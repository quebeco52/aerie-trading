<?php

declare(strict_types=1);

namespace App\Service\Corporate\Earnings;

use App\Data\Company\Sectors;
use App\Data\Company\Segments;
use App\DTO\EarningsSimulationContext;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\Industry\IndustryOutlook;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Corporate\Segment\SegmentBooks;
use App\Service\Corporate\Segment\SegmentOutlook;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MathUtility;
use App\Service\Math\ResponseCurves;
use App\Service\Math\StochasticProcesses;
use App\Service\Math\TimeSeries;

/**
 * The operating side of a report: the capacity the firm runs, the revenue it sells at the industry's price level, and
 * the cost base it carries, committed (sticky, restructured when cut) and variable (a mean-reverting ratio with an
 * overtime premium). Draws the quarter's demand shock, fundamental jump and margin shock, in that order.
 */
final class OperatingCapacityEngine
{
    // --- Capacity & Utilization ---
    /** Absolute minimum capacity utilization (10%) to prevent negative revenue on dead companies. */
    public const MIN_CAPACITY_UTILIZATION = 0.10;

    // --- Margins & Volatility ---
    /** Max quarterly asset turnover to prevent revenue hyperinflation. */
    public const MAX_QUARTERLY_ASSET_TURNOVER = 3.0;

    /** Cyclical shift coefficient for variable margins based on output gap. */
    public const CYCLICAL_MARGIN_SHIFT_COEFFICIENT = 0.15;

    /** Coefficient for margin volatility relative to baseline stock volatility. */
    public const MARGIN_VOLATILITY_COEFFICIENT = 0.15;

    /** Multiplier applied to baseline volatility to derive base idiosyncratic revenue volatility. */
    public const IDIOSYNCRATIC_REV_VOL_RATIO = 0.25;

    // --- Capacity & Seasonality Limits ---
    /** Hard ceiling on capacity utilization to bound physical operations and depreciation. */
    public const MAX_CAPACITY_UTILIZATION = 1.50;

    // --- Cost Convexity & Overtime ---
    /** Capacity utilization threshold above which convex overtime cost penalties begin. */
    public const CAPACITY_OVERTIME_THRESHOLD = 1.00;
    /** Degree of power-law convexity for operating costs when capacity exceeds 100%. */
    public const CAPACITY_OVERTIME_CONVEXITY = 1.50;
    /** Scalar scaling the convex overtime penalty applied to variable cost ratio. */
    public const CAPACITY_OVERTIME_SCALAR = 0.60;

    // --- Price Level in Capacity ---
    /** Stream-state key: the GDP deflator at the last report, so the next can measure the quarter's price growth. */
    public const STATE_LAST_DEFLATOR = 'state:last_deflator';
    /**
     * Stream-state key: log gap between the price level and the one the plant's book carries at its planned nominal
     * growth.
     */
    public const STATE_PRICE_LEVEL_SURPRISE = 'state:price_level_surprise';

    // --- Jump Diffusion (Fundamental vs Price) ---
    /** Scale factor for fundamental jump intensity relative to price jumps. */
    public const FUNDAMENTAL_JUMP_INTENSITY_SCALE = 0.25;
    /** Scale factor for fundamental jump mean size relative to price jumps. */
    public const FUNDAMENTAL_JUMP_MEAN_SCALE = 0.50;
    /** Scale factor for fundamental jump volatility relative to price jumps. */
    public const FUNDAMENTAL_JUMP_VOL_SCALE = 0.50;
    /** Price jump intensity assumed for a stock seeded without one: two jumps a year. */
    public const DEFAULT_PRICE_JUMP_INTENSITY = 2.0;
    /** Price jump size volatility assumed for a stock seeded without one: about 10% a jump. */
    public const DEFAULT_PRICE_JUMP_VOL = 0.10;

    // --- Industry Capacity & Cournot Pricing ---
    /**
     * Long-run supply elasticity of the competitive fringe (Forchheimer): fringe output ~ P^eta, so it cedes share when
     * dominant firms overbuild and refills a hole when one exits; unit elasticity is the textbook constant-cost long
     * run.
     */
    public const FRINGE_SUPPLY_ELASTICITY = 1.0;

    // --- Asymmetric Cost Stickiness (Anderson, Banker, & Janakiraman 2003) ---
    /**
     * Quarters of eliminated payroll recognised as one-time termination benefits when the committed base is cut (ASC
     * 420-10).
     */
    public const RESTRUCTURING_SEVERANCE_QUARTERS = 2.0;

    // --- Cash Cost Base ---
    /** Floor on the cash share of the structural cost base once depreciation is carved out as its own expense line. */
    public const MIN_CASH_COST_SHARE = 0.40;
    /**
     * Floor on the quarter's pricing-power multiplier where it divides a cost: a price collapse past half cannot
     * inflate costs without bound.
     */
    public const MIN_PRICING_POWER_DIVISOR = 0.5;

    // --- Equity Issuance & TAM Scaling Limits ---
    /**
     * Ceiling on structural revenue capacity, as a multiple of the firm's revenue at a full addressable share (150%).
     */
    public const MAX_SECTOR_TAM_CAPACITY_RATIO = 1.50;
    /**
     * The same ceiling for financial intermediaries, whose share is read on equity while revenue comes off the
     * leveraged book (250%).
     */
    public const MAX_FINANCIAL_SECTOR_TAM_CAPACITY_RATIO = 2.50;

    public function __construct(
        private readonly MathUtility $mathUtility,
        private readonly CorporateMetrics $corporateMetrics,
        private readonly FixedAssetLedger $plant,
        /**
         * Zero-sum industry share ledger; null (unit tests, harnesses) means every firm's gain comes from a larger
         * market.
         */
        private readonly ?IndustryShareLedger $industryShareLedger = null,
    ) {}

    public function generateCapacityAndRevenue(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        $strategy = $ctx->strategy;
        $macroState = $ctx->macroState;

        $annualTurnover = $this->resolveAnnualCapitalTurnover($ctx);
        $assetTurnover = min(self::MAX_QUARTERLY_ASSET_TURNOVER, $annualTurnover / 4.0);

        // The firm as the macro physics first reads it, kept so the drivers can be measured on it afterwards.
        $ctx->macroPhysicsBasis = clone $stock;
        $macroPhysics = $strategy->getMacroPhysics($stock, $macroState);
        $macroDemandShift = $macroPhysics['macro_demand_shift'];
        $pricingPowerMultiplier = $macroPhysics['pricing_power_multiplier'];
        // Input cost level; models that carry inflation inside their own physics leave it at the price level.
        $inputCostMultiplier = (float) ($macroPhysics['input_cost_multiplier'] ?? $pricingPowerMultiplier);

        // The industry outlook as the public reads it this quarter, filed with the report: the market the firm sizes
        // itself against below grows with it. A company in several markets reads the blend of its segments' outlooks:
        // on one plant it files how far each segment's lines lean in its plan (SegmentOutlook), and with plant by
        // segment each segment's own outlook, which its own market grows with (SegmentBooks).
        if ($this->industryShareLedger !== null) {
            $belief = $this->industryShareLedger->resolveOutlookBelief($stock);
            $ctx->industryOutlook = exp($belief);
            $filed = [IndustryOutlook::STATE_BELIEF => log($ctx->industryOutlook)];
            $segmentBeliefs = $this->industryShareLedger->resolveSegmentBeliefs($stock) ?? [];
            if (SegmentBooks::keepsBooks($stock)) {
                foreach ($segmentBeliefs as $segment => $segmentBelief) {
                    $filed[SegmentBooks::STATE_OUTLOOK . $segment] = $segmentBelief;
                }
            } else {
                foreach (SegmentOutlook::tilts($stock->getTicker(), $segmentBeliefs, $belief) as $stream => $tilt) {
                    $filed[SegmentOutlook::STATE_TILT . $stream] = $tilt;
                }
            }
            $stock->setEarningsMomentumZ($filed + ($stock->getEarningsMomentumZ() ?? []));
        }
        $this->openSegmentBooks($ctx);

        // The firm's reach into the market it sells into, on the base the saturation physics uses. The
        // industry ledger weighs both what this firm builds and what it takes from rivals by it.
        $evaluationCapital = $strategy->getEvaluationCapital((float) $stock->getTotalEquity(), $stock->getInvestedCapital());
        $ctx->addressableShare = $this->corporateMetrics->calculateAddressableShare($stock, $evaluationCapital, $macroState);
        $plant = SegmentBooks::plant($stock->getEarningsMomentumZ() ?? []);
        if ($plant !== null) {
            $markets = SegmentBooks::addressableShares(
                $stock,
                $this->corporateMetrics->calculateScaleRatio($evaluationCapital, $macroState->nominalGdpIndex, (float) $stock->getSamRatio()),
                $macroState->totalTime,
            );
            // A segment with no plant (a line the company does not run) sells nothing into its industry.
            foreach (array_filter($plant, static fn(float $share): bool => $share > 0.0) as $segment => $plantShare) {
                $ctx->segments[$segment] = ['plant' => $plantShare, 'market' => $markets[$segment] ?? 0.0, 'price' => 1.0, 'load' => 1.0, 'drain' => 0.0, 'growth' => 0.0, 'leftover' => 0.0];
            }
        }

        // Zero-sum share: rivals' idiosyncratic gains booked since this firm's last report are taken from
        // its demand in proportion to its size, scaled by how substitutable the industry's output is. The
        // drain lands in EXPECTED revenue because analysts have already read the rivals' reports.
        $rivalShareDrain = 0.0;
        if ($this->industryShareLedger !== null) {
            $rivalShareDrain = $ctx->segments === []
                ? $strategy->getIndustrySubstitutability() * $this->industryShareLedger->resolveRivalShareDrain($stock, $ctx->tickCount, $ctx->ticksPerYear)
                : $this->resolveSegmentRivalDrain($ctx, $this->industryShareLedger);
            $macroDemandShift += $rivalShareDrain;
        }
        $ctx->rivalShareDrain = $rivalShareDrain;

        // Own-price demand response: only the REAL part of the firm's price change moves volume. The
        // pricing-power multiplier carries expected inflation for the median firm, so a price setter's
        // excess pass-through loses some units and a lagging regulated tariff wins some.
        $realPriceChange = $pricingPowerMultiplier - $inputCostMultiplier;
        $ownPriceVolumeShift = -$strategy->getPriceElasticityOfDemand() * $realPriceChange;
        $macroDemandShift += $ownPriceVolumeShift;
        $ctx->ownPriceVolumeShift = $ownPriceVolumeShift;

        $revenueVol = $ctx->baselineVol * self::IDIOSYNCRATIC_REV_VOL_RATIO;
        $z1 = $this->mathUtility->generateStandardNormal();

        // A report is filed in the season after its quarter closes, so it covers the calendar quarter before
        // the one it is filed in (as ReportCalendar labels it).
        $ctx->calendarQuarter = EarningsEngine::coveredCalendarQuarter($ctx->tickCount, $ctx->ticksPerYear);
        // Seasonality follows the calendar; the fiscal quarter only shifts annual events (impairment tests).
        $ctx->fiscalQuarter = (($ctx->calendarQuarter - $strategy->getFiscalYearStartQuarter($stock)) % 4 + 4) % 4;
        $factors = $strategy->resolveSeasonalityFactors($stock);
        $ctx->seasonalFactor = $factors[$ctx->calendarQuarter] ?? 1.0;
        $ctx->priorSeasonalFactor = $factors[($ctx->calendarQuarter + 3) % 4] ?? 1.0;

        $priceJumpIntensity = (float) ($stock->getJumpIntensity() ?? self::DEFAULT_PRICE_JUMP_INTENSITY);
        $priceJumpVol = (float) ($stock->getJumpVol() ?? self::DEFAULT_PRICE_JUMP_VOL);

        $jumpIntensity = $priceJumpIntensity * self::FUNDAMENTAL_JUMP_INTENSITY_SCALE;
        $jumpVol = $priceJumpVol * self::FUNDAMENTAL_JUMP_VOL_SCALE;
        $jumpMean = -$priceJumpVol * self::FUNDAMENTAL_JUMP_MEAN_SCALE;

        $jumpData = $this->mathUtility->calculateJumpDiffusion($jumpIntensity, $jumpMean, $jumpVol, $ctx->dt);
        $jumpMagnitude = self::compensatedFundamentalJump(exp((float) ($jumpData['exponent'] ?? 0.0)), $jumpIntensity, $jumpMean, $jumpVol, $ctx->dt);

        $idiosyncraticDemandShock = $revenueVol * sqrt($ctx->dt) * $z1;

        $ctx->idiosyncraticDemandShock = $idiosyncraticDemandShock;
        $ctx->jumpMagnitude = $jumpMagnitude;
        $ctx->capacityUtilization = $this->capacityUtilizationAt($ctx, $macroDemandShift);
        // Capital tied up in construction earns nothing yet, but only so much of the base may be excluded:
        // a firm mid-megaproject still runs the plant it already has. Goodwill earns nothing either — it is
        // the premium paid over the plant — so an impairment cannot shrink capacity. A financial's base is
        // its earning assets, which never contained goodwill.
        $revenueGeneratingCapital = CorporateMetrics::revenueGeneratingCapital(
            $ctx->investedCapital,
            $stock->getTotalCipAmount(),
            $strategy->isFinancial() ? 0.0 : (float) $stock->getGoodwill(),
        );
        // Plant is booked at historical cost but sells its output at today's prices. Expected inflation already reaches
        // the book through capex planned at nominal growth, so what the book cannot see is a price level that runs
        // ahead of expectation: that surprise is carried on the plant's capacity the quarter it lands, and fades as new
        // capex restates the book at today's prices. Working capital is already at current prices, and a lender's book
        // is nominal, so neither is revalued.
        if ($stock->getGrossPpe() !== null) {
            $revenueGeneratingCapital += max(0.0, $stock->getNetPpe()) * (exp($this->resolvePriceLevelSurprise($ctx)) - 1.0);
        }

        $ctx->revenueGeneratingCapital = $revenueGeneratingCapital;
        $ctx->assetTurnover = $assetTurnover;
        [$ctx->structuralRevenue, $ctx->expectedRevenue] = $this->capacityRevenueAt($ctx, $pricingPowerMultiplier, $ctx->capacityUtilization);
        // What the quarter would have run at and sold without its own demand shock and jump: the base analysts forecast
        // from.
        $ctx->capacityUtilizationBeforeShocks = $this->capacityUtilizationAt($ctx, $macroDemandShift, false);
        [, $ctx->expectedRevenueBeforeShocks] = $this->capacityRevenueAt($ctx, $pricingPowerMultiplier, $ctx->capacityUtilizationBeforeShocks);
        // A model that moves its cycle through an order book or a fleet keeps it out of the engine's utilisation, so
        // the plant's load puts it back for everything that reads the load: the committed base, overtime, inventory
        // marks, trade terms and units-of-production depreciation. Read before the physics rolls the book on.
        $ctx->sectorActivityFactor = max(0.0, 1.0 + $strategy->resolveSectorActivityShift($stock, $ctx->macroState));

        $balancedStructuralRevenue = $ctx->structuralRevenue;
        $fixedCostRatio = (float) $stock->getFixedCostRatio();
        // The cost base inflates with INPUT prices (expected inflation), not with the firm's own selling
        // price: a price setter's excess pass-through reaches its margin, a price taker's shortfall squeezes
        // it. Scaling costs by the pricing-power multiplier gave every firm a fixed margin whatever it charged.
        // A lender's cost base is struck on its book, not on its gross interest income: branches and staff do not
        // cost more because the policy rate rose and the loans reprice (FDIC: noninterest expense ~2.6% of assets
        // through rate cycles).
        $costToEarningAssets = $strategy->getOperatingCostToEarningAssets($stock);
        $structuralCosts = $costToEarningAssets !== null
            ? $ctx->revenueGeneratingCapital * $costToEarningAssets / EarningsEngine::TTM_QUARTERS * ($inputCostMultiplier / max(self::MIN_PRICING_POWER_DIVISOR, $pricingPowerMultiplier))
            : $balancedStructuralRevenue * ($inputCostMultiplier / max(self::MIN_PRICING_POWER_DIVISOR, $pricingPowerMultiplier)) * (1.0 - $ctx->stableMargin);

        // Carve structural depreciation out of cash operating costs so EBITDA sits above depreciation and EBIT below.
        $ctx->structuralDepreciation = $this->plant->resolveStructuralDepreciation($ctx);
        $cashStructuralCosts = max(
            $structuralCosts * self::MIN_CASH_COST_SHARE,
            $structuralCosts - $ctx->structuralDepreciation,
        );

        // Payroll in the fixed base costs the real wage's gap to trend productivity: a LEVEL, so a real wage that
        // stays high keeps costing until the labour market corrects it (downward rigidity lives in the wage equation).
        $wageInflationFactor = 1.0 + ($strategy->getLaborCostShare() * $macroState->realWageGap / max(self::MIN_PRICING_POWER_DIVISOR, $pricingPowerMultiplier));
        // Capacity cost is committed, not chosen quarter by quarter: a firm carries the base it was
        // resourced for until it restructures, which is why the structural figure is what gets scaled.
        // Rules that cost the firm productivity raise it per unit of capacity as they raise its unit costs.
        $structuralFixedCosts = $cashStructuralCosts * $fixedCostRatio * $wageInflationFactor * $strategy->getFixedCostFactor($macroState);
        $structuralVariableCosts = $cashStructuralCosts - ($cashStructuralCosts * $fixedCostRatio);

        // Industry capacity balance: the industry's plant against trend demand sets the price this firm realizes and,
        // in a glut deep enough to reach the lowest price it sells at, how much of its plant runs. Idling a unit saves
        // its variable cost and, once the committed base is restructured, the part of it above the floor.
        [$ctx->industryPriceLevel, $ctx->plantLoad] = $this->resolveIndustryBalance(
            $ctx,
            ($structuralVariableCosts + ((1.0 - FinancialConstants::MIN_COMMITTED_COST_SCALE) * $structuralFixedCosts)) / $balancedStructuralRevenue,
        );
        $ctx->structuralRevenue *= $ctx->industryPriceLevel;
        $ctx->expectedRevenue *= $ctx->industryPriceLevel * $ctx->plantLoad;
        $ctx->expectedRevenueBeforeShocks *= $ctx->industryPriceLevel * $ctx->plantLoad;

        // A firm staffs, rents and stocks up for its peak and lets the extra go after it: the seasonal share of the
        // base follows the season, the rest (rent, depreciation, head office) carries evenly through the year.
        $seasonalShare = $strategy->getSeasonalFixedCostShare();
        $ctx->seasonalFixedCostFactor = 1.0 - $seasonalShare + ($seasonalShare * $ctx->seasonalFactor);
        $ctx->fixedCosts = $structuralFixedCosts * $this->resolveCommittedCostScale($ctx) * $ctx->seasonalFixedCostFactor;

        $ctx->baselineVariableMargin = $structuralVariableCosts / $ctx->structuralRevenue;
    }

    /**
     * The log gap between today's price level and the one the plant's book carries at its planned nominal growth:
     * each quarter's growth in the deflator beyond expected inflation is added, and the gap closes at the rate new
     * capex restates the book, (g + pi + d) a year, the share of a steady-state book replaced at current prices. A
     * firm that has never reported starts with no gap. Persisted on the stream map.
     */
    private function resolvePriceLevelSurprise(EarningsSimulationContext $ctx): float
    {
        $state = $ctx->stock->getEarningsMomentumZ() ?? [];
        $deflator = max(0.01, $ctx->macroState->gdpDeflator);
        $lastDeflator = (float) ($state[self::STATE_LAST_DEFLATOR] ?? $deflator);
        $expectedInflation = max(0.0, $ctx->macroState->inflationEma);
        $restatement = (max(0.0, SegmentBooks::fadedSecularGrowth($ctx->strategy, $ctx->stock, $ctx->macroState->totalTime)) + $expectedInflation + $this->plant->resolveDepreciationRate($ctx)) * $ctx->dt;

        $quarterSurprise = isset($state[self::STATE_LAST_DEFLATOR])
            ? log($deflator / max(0.01, $lastDeflator)) - ($expectedInflation * $ctx->dt)
            : 0.0;
        $ctx->priceLevelSurprise = ((float) ($state[self::STATE_PRICE_LEVEL_SURPRISE] ?? 0.0)) * (1.0 - min(1.0, $restatement)) + $quarterSurprise;

        return $ctx->priceLevelSurprise;
    }

    /**
     * The quarter's fundamental jump as a share of demand, Merton-compensated (Merton 1976): the jump's e^J - 1
     * less its expected value lambda x E[e^J - 1] x dt, so a lost contract or a recall moves one quarter's
     * revenue and not the firm's revenue on average.
     */
    public static function compensatedFundamentalJump(float $jumpMultiplier, float $intensity, float $jumpMean, float $jumpVol, float $dt): float
    {
        return ($jumpMultiplier - 1.0) - ($intensity * StochasticProcesses::lognormalJumpCompensator($jumpMean, $jumpVol) * $dt);
    }

    /**
     * Capacity utilisation the quarter runs at under a given macro demand shift: the seasonal run rate lifted by
     * demand and the quarter's own shocks, within the plant's physical bounds. Trend growth is not here: it is a rate,
     * and it reaches revenue as the plant it builds (CapitalBudgetEngine::resolveExpansionBudget()).
     */
    public function capacityUtilizationAt(EarningsSimulationContext $ctx, float $macroDemandShift, bool $withOwnShocks = true): float
    {
        $ownShocks = $withOwnShocks ? $ctx->idiosyncraticDemandShock + $ctx->jumpMagnitude : 0.0;
        $rawUtilization = $ctx->seasonalFactor * (1.0 + $macroDemandShift + $ownShocks);

        return max(self::MIN_CAPACITY_UTILIZATION, min(self::MAX_CAPACITY_UTILIZATION, $rawUtilization));
    }

    /**
     * Structural and expected revenue at a given price level and utilisation, before the industry's price level:
     * the firm's capital turned over at that price, capped at its addressable market, and run at that utilisation.
     *
     * @return array{0: float, 1: float} Structural revenue, expected revenue.
     */
    public function capacityRevenueAt(EarningsSimulationContext $ctx, float $pricingPowerMultiplier, float $capacityUtilization): array
    {
        $structuralRevenue = max(1.0, $ctx->revenueGeneratingCapital * $ctx->assetTurnover * $pricingPowerMultiplier
            * $ctx->strategy->getStructuralRevenueMultiplier($ctx->stock));

        // The firm's addressable market in the unit its capacity is measured in: its own structural revenue
        // scaled up to a full addressable share, on the base every share reading uses (equity for a lender,
        // whose book turns over a leveraged multiple of it; invested capital otherwise).
        $fullMarketRevenue = $ctx->addressableShare > 0.0 ? $structuralRevenue / $ctx->addressableShare : INF;
        $maxCapacity = $fullMarketRevenue * ($ctx->strategy->isFinancial()
            ? self::MAX_FINANCIAL_SECTOR_TAM_CAPACITY_RATIO
            : self::MAX_SECTOR_TAM_CAPACITY_RATIO);

        $cappedStructuralRevenue = min($maxCapacity, $structuralRevenue);

        // Utilisation is held within the plant's bounds, so the market cap on structural revenue bounds the quarter
        // too.
        return [$cappedStructuralRevenue, $cappedStructuralRevenue * $capacityUtilization];
    }

    /**
     * The committed cost base the firm is actually resourced for, as a fraction of the base its structural
     * capacity implies.
     *
     * Anderson, Banker & Janakiraman (2003) measure what happens to operating costs when activity moves:
     * they follow it up at one elasticity and down at a lower one, because a firm cannot unstaff a plant
     * the quarter demand falls and does not re-staff one the quarter it returns. Written as a partial
     * adjustment of the base toward the activity it is being asked to carry, their coefficients are ANNUAL
     * (the base closes 36% of a fall in a year), so each quarter closes the share that compounds to that:
     * one bad quarter barely trims the base, and a slump that persists restructures it over several years.
     * This is the only stickiness in the cost structure; variable cost is strictly per unit. The ceiling at
     * one keeps the boom side out of this channel: running above capacity is already paid for as the convex
     * overtime premium on the variable margin.
     */
    private function resolveCommittedCostScale(EarningsSimulationContext $ctx): float
    {
        // A row seeded before the column existed is carrying its full structural base.
        $prior = (float) $ctx->stock->getCommittedCostScale();
        if ($prior <= 0.0) {
            $prior = 1.0;
        }

        // The activity the base has to carry, with the seasonal swing the firm staffs through taken out, the volume a
        // model moves inside its own sector physics put back in, and plant idled in an industry glut left out.
        $utilization = ($ctx->capacityUtilization / max(0.01, $ctx->seasonalFactor)) * $ctx->sectorActivityFactor * $ctx->plantLoad;
        $activityGap = log(
            max(self::MIN_CAPACITY_UTILIZATION, $utilization) / max(self::MIN_CAPACITY_UTILIZATION, $prior),
        );

        $annualElasticity = FinancialConstants::STICKY_COST_BETA_EXPANSION
            + ($activityGap < 0.0 ? FinancialConstants::STICKY_COST_BETA_CONTRACTION_PENALTY : 0.0);
        $speed = TimeSeries::periodAdjustmentSpeed($annualElasticity, $ctx->dt);

        $scale = min(
            1.0,
            max(FinancialConstants::MIN_COMMITTED_COST_SCALE, $prior * exp($speed * $activityGap)),
        );

        $ctx->committedCostScale = $scale;
        $ctx->committedCostCut = max(0.0, $prior - $scale);
        $ctx->stock->setCommittedCostScale((string) $scale);

        return $scale;
    }

    /**
     * The price level this firm realizes and the share of its plant that runs, given how much plant its industry has
     * installed against trend demand. The industry price falls along the fringe-adjusted curve until it reaches the
     * lowest price the firm sells into the shared market at: its avoidable cost over its marginal revenue, where
     * another unit no longer pays for what idling it saves, and never below the deepest cut the balance makes. Past it
     * the price holds and the plant buyers do not take stands idle (capacity-constrained Cournot, Kreps & Scheinkman
     * 1983). Price and load reach the firm by how substitutable its output is; the rest sells in its own niche. One and
     * one for a balanced industry, a financial, or output declared non-substitutable.
     *
     * @param float $avoidableCostRatio Cost idling a unit of plant saves, over its revenue at the balanced price.
     * @return array{0: float, 1: float} Price level, plant load.
     */
    private function resolveIndustryBalance(EarningsSimulationContext $ctx, float $avoidableCostRatio): array
    {
        if ($ctx->segments !== []) {
            return $this->resolveSegmentBalances($ctx, $avoidableCostRatio);
        }

        $substitutability = $ctx->strategy->getIndustrySubstitutability();
        if ($this->industryShareLedger === null || $ctx->strategy->isFinancial() || $substitutability <= 0.0) {
            return [1.0, 1.0];
        }

        // The roster is not the industry: the firm's build weighs on the industry price by its share of the
        // market it sells into, the same share the saturation penalty is struck on. Supply is struck against the
        // industry's true demand, which the plan only knows through the public outlook.
        $supply = $this->industryShareLedger->resolveIndustrySupply(
            $ctx->stock,
            $ctx->structuralRevenue * EarningsEngine::TTM_QUARTERS,
            $ctx->addressableShare,
            IndustryShareLedger::trendNominalGdp($ctx->macroState),
            $ctx->macroState->totalTime,
            IndustryShareLedger::secularExcessGrowth($ctx->strategy, $ctx->stock),
            $ctx->tickCount,
            $ctx->ticksPerYear,
        );

        return $supply === null ? [1.0, 1.0] : self::clearAt($supply, $ctx->addressableShare, $substitutability, $avoidableCostRatio);
    }

    /**
     * resolveIndustryBalance() for a company that keeps plant by segment (SegmentBooks): each segment's plant is struck
     * against its own industry, at its own share of that market and its industry's substitutability, and sells at what
     * that industry pays; the company's price and load are its segments' over the plant that runs. Each segment's share
     * of the quarter's revenue is filed for the plan to split its lines by.
     *
     * @return array{0: float, 1: float} Price level, plant load.
     */
    private function resolveSegmentBalances(EarningsSimulationContext $ctx, float $avoidableCostRatio): array
    {
        $industries = SegmentBooks::industries($ctx->stock);
        $prices = [];
        $loads = [];
        foreach ($ctx->segments as $segment => $books) {
            $strategy = $segment === Segments::MAIN ? $ctx->strategy : Sectors::strategyFor($industries[$segment] ?? '');
            $substitutability = $strategy->getIndustrySubstitutability();
            $supply = $this->industryShareLedger === null || $strategy->isFinancial() || $substitutability <= 0.0 ? null : $this->industryShareLedger->resolveSegmentSupply(
                $ctx->stock,
                $segment,
                $books['plant'] * (float) $ctx->stock->getTotalRevenue(),
                $books['plant'] * $ctx->structuralRevenue * EarningsEngine::TTM_QUARTERS,
                $books['market'],
                IndustryShareLedger::trendNominalGdp($ctx->macroState),
                $ctx->macroState->totalTime,
                IndustryShareLedger::secularExcessGrowth($strategy, $ctx->stock),
                $ctx->tickCount,
                $ctx->ticksPerYear,
            );
            [$prices[$segment], $loads[$segment]] = $supply === null ? [1.0, 1.0] : self::clearAt($supply, $books['market'], $substitutability, $avoidableCostRatio);
            $ctx->segments[$segment]['price'] = $prices[$segment];
            $ctx->segments[$segment]['load'] = $loads[$segment];
        }

        // A by-product's plant runs with the main segment's whether or not its own industry buys all its output.
        $running = [];
        foreach (SegmentBooks::jointSegments($ctx->stock) as $segment) {
            $running[$segment] = $loads[Segments::MAIN] ?? 1.0;
        }
        $company = SegmentBooks::aggregate(
            array_map(static fn(array $books): float => $books['plant'], $ctx->segments),
            $prices,
            $loads,
            array_map(static fn(array $books): float => $books['drain'], $ctx->segments),
            $running,
        );
        $filed = [];
        foreach ($company['targets'] as $segment => $target) {
            $filed[SegmentBooks::STATE_TARGET . $segment] = $target;
        }
        $ctx->stock->setEarningsMomentumZ($filed + ($ctx->stock->getEarningsMomentumZ() ?? []));

        return [$company['price'], $company['load']];
    }

    /**
     * The drain on a company that keeps plant by segment: each segment loses to its own industry's rivals what their
     * gains take from it, by that industry's substitutability, weighed by its plant. Each segment's own drain is kept
     * so its lines alone carry it (resolveSegmentBalances()).
     */
    private function resolveSegmentRivalDrain(EarningsSimulationContext $ctx, IndustryShareLedger $ledger): float
    {
        $industries = SegmentBooks::industries($ctx->stock);
        $drain = 0.0;
        foreach ($ctx->segments as $segment => $books) {
            $strategy = $segment === Segments::MAIN ? $ctx->strategy : Sectors::strategyFor($industries[$segment] ?? '');
            $books['drain'] = $strategy->getIndustrySubstitutability()
                * $ledger->resolveSegmentRivalDrain($ctx->stock, $segment, $books['plant'] * (float) $ctx->stock->getTotalRevenue(), $ctx->tickCount, $ctx->ticksPerYear);
            $ctx->segments[$segment] = $books;
            $drain += $books['plant'] * $books['drain'];
        }

        return $drain;
    }

    /**
     * Opens the plant books of a company in several markets at the first report that finds its segments' revenue shares
     * filed: each segment takes its share of the plant and a market of its own, and the industry ledger moves the other
     * segments out of the main record. The lean the plan carried on one plant goes.
     */
    private function openSegmentBooks(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        $macroState = $ctx->macroState;
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        if (SegmentOutlook::filedShares($momentum) === [] || SegmentBooks::plant($momentum) !== null || !SegmentBooks::keepsBooks($stock)) {
            return;
        }

        // Each other segment enters at its share of the market its industry's firms sell into (SegmentBooks::
        // entryMarketShare()); the main segment keeps the company's share of its own.
        $plant = SegmentBooks::openingPlantShares($stock->getTicker(), $momentum);
        $firmShare = $this->corporateMetrics->calculateAddressableShare($stock, $ctx->strategy->getEvaluationCapital((float) $stock->getTotalEquity(), $stock->getInvestedCapital()), $macroState);
        $marketShares = [];
        foreach (SegmentBooks::industries($stock) as $segment => $industry) {
            $market = $segment === Segments::MAIN ? null : $this->industryShareLedger?->resolveIndustryMarketRevenue(
                $industry,
                $stock->getTicker(),
                $macroState,
                IndustryShareLedger::secularExcessGrowth(Sectors::strategyFor($industry), $stock),
                $ctx->tickCount,
                $ctx->ticksPerYear,
            );
            if ($market !== null) {
                $marketShares[$segment] = SegmentBooks::entryMarketShare(($plant[$segment] ?? 0.0) * (float) $stock->getTotalRevenue(), $market, $firmShare);
            }
        }

        $opened = SegmentBooks::open(
            $plant,
            CorporateMetrics::secularMarketGrowth($stock, $macroState->totalTime),
            SegmentBooks::marketGrowths($stock, $macroState->totalTime),
            $firmShare,
            $marketShares,
        );
        foreach (array_keys($momentum) as $key) {
            if (str_starts_with((string) $key, SegmentOutlook::STATE_TILT)) {
                unset($momentum[$key]);
            }
        }
        $stock->setEarningsMomentumZ($opened + $momentum);
        $this->industryShareLedger?->openSegmentBooks($stock, $plant[Segments::MAIN] ?? 0.0);
    }

    /**
     * The price a firm realizes and the share of its plant that runs in a market of this supply, at its share of it.
     *
     * @param array{roster: float, fringe: float} $supply
     * @return array{0: float, 1: float} Price level, plant load.
     */
    private static function clearAt(array $supply, float $addressableShare, float $substitutability, float $avoidableCostRatio): array
    {
        $marginalRevenue = FirmEconomics::calculateCournotMarginalRevenueFactor(
            $addressableShare,
            FirmEconomics::fringeAdjustedDemandElasticity(
                FinancialConstants::COURNOT_DEMAND_ELASTICITY,
                self::FRINGE_SUPPLY_ELASTICITY,
                1.0 - ($supply['fringe'] / max(1e-9, $supply['roster'] + $supply['fringe'])),
            ),
            $substitutability,
        );
        $withholdingPrice = $marginalRevenue > 0.0 ? min(1.0, $avoidableCostRatio / $marginalRevenue) : 1.0;

        // The roster is priced as dominant firms against a competitive fringe that answers the price: an
        // overbuild is partly absorbed by fringe exit, a hole left by a failed firm partly refilled.
        $clearing = FirmEconomics::calculateIndustryClearing(
            $supply['roster'],
            $supply['fringe'],
            FinancialConstants::COURNOT_DEMAND_ELASTICITY,
            self::FRINGE_SUPPLY_ELASTICITY,
            max(1.0 - FinancialConstants::MAX_INDUSTRY_PRICE_RESPONSE, $withholdingPrice),
        );
        $firmResponse = $substitutability * ($clearing['price'] - 1.0);

        return [
            1.0 + max(-FinancialConstants::MAX_INDUSTRY_PRICE_RESPONSE, min(FinancialConstants::MAX_INDUSTRY_PRICE_RESPONSE, $firmResponse)),
            1.0 - ($substitutability * (1.0 - $clearing['load'])),
        ];
    }

    /**
     * Annual capital turnover (revenue / invested capital), the DuPont component that fixes how much revenue a
     * dollar of capital can generate: ROIC = after-tax margin x turnover.
     *
     * For physical businesses turnover is a technology parameter, so it is seeded once from the identity using the
     * structural baseline ROIC and margin, persisted on the stock, and then held. Deriving it every quarter from
     * the trailing-return blend would let a margin squeeze cut revenue capacity, a feedback loop with no
     * physical counterpart (a plant does not shrink because last quarter was unprofitable). Growth in capacity
     * flows only through invested capital (Damodaran: g = reinvestment x ROIC) and the sector TAM cap.
     *
     * Financial intermediaries keep the dynamic derivation: their target return carries net interest margin and
     * cost-of-funds physics that genuinely move the yield on earning assets.
     */
    public function resolveAnnualCapitalTurnover(EarningsSimulationContext $ctx): float
    {
        if ($ctx->strategy->isFinancial()) {
            // The book turns over at the yield it earns: no floor, or a lender whose yield fell in an easing cycle
            // booked revenue its book never earned.
            return FirmEconomics::structuralAssetTurnover($ctx->baselineRoic, $ctx->stableMargin, $ctx->corporateTaxRate);
        }

        $stored = $ctx->stock->getAssetTurnover();
        if ($stored !== null && (float) $stored > 0.0) {
            return (float) $stored;
        }

        $structuralTurnover = FirmEconomics::structuralAssetTurnover(max(0.01, (float) $ctx->stock->getBaselineRoic()), $ctx->stableMargin, $ctx->corporateTaxRate);
        $ctx->stock->setAssetTurnover((string) $structuralTurnover);

        return $structuralTurnover;
    }

    public function processVariableMargins(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        $strategy = $ctx->strategy;

        $kappa = $strategy->getMarginReversionSpeed();
        $outputGap = $ctx->macroState->outputGapEma;
        $cyclicality = $strategy->getOperatingCyclicality($stock);

        $cyclicalMarginShift = $outputGap * $cyclicality * self::CYCLICAL_MARGIN_SHIFT_COEFFICIENT;
        $dynamicVariableTheta = min(0.99, max(0.01, $ctx->baselineVariableMargin - $cyclicalMarginShift));

        $z2 = $this->mathUtility->generateStandardNormal();
        $marginVol = $ctx->baselineVol * self::MARGIN_VOLATILITY_COEFFICIENT * $strategy->getVariableCostNoiseShare();

        if ($stock->getStructuralVariableMargin() !== null) {
            $currentVariableMargin = max(0.01, min(0.99, (float) $stock->getStructuralVariableMargin()));
        } else {
            // With no prior state the process starts at its own long-run mean, which is the structural
            // variable cost ratio computed above, which carries the depreciation carve-out.
            $currentVariableMargin = max(0.01, min(0.99, $ctx->baselineVariableMargin));
        }

        $realizedVariableMargin = StochasticProcesses::calculateCIR($currentVariableMargin, $kappa, $dynamicVariableTheta, $marginVol, $ctx->dt, $z2);
        // What the ratio was expected to run at: the same step without its draw, at the output the quarter was
        // expected to sell.
        $ctx->publicVariableMargin = min(0.99, max(0.01, StochasticProcesses::calculateCIR($currentVariableMargin, $kappa, $dynamicVariableTheta, $marginVol, $ctx->dt, 0.0)
            + self::overtimePremium($ctx->capacityUtilizationBeforeShocks * $ctx->sectorActivityFactor * $ctx->plantLoad, $ctx->seasonalFactor)));

        $stock->setStructuralVariableMargin($realizedVariableMargin);

        // Variable cost is per unit: cost stickiness (Anderson, Banker & Janakiraman 2003) lives in the committed
        // base alone (resolveCommittedCostScale), so a fall in volume is not charged as sticky twice.
        $ctx->realizedVariableMargin = min(0.99, max(0.01, $realizedVariableMargin + self::overtimePremium($ctx->capacityUtilization * $ctx->sectorActivityFactor * $ctx->plantLoad, $ctx->seasonalFactor)));
    }

    /**
     * Overtime cost: the convex penalty on deseasonalized capacity utilization above the overtime threshold. A firm
     * keeps capacity for its calendar's own peak, so a retailer at 1.35x in its holiday quarter is at structural
     * demand and pays nothing.
     */
    public static function overtimePremium(float $capacityUtilization, float $seasonalFactor): float
    {
        return ResponseCurves::calculateConvexPenalty(
            ($capacityUtilization / max(0.01, $seasonalFactor)) - self::CAPACITY_OVERTIME_THRESHOLD,
            self::CAPACITY_OVERTIME_CONVEXITY,
            self::CAPACITY_OVERTIME_SCALAR,
        );
    }

    /**
     * One-time termination benefits for the capacity just cut (ASC 420-10), recognised in the quarter the
     * plan is committed rather than spread over the savings it buys. Only the labour share carries
     * severance — exiting a lease or mothballing a line does not — and no analyst forecasts the charge, so
     * expectedEbit is deliberately left alone and a restructuring quarter reports as a miss. That cost is
     * the reason a firm does not resize on the first soft quarter.
     */
    public function applyRestructuringCharge(EarningsSimulationContext $ctx): void
    {
        if ($ctx->committedCostCut <= 0.0 || $ctx->committedCostScale <= 0.0) {
            return;
        }

        $structuralFixedCosts = $ctx->fixedCosts / ($ctx->committedCostScale * $ctx->seasonalFixedCostFactor);
        $charge = $ctx->committedCostCut
            * $structuralFixedCosts
            * $ctx->strategy->getLaborCostShare()
            * self::RESTRUCTURING_SEVERANCE_QUARTERS;

        if ($charge <= 0.0) {
            return;
        }

        // Severance sits in operating expense, so it reduces EBITDA and EBIT alike.
        $ctx->ebitda -= $charge;
        $ctx->ebit -= $charge;
        $ctx->restructuringCharge += $charge;
    }
}
