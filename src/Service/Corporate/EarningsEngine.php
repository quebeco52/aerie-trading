<?php

declare(strict_types=1);

namespace App\Service\Corporate;

use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;
use App\Service\Event\NarrativeEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Market\MarketConsensusEngine;
use App\DTO\EarningsSimulationContext;
use App\Service\Event\EarningsReportedEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Handles the simulation of quarterly earnings reports.
 * Models revenue, operating leverage, analyst consensus, and corporate saturation.
 */
class EarningsEngine
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

    /** Failsafe max expected EBIT loss relative to structural revenue. */
    public const MAX_EBIT_LOSS_RATIO = 0.50;

    // --- Valuations & Bounds ---
    /** Minimum valuation premium relative to market PE (floor). */
    public const VALUATION_PREMIUM_MIN = 0.5;

    /** Maximum valuation premium relative to market PE (ceiling). */
    public const VALUATION_PREMIUM_MAX = 3.0;

    /** Fallback surprise percentage when expected EPS is zero. */
    public const ZERO_BASE_SURPRISE_PCT = 0.10;

    /** Default time step in years for quarterly reports. */
    public const QUARTERLY_TIME_STEP = 0.25;

    /** Multiplier applied to baseline volatility to derive base idiosyncratic revenue volatility. */
    public const IDIOSYNCRATIC_REV_VOL_RATIO = 0.25;

    // --- Capacity & Seasonality Limits ---
    /** Hard ceiling on capacity utilization to bound physical operations and depreciation. */
    public const MAX_CAPACITY_UTILIZATION = 1.50;
    /** TAM headroom multiplier allowing seasonal/cyclical volume surges above structural capacity. */
    public const EXPECTED_REVENUE_TAM_HEADROOM = 1.50;

    // --- Cost Convexity & Overtime ---
    /** Capacity utilization threshold above which convex overtime cost penalties begin. */
    public const CAPACITY_OVERTIME_THRESHOLD = 1.00;
    /** Degree of power-law convexity for operating costs when capacity exceeds 100%. */
    public const CAPACITY_OVERTIME_CONVEXITY = 1.50;
    /** Scalar scaling the convex overtime penalty applied to variable cost ratio. */
    public const CAPACITY_OVERTIME_SCALAR = 0.60;

    // --- Earnings Reporting Season ---
    /** Fraction of quarter that passes before the earnings reporting season opens (~14 ticks). */
    public const EARNINGS_REPORTING_LAG_RATIO = 0.22;
    /** Duration of the clustered earnings reporting window as a fraction of the quarter (~25 ticks). */
    public const EARNINGS_SEASON_LENGTH_RATIO = 0.40;

    // --- Fiscal Calendar ---
    /** Fiscal quarter index at which the annual goodwill impairment test runs (fiscal Q4). */
    public const FISCAL_YEAR_END_QUARTER = 3;

    // --- SUE Dispersion ---
    /** Minimum analyst estimate dispersion floor to avoid division by near-zero in SUE. */
    public const MIN_ESTIMATE_DISPERSION = 0.02;
    /** Quarters of past surprises retained as the sample the SUE denominator is estimated from (Foster, Olsen & Shevlin 1984). */
    public const SUE_HISTORY_QUARTERS = 8;
    /** Reports required before the firm's own surprise history replaces the sector's analyst dispersion in the SUE denominator. */
    public const SUE_MIN_HISTORY_QUARTERS = 4;

    // --- Trailing Twelve Month Earnings ---
    /** Number of reported quarters summed into the trailing twelve month earnings figure. */
    public const TTM_QUARTERS = 4;
    /** Absolute clamp on stored trailing net income, matching the guard on the EPS bridge and the DECIMAL(20,4) column. */
    public const MAX_ABSOLUTE_NET_INCOME = 999999999999999.0;

    /**
     * Constructor.
     *
     * @param MarketEventPublisher $marketEvent Publisher for all market events, news headlines, and shocks.
     * @param MathUtility $mathUtility Utility for advanced mathematical operations (e.g., generating standard normal distribution).
     */
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private MarketEventPublisher $marketEvent,
        private CapitalAllocationEngine $capitalAllocationEngine,
        private DebtEngine $debtEngine,
        private CapExEngine $capExEngine,
        private MathUtility $mathUtility,
        private CorporateMetrics $corporateMetrics,
        private NarrativeEngine $narrativeEngine,
        private MarketConsensusEngine $marketConsensusEngine
    ) {}

    public function calculate(Stock $stock, \App\DTO\MacroStateDTO $macroState, int $tickCount = 0, int $ticksPerYear = 252): ?array
    {
        if ($stock->isBankrupt() || !$this->checkReportingEligibility($stock, $tickCount, $ticksPerYear)) {
            return null;
        }

        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);

        $ctx = new EarningsSimulationContext(
            $stock,
            $macroState,
            $strategy,
            $businessModel,
            self::QUARTERLY_TIME_STEP
        );
        $ctx->tickCount = $tickCount;
        $ctx->ticksPerYear = $ticksPerYear;

        $this->initializeContext($ctx);
        // Assets finished this quarter leave construction in progress; the ledger roll-forward below moves
        // them into gross PP&E, where they start earning revenue and start depreciating.
        $ctx->completedCip = $this->capExEngine->processCipQueue($ctx->stock);
        $this->generateCapacityAndRevenue($ctx);
        $this->processVariableMargins($ctx);
        $this->calculateDepreciation($ctx);
        $this->calculateExpectedVsActualFinancials($ctx);
        $this->applyWorkingCapitalCharges($ctx);
        $this->calculateInterestAndRunRates($ctx);
        $this->reconcileTaxesAndNetIncome($ctx);
        $this->calculateEPSAndSurprise($ctx);
        $this->calculateFreeCashFlow($ctx);
        $this->executePriceAndVolatilityShocks($ctx);

        return $this->publishEventAndReport($ctx);
    }

    /**
     * Resolves the deterministic quarter tick on which a ticker reports earnings.
     * Clustered inside the post-quarter reporting season window: [lag, lag + season).
     */
    public static function resolveReportingTick(string $ticker, int $ticksPerYear): int
    {
        $ticksPerQuarter = max(1, (int) ($ticksPerYear / 4));
        $lagTicks    = (int) round($ticksPerQuarter * self::EARNINGS_REPORTING_LAG_RATIO);
        $seasonTicks = max(1, (int) round($ticksPerQuarter * self::EARNINGS_SEASON_LENGTH_RATIO));

        return $lagTicks + (abs(crc32($ticker)) % $seasonTicks);
    }

    private function checkReportingEligibility(Stock $stock, int $tickCount, int $ticksPerYear): bool
    {
        $ticksPerQuarter = max(1, (int) ($ticksPerYear / 4));
        $currentQuarterTick = $tickCount % $ticksPerQuarter;
        $reportingTick = self::resolveReportingTick($stock->getTicker(), $ticksPerYear);

        return $currentQuarterTick === $reportingTick;
    }

    private function initializeContext(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        $ctx->baselineVol = (float) $stock->getVolatility();
        $ctx->sharesOutstanding = (float) $stock->getSharesOutstanding();
        $ctx->stableMargin = max(0.01, (float) $stock->getOperatingMargin());
        $ctx->previousQuarterlyRevenue = (float) $stock->getPreviousRevenue() / 4.0;

        $targetMetrics = $ctx->strategy->getTargetMetrics($stock, $ctx->macroState, $this->mathUtility);
        $ctx->investedCapital = $targetMetrics['invested_capital'];
        $ctx->baselineRoic = $targetMetrics['baseline_roic'];

        $macroTaxRate = $ctx->macroState->corporateTaxRate;
        $ctx->corporateTaxRate = $ctx->strategy->getEffectiveTaxRate($macroTaxRate);

        $this->seedFixedAssetLedgerIfNeeded($ctx);
    }

    /**
     * Opens a fixed-asset ledger for a firm that has never reported. Existing databases therefore heal
     * themselves on the next earnings report instead of needing a backfill, the same way the structural
     * asset turnover seeds itself. Financial models keep depreciating their capital proxy and never open
     * a plant ledger.
     */
    private function seedFixedAssetLedgerIfNeeded(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        if ($stock->getGrossPpe() !== null || $ctx->strategy->isFinancial()) {
            return;
        }

        // Working capital is normally persisted by the first cash-flow pass; before that, estimate it the
        // same way that pass will, so the seed is not thrown off by a missing balance.
        $netWorkingCapital = $stock->getNetWorkingCapital() !== null
            ? (float) $stock->getNetWorkingCapital()
            : $ctx->strategy->getWorkingCapitalIntensity($stock) * (float) $stock->getTotalRevenue();

        $this->corporateMetrics->seedFixedAssetLedger(
            $stock,
            $ctx->investedCapital,
            $netWorkingCapital,
            (float) $stock->getGoodwill(),
            $stock->getTotalCipAmount()
        );
    }

    private function generateCapacityAndRevenue(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        $strategy = $ctx->strategy;
        $macroState = $ctx->macroState;

        $annualTurnover = $this->resolveAnnualCapitalTurnover($ctx);
        $assetTurnover = min(self::MAX_QUARTERLY_ASSET_TURNOVER, $annualTurnover / 4.0);

        $macroPhysics = $strategy->getMacroPhysics($stock, $macroState);
        $macroDemandShift = $macroPhysics['macro_demand_shift'];
        $pricingPowerMultiplier = $macroPhysics['pricing_power_multiplier'];

        $revenueVol = $ctx->baselineVol * self::IDIOSYNCRATIC_REV_VOL_RATIO;
        $z1 = $this->mathUtility->generateStandardNormal();

        $ticksPerQuarter = max(1, (int) ($ctx->ticksPerYear / 4));
        $ctx->calendarQuarter = intdiv($ctx->tickCount, $ticksPerQuarter) % 4;
        // Seasonality follows the calendar; the fiscal quarter only shifts annual events (impairment tests).
        $ctx->fiscalQuarter = (($ctx->calendarQuarter - $strategy->getFiscalYearStartQuarter($stock)) % 4 + 4) % 4;
        $factors = $strategy->getSeasonalityFactors();
        $ctx->seasonalFactor = $factors[$ctx->calendarQuarter] ?? 1.0;
        $ctx->priorSeasonalFactor = $factors[($ctx->calendarQuarter + 3) % 4] ?? 1.0;

        $priceJumpIntensity = (float) ($stock->getJumpIntensity() ?? 2.00);
        $priceJumpVol = (float) ($stock->getJumpVol() ?? 0.10);

        $jumpIntensity = $priceJumpIntensity * FinancialConstants::FUNDAMENTAL_JUMP_INTENSITY_SCALE;
        $jumpVol = $priceJumpVol * FinancialConstants::FUNDAMENTAL_JUMP_VOL_SCALE;
        $jumpMean = -$priceJumpVol * FinancialConstants::FUNDAMENTAL_JUMP_MEAN_SCALE;

        $jumpData = $this->mathUtility->calculateJumpDiffusion($jumpIntensity, $jumpMean, $jumpVol, $ctx->dt);
        $jumpMagnitude = $jumpData['exponent'] ?? 0.0;

        $idiosyncraticDemandShock = $revenueVol * sqrt($ctx->dt) * $z1;
        $secularGrowthRate = $strategy->getSecularGrowthRate($stock);
        $secularDrift = $secularGrowthRate * $ctx->dt;

        $rawUtilization = $ctx->seasonalFactor * (1.0 + $secularDrift + $macroDemandShift + $idiosyncraticDemandShock + $jumpMagnitude);
        $ctx->capacityUtilization = max(self::MIN_CAPACITY_UTILIZATION, min(self::MAX_CAPACITY_UTILIZATION, $rawUtilization));
        $maxCipDeduction = abs($ctx->investedCapital) * FinancialConstants::MAX_CIP_CAPITAL_DEDUCTION_RATIO;
        $effectiveCip = min($maxCipDeduction, $stock->getTotalCipAmount());
        $revenueGeneratingCapital = max(abs($ctx->investedCapital) * (1.0 - FinancialConstants::MAX_CIP_CAPITAL_DEDUCTION_RATIO), abs($ctx->investedCapital) - $effectiveCip);

        $dynamicSam = FinancialConstants::BASELINE_SECTOR_TAM * $macroState->nominalGdpIndex * (float) ($stock->getSamRatio() ?? 1.0);
        $maxSectorCapacity = $dynamicSam * FinancialConstants::MAX_SECTOR_TAM_CAPACITY_RATIO;
        
        $structuralRevenue = max(1.0, $revenueGeneratingCapital * $assetTurnover * $pricingPowerMultiplier);
        
        if (!$strategy->isFinancial()) {
            $ctx->structuralRevenue = min($maxSectorCapacity, $structuralRevenue);
            $ctx->expectedRevenue = min($maxSectorCapacity * self::EXPECTED_REVENUE_TAM_HEADROOM, $ctx->structuralRevenue * $ctx->capacityUtilization);
        } else {
            $maxFinancialCapacity = $dynamicSam * FinancialConstants::MAX_FINANCIAL_SECTOR_TAM_CAPACITY_RATIO;
            $ctx->structuralRevenue = min($maxFinancialCapacity, $structuralRevenue);
            $ctx->expectedRevenue = min($maxFinancialCapacity * self::EXPECTED_REVENUE_TAM_HEADROOM, $ctx->structuralRevenue * $ctx->capacityUtilization);
        }

        $fixedCostRatio = (float) $stock->getFixedCostRatio();
        $structuralCosts = $ctx->structuralRevenue * (1.0 - $ctx->stableMargin);

        // Depreciation becomes its own expense line below EBITDA, so it must be carved OUT of the cash cost
        // base rather than added on top of it. The stock's operatingMargin is its EBIT margin — every seed,
        // valuation and solvency test reads it that way — so at structural capacity the carve-out is exactly
        // self-cancelling: revenue - cashCosts - structuralDepreciation == revenue x margin. What changes is
        // that a utilization swing or a drifting asset base now moves EBIT, which is the whole point: units-of
        // -production depreciation used to land on EBITDA, where no coverage or solvency test could see it.
        $ctx->structuralDepreciation = $this->resolveStructuralDepreciation($ctx);
        $cashStructuralCosts = max(
            $structuralCosts * FinancialConstants::MIN_CASH_COST_SHARE,
            $structuralCosts - $ctx->structuralDepreciation
        );

        // Beveridge Wage-Price Spiral SG&A Squeeze:
        // When labor tightness causes wage growth above trend (3.5%), the LABOR share of corporate overhead
        // inflates, squeezing margins for firms that cannot pass costs through via pricing power. The share
        // is sector-specific (OperatingStrategyInterface::getLaborCostShare): a law firm feels nearly all
        // of it, a pipeline operator very little.
        $excessWageGrowth = max(0.0, $macroState->wageGrowth - (MacroEngine::TFP_DRIFT + MacroEngine::TARGET_INFLATION));
        $wageInflationFactor = 1.0 + ($strategy->getLaborCostShare() * $excessWageGrowth / max(0.5, $pricingPowerMultiplier));
        $ctx->fixedCosts = $cashStructuralCosts * $fixedCostRatio * $wageInflationFactor;

        $structuralVariableCosts = $cashStructuralCosts - ($cashStructuralCosts * $fixedCostRatio);
        $ctx->baselineVariableMargin = $structuralVariableCosts / $ctx->structuralRevenue;
    }

    /**
     * Depreciation the firm would charge at exactly structural capacity (utilization 1.0). This is the
     * amount carved out of the cash cost base; the realized charge in calculateDepreciation() scales it
     * by actual utilization, so the difference between the two is what moves reported EBIT.
     */
    private function resolveStructuralDepreciation(EarningsSimulationContext $ctx): float
    {
        return max(0.0, $ctx->strategy->getDepreciableBase($ctx->stock)) * $this->resolveDepreciationRate($ctx) / 4.0;
    }

    /** Annual declining-balance depreciation rate: the stock's own rate, else its industry's. */
    private function resolveDepreciationRate(EarningsSimulationContext $ctx): float
    {
        $custom = (float) $ctx->stock->getDepreciationRate();

        return $custom > 0.0
            ? $custom
            : $this->corporateMetrics->getIndustryDepreciationRate($ctx->stock->getIndustry() ?: 'General');
    }

    /**
     * Units-of-production depreciation (ASC 360) on the net book value of PP&E: the charge scales with how
     * hard the plant is actually run, so a firm sweating its assets wears them out faster.
     *
     * The base is net PP&E, not invested capital. Goodwill is never depreciated — it is impairment-tested
     * annually, which the engine already does — and working capital does not wear out, so the old base
     * overstated the charge for every acquisitive or inventory-heavy firm. Construction in progress is
     * likewise excluded: an asset not yet placed in service earns nothing and depreciates nothing.
     */
    private function calculateDepreciation(EarningsSimulationContext $ctx): void
    {
        $productionRate = $this->resolveDepreciationRate($ctx) * $ctx->capacityUtilization;
        $ctx->quarterlyDepreciation = max(0.0, $ctx->strategy->getDepreciableBase($ctx->stock)) * $productionRate / 4.0;
    }

    /**
     * Annual capital turnover (revenue / invested capital), the DuPont component that fixes how much revenue a
     * dollar of capital can generate: ROIC = after-tax margin x turnover.
     *
     * For physical businesses turnover is a technology parameter, so it is seeded once from the identity using the
     * structural baseline ROIC and margin, persisted on the stock, and then held. Deriving it every quarter from
     * the trailing-return blend let a margin squeeze lower trailing ROIC, which cut the target return and with it
     * the firm's revenue capacity on top of the margin loss; the shortfall lowered ROIC again, a feedback loop with
     * no physical counterpart (a plant does not shrink because last quarter was unprofitable). Growth in capacity
     * now flows only through invested capital (Damodaran: g = reinvestment x ROIC) and the sector TAM cap.
     *
     * Financial intermediaries keep the dynamic derivation: their target return carries net interest margin and
     * cost-of-funds physics that genuinely move the yield on earning assets.
     */
    private function resolveAnnualCapitalTurnover(EarningsSimulationContext $ctx): float
    {
        $afterTaxMargin = $ctx->stableMargin * (1.0 - $ctx->corporateTaxRate);

        if ($ctx->strategy->isFinancial()) {
            return max(0.01, $ctx->baselineRoic) / $afterTaxMargin;
        }

        $stored = $ctx->stock->getAssetTurnover();
        if ($stored !== null && (float) $stored > 0.0) {
            return (float) $stored;
        }

        $structuralTurnover = max(0.01, (float) $ctx->stock->getBaselineRoic()) / $afterTaxMargin;
        $ctx->stock->setAssetTurnover((string) $structuralTurnover);

        return $structuralTurnover;
    }

    private function processVariableMargins(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        $strategy = $ctx->strategy;

        $kappa = $strategy->getMarginReversionSpeed();
        $outputGap = $ctx->macroState->outputGapEma;
        $beta = (float) $stock->getBeta();

        $cyclicalMarginShift = $outputGap * $beta * self::CYCLICAL_MARGIN_SHIFT_COEFFICIENT;
        $dynamicVariableTheta = min(0.99, max(0.01, $ctx->baselineVariableMargin - $cyclicalMarginShift));

        $z2 = $this->mathUtility->generateStandardNormal();
        $marginVol = $ctx->baselineVol * self::MARGIN_VOLATILITY_COEFFICIENT;

        if ($stock->getStructuralVariableMargin() !== null) {
            $currentVariableMargin = max(0.01, min(0.99, (float) $stock->getStructuralVariableMargin()));
        } else {
            // With no prior state the process starts at its own long-run mean, which is the structural
            // variable cost ratio computed above. Deriving it independently from margin and fixed-cost
            // ratio instead would ignore the depreciation carve-out and open every first report with a
            // cost base that no longer matches the one the firm is actually reverting toward.
            $currentVariableMargin = max(0.01, min(0.99, $ctx->baselineVariableMargin));
        }

        $realizedVariableMargin = $this->mathUtility->calculateCIR($currentVariableMargin, $kappa, $dynamicVariableTheta, $marginVol, $ctx->dt, $z2);

        $stock->setStructuralVariableMargin($realizedVariableMargin); // Save true state before asymmetric stickiness noise

        // Asymmetric Cost Stickiness (Anderson, Banker, & Janakiraman 2003):
        // Operating costs contract sluggishly when revenue drops, squeezing variable margins during contractions.
        $priorRevenue = $ctx->previousQuarterlyRevenue > 0.0 ? $ctx->previousQuarterlyRevenue : $ctx->expectedRevenue;
        $currentDeseasonalized = $ctx->expectedRevenue / max(0.01, $ctx->seasonalFactor);
        $priorDeseasonalized   = $priorRevenue        / max(0.01, $ctx->priorSeasonalFactor);
        $revenueLogChange = max(-0.50, min(0.50, log(max(0.01, $currentDeseasonalized / max(1.0, $priorDeseasonalized)))));
        $stickyVariableMargin = $this->mathUtility->calculateAsymmetricCostStickiness($realizedVariableMargin, $revenueLogChange);

        $overtimePremium = $this->mathUtility->calculateConvexPenalty(
            $ctx->capacityUtilization - self::CAPACITY_OVERTIME_THRESHOLD,
            self::CAPACITY_OVERTIME_CONVEXITY,
            self::CAPACITY_OVERTIME_SCALAR
        );

        $ctx->realizedVariableMargin = min(0.99, max(0.01, $stickyVariableMargin + $overtimePremium));
    }

    private function calculateExpectedVsActualFinancials(EarningsSimulationContext $ctx): void
    {
        $actuals = $ctx->strategy->computeActualFinancials(
            $ctx->stock,
            $ctx->expectedRevenue,
            $ctx->realizedVariableMargin,
            $ctx->fixedCosts,
            $ctx->baselineVol,
            $ctx->macroState,
            $this->mathUtility
        );
        $ctx->actualRevenue = $actuals->actualRevenue;
        $ctx->actualVariableCosts = $actuals->actualVariableCosts;

        $coverage = $ctx->strategy->getCoverageProfile($ctx->stock);
        $seasonalRatio = $ctx->seasonalFactor / max(0.01, $ctx->priorSeasonalFactor);
        $consensus = $this->marketConsensusEngine->generateConsensus(
            $actuals,
            $coverage,
            $ctx->expectedRevenue,
            $this->mathUtility,
            $ctx->stock,
            $ctx->macroState->marketVolatilityEma,
            $ctx->realizedVariableMargin,
            $seasonalRatio
        );
        $ctx->analystExpectedRevenue = $consensus->analystExpectedRevenue;
        $ctx->analystExpectedVariableCosts = $consensus->analystExpectedVariableCosts;
        $ctx->estimateDispersion = $consensus->estimateDispersion;

        // Depreciation is the most forecastable line on the income statement — it follows a schedule the
        // firm has already disclosed — so analysts get it right and it is not a source of surprise.
        $expectedEbit = $ctx->analystExpectedRevenue - $ctx->fixedCosts - $ctx->analystExpectedVariableCosts - $ctx->quarterlyDepreciation;
        $ctx->expectedEbit = max(-$ctx->structuralRevenue * self::MAX_EBIT_LOSS_RATIO, $expectedEbit);

        // Operating costs are the cash cost base; EBITDA sits above the depreciation line and EBIT below it.
        $ctx->operatingCosts = $ctx->actualVariableCosts + $ctx->fixedCosts;
        $ctx->ebitda = $ctx->actualRevenue - $ctx->operatingCosts;
        $ctx->ebit = $ctx->ebitda - $ctx->quarterlyDepreciation;

        $ctx->primaryShockZ = $actuals->primaryShockZ;
        $ctx->eventType = $actuals->eventType;
        $ctx->eventContext = $actuals->eventContext;
        $ctx->stock->setEarningsMomentumZ($actuals->streamZ);
        $ctx->streamRevenue = $actuals->streamRevenue;
        $ctx->scheduledCapex = max(0.0, $actuals->scheduledCapex);
        $ctx->kpis = $actuals->kpis;

        // Stock-based compensation (ASC 718) is already inside the operating cost base: it changes no margin,
        // but it is non-cash (added back to FCF below) and is settled in newly issued shares.
        $ctx->stockCompensation = max(0.0, $ctx->actualRevenue) * $ctx->strategy->getStockCompensationIntensity();
        $ctx->kpis['stock_compensation'] = $ctx->stockCompensation;
    }

    private function calculateInterestAndRunRates(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;

        // Seasonally Adjusted Annual Rate (SAAR) Metrics:
        // Reported quarterly revenue and EBIT oscillate with operational seasonality.
        // Annual-basis metrics (total revenue run-rate, borrowing rate, ICR, debt health)
        // must read the seasonally adjusted run-rate rather than interpreting a seasonal
        // trough/peak as a permanent structural shift.
        $ctx->seasonallyAdjustedRevenue = $ctx->actualRevenue / max(0.01, $ctx->seasonalFactor);
        $realizedCostRatio = $ctx->actualRevenue > 0.0
            ? ($ctx->actualVariableCosts / $ctx->actualRevenue)
            : $ctx->realizedVariableMargin;
        $ctx->seasonallyAdjustedEbit = ($ctx->seasonallyAdjustedRevenue * (1.0 - $realizedCostRatio)) - $ctx->fixedCosts - $ctx->quarterlyDepreciation;
        $ctx->structuralOperatingMargin = $ctx->seasonallyAdjustedEbit / max(1.0, $ctx->seasonallyAdjustedRevenue);

        // Persist the margin the firm actually earned. The stock's operatingMargin is the slow structural
        // parameter that only asset reinvestment moves, so solvency tests reading it price a collapse in
        // realized profitability quarters late.
        $stock->setReportedOperatingMargin($ctx->structuralOperatingMargin);

        $annualSaarRevenue = MathUtility::calculateSeasonallyAdjustedAnnualRate($ctx->actualRevenue, $ctx->seasonalFactor, 4);
        $stock->setTotalRevenue((string) $annualSaarRevenue);

        $saExpectedRevenue = $ctx->expectedRevenue / max(0.01, $ctx->seasonalFactor);
        $saExpectedEbit = ($saExpectedRevenue * (1.0 - $ctx->baselineVariableMargin)) - $ctx->fixedCosts - $ctx->quarterlyDepreciation;
        $saExpectedMargin = $saExpectedEbit / max(1.0, $saExpectedRevenue);
        $expectedDebtMetrics = $this->debtEngine->calculateInterestExpense($stock, $ctx->macroState, false, $saExpectedRevenue * 4.0, $saExpectedMargin);
        $ctx->expectedInterestExpense = $expectedDebtMetrics->interestExpense / 4.0;

        $ctx->trueOperatingMargin = $ctx->ebit / max(1.0, $ctx->actualRevenue);
        $ctx->debtMetrics = $this->debtEngine->calculateInterestExpense($stock, $ctx->macroState, true, $annualSaarRevenue, $ctx->structuralOperatingMargin);

        $annualInterestExpense = $ctx->debtMetrics->interestExpense;
        $ctx->quarterlyInterestExpense = $annualInterestExpense / 4.0;
        // Note: we can't mutate debtMetrics since it's readonly. The DB will store $ctx->quarterlyInterestExpense.

        $stock->setHistoricalFixedRate((string) $ctx->debtMetrics->historicalFixedRate);

        // Pass the realized wholesale rate (dynamic Merton/BGG spread already applied) so strategies whose
        // earning assets reprice off their own funding cost book income consistent with the expense side
        // computed just above, rather than a stale calm-market benchmark.
        $annualInterestIncome = $ctx->strategy->calculateInterestIncome($stock, $ctx->macroState, $this->mathUtility, $ctx->debtMetrics->wholesaleRate);
        $ctx->quarterlyInterestIncome = $annualInterestIncome / 4.0;
    }

    private function reconcileTaxesAndNetIncome(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        $expectedEbt = $ctx->expectedEbit - $ctx->expectedInterestExpense + $ctx->quarterlyInterestIncome;
        $actualEbt = $ctx->ebit - $ctx->quarterlyInterestExpense + $ctx->quarterlyInterestIncome;

        $nol = (float) $stock->getNetOperatingLoss();

        if ($actualEbt > 0 && $nol > 0) {
            $maxShield = $actualEbt * FinancialConstants::NOL_MAX_SHIELD_RATIO;
            $shielded = min($maxShield, $nol);
            $taxableIncome = $actualEbt - $shielded;
            $stock->setNetOperatingLoss((string) ($nol - $shielded));
            $ctx->actualQuarterlyNetIncome = $actualEbt - ($taxableIncome * $ctx->corporateTaxRate);
        } elseif ($actualEbt < 0) {
            $stock->setNetOperatingLoss((string) ($nol + abs($actualEbt)));
            $ctx->actualQuarterlyNetIncome = $actualEbt;
        } else {
            $ctx->actualQuarterlyNetIncome = $actualEbt * (1.0 - $ctx->corporateTaxRate);
        }

        $this->splitTaxExpenseIntoCurrentAndDeferred($ctx, $actualEbt - $ctx->actualQuarterlyNetIncome);

        if ($expectedEbt > 0 && $nol > 0) {
            $expectedMaxShield = $expectedEbt * FinancialConstants::NOL_MAX_SHIELD_RATIO;
            $expectedShielded = min($expectedMaxShield, $nol);
            $expectedTaxable = $expectedEbt - $expectedShielded;
            $ctx->expectedQuarterlyNetIncome = $expectedEbt - ($expectedTaxable * $ctx->corporateTaxRate);
        } elseif ($expectedEbt < 0) {
            $ctx->expectedQuarterlyNetIncome = $expectedEbt;
        } else {
            $ctx->expectedQuarterlyNetIncome = $expectedEbt * (1.0 - $ctx->corporateTaxRate);
        }

        $ctx->preTaxIncome = $actualEbt;
        // Reported tax expense is current plus deferred; the cash figure is on the context separately.
        $ctx->taxPaid = $actualEbt - $ctx->actualQuarterlyNetIncome;

        $ctx->reportedExpectedNetIncome = $ctx->expectedQuarterlyNetIncome;
        $ctx->reportedActualNetIncome = $ctx->actualQuarterlyNetIncome;

        if ($ctx->businessModel === 'reit') {
            $ctx->reportedExpectedNetIncome += $ctx->quarterlyDepreciation;
            $ctx->reportedActualNetIncome += $ctx->quarterlyDepreciation;
        }

        $annualSaarRevenue = MathUtility::calculateSeasonallyAdjustedAnnualRate($ctx->actualRevenue, $ctx->seasonalFactor, 4);
        $ctx->health = $this->debtEngine->analyzeDebtHealth($stock, $ctx->macroState, $annualSaarRevenue, $ctx->structuralOperatingMargin);
        $ctx->truePostTaxReturn = $ctx->strategy->updateDynamicRoic(
            $stock,
            $ctx->actualQuarterlyNetIncome,
            $ctx->investedCapital,
            $ctx->seasonallyAdjustedEbit,
            $ctx->corporateTaxRate,
            $ctx->health->wacc ?? 0.08,
            $ctx->health->costOfEquity ?? 0.10,
            $ctx->macroState,
            $ctx->quarterlyDepreciation
        );

        $this->testGoodwillForImpairment($ctx);
    }

    /**
     * Annual goodwill impairment test (ASC 350 / IAS 36), run in the fiscal fourth quarter. Value in use of the
     * acquired capital is its perpetuity value, capital x ROIC / hurdle; when the trailing return has fallen
     * below the hurdle the carrying amount exceeds that value and the shortfall is written off against
     * goodwill. The charge is non-cash: it hits reported (GAAP) earnings, equity and the goodwill balance,
     * never free cash flow, and analysts do not forecast it, so it lands as a negative earnings surprise.
     */
    private function testGoodwillForImpairment(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        $goodwill = (float) $stock->getGoodwill();
        if ($goodwill <= 0.0 || $ctx->fiscalQuarter !== self::FISCAL_YEAR_END_QUARTER || !$ctx->health instanceof \App\DTO\DebtHealthDTO) {
            return;
        }

        $trailingReturn = $ctx->strategy->getTrueReturn($stock);
        $hurdleRate = $ctx->strategy->getHurdleRate($ctx->health);
        if ($hurdleRate <= 0.0 || $trailingReturn >= $hurdleRate) {
            return;
        }

        $carryingCapital = $ctx->strategy->getEvaluationCapital((float) $stock->getTotalEquity(), $ctx->investedCapital);
        $valueShortfall = max(0.0, $carryingCapital) * (1.0 - (max(0.0, $trailingReturn) / $hurdleRate));
        $impairment = min($goodwill, max(0.0, $valueShortfall));
        if ($impairment < $goodwill * FinancialConstants::MIN_GOODWILL_IMPAIRMENT_FRACTION) {
            return;
        }

        $stock->setGoodwill((string) ($goodwill - $impairment));
        $stock->setTotalEquity((string) max(10.0, (float) $stock->getTotalEquity() - $impairment));
        $stock->setRetainedEarnings((string) ((float) $stock->getRetainedEarnings() - $impairment));

        $ctx->goodwillImpairment = $impairment;
        $ctx->reportedActualNetIncome -= $impairment;
    }

    private function calculateEPSAndSurprise(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        $shares = max(1.0, $ctx->sharesOutstanding);

        // Quarterly reported EPS for analyst surprise calculation (matches analyst consensus which already anticipates seasonality)
        $ctx->actualQuarterlyEps = $ctx->reportedActualNetIncome / $shares;
        $ctx->expectedQuarterlyEps = $ctx->reportedExpectedNetIncome / $shares;
        $ctx->surpriseAmountQuarterly = $ctx->actualQuarterlyEps - $ctx->expectedQuarterlyEps;

        // Seasonally Adjusted Annual EPS for balance sheet and valuation (Kalman TTM EPS)
        $saEbt = $ctx->seasonallyAdjustedEbit - $ctx->quarterlyInterestExpense + $ctx->quarterlyInterestIncome;
        $saQuarterlyNetIncome = $saEbt > 0.0 ? $saEbt * (1.0 - $ctx->corporateTaxRate) : $saEbt;
        if ($ctx->businessModel === 'reit') {
            $saQuarterlyNetIncome += $ctx->quarterlyDepreciation;
        }
        $saAnnualEpsRaw = ($saQuarterlyNetIncome * 4.0) / $shares;
        $ctx->actualAnnualEpsRaw = $saAnnualEpsRaw;

        // Trailing twelve month earnings are the SUM of the last four reported quarters, not a filter over
        // them. Smoothing the headline figure here put a multi-year half-life on it, so the P/E a player read
        // lagged the business by years and a genuine collapse in earnings was invisible on the screener.
        // Valuation is unaffected: the market engine runs its own Kalman filter against the strategy's
        // structural EPS and treats this figure as the noisy measurement it is meant to be.
        //
        // The history holds absolute net income rather than per-share amounts, because EPS on this entity is
        // derived from net income over current shares. Buybacks therefore lift trailing EPS and splits divide
        // it with no restatement of history, exactly as reported accounts behave.
        $history = $stock->getQuarterlyNetIncomeHistory() ?? [];
        if (count($history) < self::TTM_QUARTERS) {
            // First report: seed from the deseasonalized run-rate so the opening trailing figure does not
            // inherit the seasonality of whichever quarter happens to report first.
            $history = array_fill(0, self::TTM_QUARTERS, $saQuarterlyNetIncome);
        }

        $history[] = $ctx->reportedActualNetIncome;
        $history = array_map('floatval', array_slice($history, -self::TTM_QUARTERS));
        $stock->setQuarterlyNetIncomeHistory(array_values($history));

        $trailingNetIncome = max(
            -self::MAX_ABSOLUTE_NET_INCOME,
            min(self::MAX_ABSOLUTE_NET_INCOME, array_sum($history))
        );
        $stock->setTotalNetIncome((string) $trailingNetIncome);

        $rawEpsSurprise = abs($ctx->expectedQuarterlyEps) > 0.01
            ? $ctx->surpriseAmountQuarterly / abs($ctx->expectedQuarterlyEps)
            : ($ctx->surpriseAmountQuarterly > 0 ? self::ZERO_BASE_SURPRISE_PCT : ($ctx->surpriseAmountQuarterly < 0 ? -self::ZERO_BASE_SURPRISE_PCT : 0.0));
        $epsSurprisePct = max(-1.0, min(1.0, $rawEpsSurprise));

        $revenueSurprisePct = abs($ctx->analystExpectedRevenue) > 1.0
            ? ($ctx->actualRevenue - $ctx->analystExpectedRevenue) / abs($ctx->analystExpectedRevenue)
            : 0.0;

        $blendWeights = $ctx->strategy->getSurpriseBlendWeights();
        $epsWeight = $blendWeights['eps_weight'];
        $revWeight = $blendWeights['revenue_weight'];
        $ctx->surprisePct = ($revenueSurprisePct * $revWeight) + ($epsSurprisePct * $epsWeight);
    }

    private function calculateFreeCashFlow(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;

        if ($ctx->sharesOutstanding <= 0) {
            $fcfData = ['fcf_per_share' => 0.0, 'capex' => 0.0, 'direct_capex' => 0.0];
        } else {
            $outputGap = $ctx->macroState->outputGapEma;
            $capexCyclicality = $ctx->strategy->getCapexCyclicality();
            $cycleCapExModifier = max(0.50, min(1.50, 1.00 + ($outputGap * $capexCyclicality)));

            // Operational CapEx:
            // 1. Maintenance CapEx: replaces depreciating physical capital in ongoing operations.
            //    Scales with macro cyclicality and drops during severe financial distress.
            $solvencyFactor = $ctx->actualQuarterlyNetIncome > 0
                ? 1.0
                : max(0.20, 1.0 + ($ctx->actualQuarterlyNetIncome / max(1.0, abs($ctx->investedCapital))));

            // Replacement cost (BEA perpetual inventory method): depreciation is measured against what the
            // plant originally cost, but replacing a worn machine costs today's price. The ratio of the
            // current capital-goods price level to the vintage the plant was bought at is how far a
            // maintenance dollar has to stretch. This is the channel through which inflation actually
            // reaches the balance sheet — the firm spends more cash to stand still — replacing the old
            // revaluation that simply wrote equity up with no cash and no income behind it.
            $ctx->replacementCostRatio = $this->resolveReplacementCostRatio($ctx);
            $maintenanceCapEx = $ctx->quarterlyDepreciation * $ctx->replacementCostRatio * $cycleCapExModifier * $solvencyFactor;

            $currentAnnualizedRevenue = $ctx->actualRevenue / max(0.001, $ctx->dt);
            $priorNwcStr = $stock->getNetWorkingCapital();
            $currentNwc = $this->rollForwardWorkingCapitalLedger($ctx, $currentAnnualizedRevenue);

            // Seed on first report; no spurious one-time swing.
            $priorNwc = $priorNwcStr === null ? $currentNwc : (float) $priorNwcStr;
            $rawDeltaNwc = $currentNwc - $priorNwc;
            $maxNwcSwing = $currentAnnualizedRevenue * 0.25; // Clamp single-quarter NWC swing to at most 1 quarter of revenue
            $deltaNwc = max(-$maxNwcSwing, min($maxNwcSwing, $rawDeltaNwc));
            $ctx->deltaWorkingCapital = $deltaNwc;

            // 2. Growth CapEx: fundamental reinvestment planned on normalized earnings power,
            //    gated by the NPV hurdle and bounded by internally available funding.
            $growthCapEx = $this->calculateGrowthCapEx($ctx, $cycleCapExModifier, $maintenanceCapEx, $deltaNwc);

            // 3. Scheduled CapEx: outlays the sector physics itself commits (spectrum licences, grid rebuilds).
            //    Mandatory, so it bypasses the funding gate; a cash shortfall is the treasury's problem. The
            //    asset is not productive on day one, so it is queued as construction-in-progress.
            if ($ctx->scheduledCapex > 0.0) {
                $this->capExEngine->allocateGrowthCapEx($stock, $ctx->scheduledCapex);
            }

            $actualCapEx = $maintenanceCapEx + $growthCapEx + $ctx->scheduledCapex;

            // Stock-based compensation is a non-cash expense: added back to operating cash flow (ASC 718).
            // Inventory writedowns and credit-loss provisions are non-cash, exactly like depreciation and
            // equity compensation: they hit reported earnings but no money moves, so they come back here.
            $fcff = $ctx->actualQuarterlyNetIncome + $ctx->quarterlyDepreciation + $ctx->stockCompensation
                + $ctx->inventoryWriteDown + $ctx->receivablesProvision + $ctx->deferredTaxExpense
                - $deltaNwc - $actualCapEx;
            $ctx->operatingCashFlow = $fcff + $actualCapEx;
            $ctx->investingCashFlow = -$actualCapEx;

            $fcfData = [
                'fcf_per_share' => $fcff / $ctx->sharesOutstanding,
                'capex' => $actualCapEx,
                // Only spend that buys an asset outright lands in PP&E this quarter. Scheduled CapEx is
                // queued as construction in progress and reaches the ledger when it is placed in service,
                // so counting it here as well would capitalize the same dollar twice.
                'direct_capex' => $maintenanceCapEx + $growthCapEx,
            ];
        }

        $annualFcfPerShare = $fcfData['fcf_per_share'] / max(0.001, $ctx->dt);
        $actualAnnualCapEx = $fcfData['capex'] / max(0.001, $ctx->dt);

        // Replacement CapEx is the bar the firm has to clear to stand still, so the reinvestment ratio is
        // measured against the depreciation charge restated at today's prices. Comparing spend to the
        // historical-cost charge would read pure inflation as modernization and hand out margin for it.
        $replacementDepreciation = $ctx->quarterlyDepreciation * $ctx->replacementCostRatio;
        $reinvestmentRatio = $replacementDepreciation > 0 ? ($fcfData['capex'] / $replacementDepreciation) : 1.0;
        $ctx->strategy->applyAssetDepreciationDecay($stock, $reinvestmentRatio, $ctx->dt);

        $this->rollForwardFixedAssetLedger($ctx, $fcfData['direct_capex']);

        $currentPrice = (float) $stock->getPrice();
        $debtBeforeAllocation = (float) $stock->getWholesaleDebt();
        $ctx->allocation = $this->capitalAllocationEngine->allocateCapital(
            $stock,
            $ctx->actualAnnualEpsRaw,
            $fcfData['fcf_per_share'],
            $currentPrice,
            $ctx->sharesOutstanding,
            $ctx->macroState,
            $ctx->actualQuarterlyNetIncome,
            $ctx->stockCompensation
        );

        $stock->setSharesOutstanding((string) $ctx->allocation['new_shares']);

        $organicCapex = $ctx->allocation['organic_capex'] ?? 0.0;

        // Cash-flow statement signs classify the life-cycle stage (Dickinson 2011): net investment includes
        // organic expansion, net financing is debt raised plus shares issued net of dividends and buybacks.
        $ctx->investingCashFlow -= $organicCapex;
        $sharesDelta = ((float) $ctx->allocation['new_shares']) - $ctx->sharesOutstanding;
        $ctx->financingCashFlow = ((float) $stock->getWholesaleDebt() - $debtBeforeAllocation)
            + ($sharesDelta * $currentPrice)
            - (float) ($ctx->allocation['total_paid'] ?? 0.0);
        $ctx->lifecycleStage = \App\Data\LifecycleStage::fromCashFlowSigns(
            $ctx->operatingCashFlow > 0.0,
            $ctx->investingCashFlow > 0.0,
            $ctx->financingCashFlow > 0.0
        );
        $stock->setLifecycleStage($ctx->lifecycleStage);

        // Settle this quarter's stock-based compensation in new shares (dilution), a non-cash, non-financing flow.
        if ($ctx->stockCompensation > 0.0 && $currentPrice > 0.0) {
            $stock->setSharesOutstanding((string) ((float) $stock->getSharesOutstanding() + ($ctx->stockCompensation / $currentPrice)));
        }
        $reportedOrganicCapex = $ctx->strategy->allowsPhysicalOrganicCapex() ? $organicCapex : 0.0;
        $annualizedOrganicCapex = $reportedOrganicCapex * 4.0;
        $organicCapexPerShare = $ctx->sharesOutstanding > 0 ? ($annualizedOrganicCapex / $ctx->sharesOutstanding) : 0.0;

        $trueAnnualFcfPerShare = $annualFcfPerShare - $organicCapexPerShare;
        $stock->setFreeCashFlowPerShare((string) $trueAnnualFcfPerShare);

        $ctx->totalReportedCapex = ($actualAnnualCapEx / 4.0) + $reportedOrganicCapex;
        $ctx->trueQuarterlyFcf = ($trueAnnualFcfPerShare * $ctx->sharesOutstanding) / 4.0;

        // Sloan (1996) Accruals Anomaly: Accruals = (Net Income - FCF) / Total Assets
        $totalAssets = max(FinancialConstants::MIN_OPERATING_BASE_CASH, (float) $stock->getTotalEquity() + (float) $stock->getTotalDebt());
        $quarterlyAccruals = $ctx->actualQuarterlyNetIncome - $ctx->trueQuarterlyFcf;
        $accrualsRatio = ($quarterlyAccruals * 4.0) / $totalAssets;
        $stock->setAccrualsRatio($accrualsRatio);
    }

    /**
     * Advances the separate tax basis of PP&E and returns this quarter's book-versus-tax timing difference.
     *
     * Tax depreciation uses the 200% declining balance method (the MACRS general depreciation system):
     * the same asset is written off faster for the tax authority than for shareholders. Early in an
     * asset's life tax depreciation exceeds book, so taxable income is lower than book income and the
     * unpaid tax accumulates as a deferred liability. Later the two cross over and the liability unwinds.
     *
     * A firm investing steadily therefore carries a permanently growing deferred balance, which is why
     * capital-hungry companies pay a cash tax rate well below the statutory one for decades at a time.
     */
    private function splitTaxExpenseIntoCurrentAndDeferred(EarningsSimulationContext $ctx, float $bookTaxExpense): void
    {
        $stock = $ctx->stock;
        $timingDifference = $this->rollForwardTaxDepreciation($ctx);

        // The tax footnote identity: total expense is unchanged, and is split into the part paid this
        // quarter and the part postponed. Splitting rather than recomputing is what guarantees reported
        // earnings and EPS are untouched by this rule, which is exactly right — a timing difference moves
        // cash, never profit.
        //
        // The deferred half is bounded by the total expense so cash tax can never turn negative (the firm
        // does not receive money from the tax authority for buying equipment) and the reversal can never
        // charge more than double.
        $rawDeferred = $timingDifference * $ctx->corporateTaxRate;
        $deferredTax = max(-$bookTaxExpense, min($bookTaxExpense, $rawDeferred));

        $ctx->deferredTaxExpense = $deferredTax;
        $ctx->cashTaxPaid = $bookTaxExpense - $deferredTax;
        $stock->setDeferredTaxLiability((string) max(0.0, (float) $stock->getDeferredTaxLiability() + $deferredTax));
    }

    /**
     * Advances the separate tax basis of PP&E and returns this quarter's book-versus-tax timing difference.
     *
     * Tax depreciation uses the 200% declining balance method (the MACRS general depreciation system): the
     * same asset is written off faster for the tax authority than for shareholders. Early in an asset's
     * life tax depreciation exceeds book, so taxable income is lower than book income and the unpaid tax
     * accumulates as a deferred liability. On an ageing asset the two cross over and the liability unwinds.
     *
     * A firm investing steadily therefore carries a permanently growing deferred balance, which is why
     * capital-hungry companies pay a cash tax rate well below the statutory one for decades at a time.
     */
    private function rollForwardTaxDepreciation(EarningsSimulationContext $ctx): float
    {
        $stock = $ctx->stock;
        if ($stock->getGrossPpe() === null) {
            return 0.0; // Financial balance sheets keep no plant, so there is no timing difference to track.
        }

        // Opening basis equals book value: no deferred tax is inherited from before the firm existed.
        $basis = (float) ($stock->getPpeTaxBasis() ?? (string) $stock->getNetPpe());

        $taxRate = $this->resolveDepreciationRate($ctx) * FinancialConstants::TAX_DEPRECIATION_ACCELERATION;
        $taxDepreciation = min($basis, max(0.0, $basis) * $taxRate / 4.0);

        // Additions join the basis in the ledger roll-forward, alongside the book ledger they also enter.
        $stock->setPpeTaxBasis((string) max(0.0, $basis - $taxDepreciation));

        return $taxDepreciation - $ctx->quarterlyDepreciation;
    }

    /**
     * Rebuilds the working capital balances from the cash conversion cycle and returns the new net figure.
     *
     * Working capital used to be a single scalar, which meant nothing inside it could ever go wrong. Carried
     * as real balances, receivables and inventory become things that can be impaired: a customer stops
     * paying, or goods sit unsold until they are worth less than they cost. Both are ordinary recession
     * charges and neither is forecastable, which is why they show up as misses.
     *
     * Receivables scale with revenue (they are billed sales); inventory and payables scale with the cost
     * base (they are carried at cost, not at what the firm hopes to sell them for).
     */
    private function rollForwardWorkingCapitalLedger(EarningsSimulationContext $ctx, float $annualizedRevenue): float
    {
        $stock = $ctx->stock;

        $baseDays = $ctx->strategy->getWorkingCapitalDays($stock);
        $deseasonalizedUtilization = $ctx->capacityUtilization / max(0.01, $ctx->seasonalFactor);
        $shifts = $this->mathUtility->calculateWorkingCapitalDayShifts(
            creditSpread: $ctx->macroState->macroCreditSpreadEma,
            capacityUtilization: $deseasonalizedUtilization,
            interbankLiquiditySpread: $ctx->macroState->interbankLiquiditySpreadEma
        );

        // A macro shift stretches an existing cycle; it cannot conjure one. A firm that carries no inventory
        // does not start accumulating it because demand fell, and a bank with no trade receivables does not
        // acquire some because credit spreads widened. Only components the model actually declares move.
        $shiftDays = static fn (float $base, float $shift): float => $base > 0.0 ? max(0.0, $base + $shift) : 0.0;

        $dso = $shiftDays($baseDays['dso'] ?? 0.0, $shifts['dso'] ?? 0.0);
        $dio = $shiftDays($baseDays['dio'] ?? 0.0, $shifts['dio'] ?? 0.0);
        $dpo = $shiftDays($baseDays['dpo'] ?? 0.0, $shifts['dpo'] ?? 0.0);

        $annualizedCosts = max(0.0, ($ctx->actualVariableCosts + $ctx->fixedCosts) / max(0.001, $ctx->dt));
        $perDay = FinancialConstants::DAYS_PER_YEAR;

        $stock->setReceivables((string) max(0.0, $annualizedRevenue * $dso / $perDay));
        $stock->setInventory((string) max(0.0, $annualizedCosts * $dio / $perDay));
        $stock->setPayables((string) max(0.0, $annualizedCosts * $dpo / $perDay));

        return (float) $stock->getNetWorkingCapital();
    }

    /**
     * Impairs the working capital balances that can go bad.
     *
     * Inventory (ASC 330, lower of cost and net realizable value): when the plant is running well below
     * capacity the goods are not moving, and stock that has to be cleared goes out below cost. The charge
     * scales with how far demand has fallen.
     *
     * Receivables (ASC 326, expected credit losses): the allowance is a level, not a flow, so a provision is
     * booked when the expected loss rate RISES and released slowly when it falls. Modelling it as a flow
     * would charge a firm every quarter of a downturn rather than at the point the outlook deteriorates.
     *
     * Both are non-cash and neither is in the analyst forecast, so they land as negative surprises. They
     * are deducted after consensus is formed for exactly that reason.
     */
    private function applyWorkingCapitalCharges(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        if (!$stock->hasWorkingCapitalLedger()) {
            return; // No ledger yet (first report, or a balance-sheet business that keeps no trade cycle).
        }

        $ctx->inventoryWriteDown = $this->resolveInventoryWriteDown($ctx);
        $ctx->receivablesProvision = $this->resolveReceivablesProvision($ctx);

        $totalCharge = $ctx->inventoryWriteDown + $ctx->receivablesProvision;
        if ($totalCharge === 0.0) {
            return;
        }

        // Both charges sit in operating expense, so they reduce EBITDA and EBIT alike. Analysts do not
        // forecast them, so expectedEbit is deliberately left untouched.
        $ctx->ebitda -= $totalCharge;
        $ctx->ebit -= $totalCharge;
    }

    /** Lower-of-cost-or-net-realizable-value writedown on inventory the firm cannot move (ASC 330). */
    private function resolveInventoryWriteDown(EarningsSimulationContext $ctx): float
    {
        $inventory = (float) ($ctx->stock->getInventory() ?? 0.0);
        if ($inventory <= 0.0) {
            return 0.0;
        }

        $trigger = FinancialConstants::INVENTORY_NRV_UTILIZATION_TRIGGER;
        $utilization = $ctx->capacityUtilization / max(0.01, $ctx->seasonalFactor);
        if ($utilization >= $trigger) {
            return 0.0;
        }

        $severity = min(1.0, ($trigger - $utilization) / $trigger);
        $charge = $inventory * FinancialConstants::INVENTORY_NRV_LOSS_RATE * $severity;

        $ctx->stock->setInventory((string) max(0.0, $inventory - $charge));

        return $charge;
    }

    /** Expected credit loss allowance on trade receivables, provisioned to a level (ASC 326). */
    private function resolveReceivablesProvision(EarningsSimulationContext $ctx): float
    {
        $receivables = (float) ($ctx->stock->getReceivables() ?? 0.0);
        if ($receivables <= 0.0) {
            return 0.0;
        }

        $defaultRate = max(0.0, min(1.0, $ctx->macroState->corporateDefaultRateEma));
        $targetAllowance = $receivables * $defaultRate * FinancialConstants::TRADE_RECEIVABLE_LGD;
        $currentAllowance = (float) $ctx->stock->getReceivablesAllowance();

        $provision = $targetAllowance - $currentAllowance;
        if ($provision < 0.0) {
            // A recovery is released gradually: an improving outlook is not instant profit.
            $provision = -min(abs($provision), $currentAllowance * FinancialConstants::MAX_ALLOWANCE_RELEASE_RATIO);
        }

        $ctx->stock->setReceivablesAllowance((string) max(0.0, $currentAllowance + $provision));

        return $provision;
    }

    /**
     * How far a maintenance dollar has to stretch: the current capital-goods price level over the price
     * level the existing plant was bought at. One when prices have not moved since the plant was built.
     *
     * The vintage is seeded to the current level for a firm that has never reported, so nobody inherits a
     * replacement bill for inflation that happened before they existed.
     */
    private function resolveReplacementCostRatio(EarningsSimulationContext $ctx): float
    {
        $stock = $ctx->stock;

        // No plant, no replacement cost. Financial models never open a fixed-asset ledger, so their vintage
        // would never roll forward and the ratio would climb with the price level forever, charging a bank
        // an ever-growing cash premium to replace machinery it does not own.
        if ($stock->getGrossPpe() === null) {
            return 1.0;
        }

        $currentDeflator = max(0.01, $ctx->macroState->gdpDeflator);
        $vintage = $stock->getPpeVintageDeflator();

        if ($vintage === null || (float) $vintage <= 0.0) {
            $stock->setPpeVintageDeflator((string) $currentDeflator);

            return 1.0;
        }

        return max(0.0, $currentDeflator / (float) $vintage);
    }

    /**
     * Perpetual-inventory roll-forward of the fixed-asset ledger.
     *
     * Gross cost rises by the capital actually placed in service this quarter: CapEx that buys an asset
     * outright, plus construction finally completed. Accumulated depreciation rises by the quarter's charge.
     *
     * Fully depreciated cost is then retired from both sides at the useful-life rate. Without retirement the
     * gross balance would grow forever while net book value plateaued, so the asset-age ratio would march to
     * 1.0 and never come back — a firm that had replaced its entire plant would still look ancient. Retiring
     * a cohort each quarter is what a real fixed-asset register does when equipment reaches the end of its
     * life and leaves the books.
     */
    private function rollForwardFixedAssetLedger(EarningsSimulationContext $ctx, float $directCapex): void
    {
        $stock = $ctx->stock;
        if ($stock->getGrossPpe() === null) {
            return; // Financial balance sheets never open a plant ledger.
        }

        $openingNetPpe = $stock->getNetPpe();
        $additions = max(0.0, $directCapex) + max(0.0, $ctx->completedCip);

        $grossPpe = (float) $stock->getGrossPpe() + $additions;
        $accumulated = (float) $stock->getAccumulatedDepreciation() + max(0.0, $ctx->quarterlyDepreciation);

        // The plant's vintage is the CapEx-weighted price level it was bought at: this quarter's additions
        // come in at today's prices and pull the average forward, while the assets still on the books keep
        // theirs. A firm replacing its plant steadily converges on the current price level; one that has
        // stopped investing keeps an old vintage, and its eventual replacement bill grows accordingly.
        $currentDeflator = max(0.01, $ctx->macroState->gdpDeflator);
        $vintage = (float) ($stock->getPpeVintageDeflator() ?? $currentDeflator);
        $vintageBase = max(0.0, $openingNetPpe) + $additions;
        if ($vintageBase > 0.0) {
            $vintage = ((max(0.0, $openingNetPpe) * $vintage) + ($additions * $currentDeflator)) / $vintageBase;
        }
        $stock->setPpeVintageDeflator((string) max(0.01, $vintage));

        $retired = min($accumulated, $grossPpe * $this->resolveDepreciationRate($ctx) / 4.0);
        $grossPpe -= $retired;
        $accumulated -= $retired;

        $stock->setGrossPpe((string) max(0.0, $grossPpe));
        $stock->setAccumulatedDepreciation((string) max(0.0, min($accumulated, $grossPpe)));

        // New assets enter the tax basis at cost, exactly as they enter the book ledger, and then start
        // depreciating on their own accelerated schedule from next quarter.
        if ($additions > 0.0 && $stock->getPpeTaxBasis() !== null) {
            $stock->setPpeTaxBasis((string) ((float) $stock->getPpeTaxBasis() + $additions));
        }
    }

    /**
     * Growth CapEx follows the fundamental reinvestment identity (Damodaran): the capital budget is planned on
     * normalized earnings power (structural ROIC x invested capital), not on the current quarter's reported
     * profit. A loss-making growth firm therefore keeps investing from its cash pile while a boom quarter does
     * not trigger a one-off splurge. The stock's capexRatio is its reinvestment rate (g = reinvestment x ROIC).
     *
     * Two real-world gates apply:
     *  1. NPV rule: no growth investment while the structural return fails the model's hurdle rate.
     *  2. Funding constraint: spend is bounded by internally generated cash plus cash above the operating floor.
     */
    private function calculateGrowthCapEx(EarningsSimulationContext $ctx, float $cycleCapExModifier, float $maintenanceCapEx, float $deltaNwc): float
    {
        $stock = $ctx->stock;
        $reinvestmentRate = max(0.0, (float) $stock->getCapexRatio());
        $hurdleRate = $ctx->health instanceof \App\DTO\DebtHealthDTO
            ? $ctx->strategy->getHurdleRate($ctx->health)
            : FinancialConstants::DEFAULT_WACC_FALLBACK;

        if ($reinvestmentRate <= 0.0 || $ctx->baselineRoic < $hurdleRate) {
            return 0.0;
        }

        $structuralQuarterlyNopat = $ctx->baselineRoic * abs($ctx->investedCapital) / 4.0;
        $plannedGrowthCapEx = $structuralQuarterlyNopat * $reinvestmentRate * $cycleCapExModifier;

        $operatingBase = $this->corporateMetrics->calculateOperatingBase((float) $stock->getTotalRevenue(), (float) $stock->getTotalEquity());
        $minOperatingCash = $ctx->strategy->calculateMinOperatingCash($operatingBase, (float) $stock->getCustomerDeposits(), (float) $stock->getWholesaleDebt());
        $deployableCash = max(0.0, (float) $stock->getCorporateTreasury() - $minOperatingCash);
        $internalCashFlow = $ctx->actualQuarterlyNetIncome + $ctx->quarterlyDepreciation - $deltaNwc - $maintenanceCapEx;
        $fundingCapacity = max(0.0, $internalCashFlow + $deployableCash);

        return min($plannedGrowthCapEx, $fundingCapacity);
    }

    private function executePriceAndVolatilityShocks(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;

        // Derive a composite earnings Z-score from the blended surprise percentage.
        $dispersion = $this->resolveSurpriseDispersion($ctx);
        $earningsSurpriseZ = $ctx->surprisePct / $dispersion;
        $this->applyVolatilityShock($stock, $earningsSurpriseZ, $ctx->baselineVol);
        $this->recordSurprise($stock, $ctx->surprisePct);

        $currentPrice = (float) $stock->getPrice();

        if ($ctx->actualAnnualEpsRaw > 0) {
            $currentPE = $currentPrice / $ctx->actualAnnualEpsRaw;
        } else {
            $annualSaarRevenue = MathUtility::calculateSeasonallyAdjustedAnnualRate($ctx->actualRevenue, $ctx->seasonalFactor, 4);
            $salesPerShare = $ctx->sharesOutstanding > 0 ? $annualSaarRevenue / $ctx->sharesOutstanding : 1.0;
            $priceToSales = $salesPerShare > 0 ? $currentPrice / $salesPerShare : 1.0;
            $structuralAfterTaxMargin = max(0.01, (float) $stock->getOperatingMargin() * (1.0 - $ctx->corporateTaxRate));
            $currentPE = $priceToSales * (1.0 / $structuralAfterTaxMargin);
        }

        $valuationPremium = max(self::VALUATION_PREMIUM_MIN, min(self::VALUATION_PREMIUM_MAX, $currentPE / FinancialConstants::BASELINE_MARKET_PE));
        $beta = (float) $stock->getBeta();

        // Growth premium proxy: valuationPremium - 1.0 (so 1.0 -> 0 growth premium, 2.0 -> +1.0 growth premium)
        $growthPremium = max(0.0, $valuationPremium - 1.0);

        // Calculate ERC-driven price gap with empirical market dampening.
        // Note: Raw surprisePct is intentionally passed to preserve calibrated PRICE_GAP_DAMPENING.
        $priceGapPct = $this->mathUtility->calculateEarningsResponseCoefficient(
            $ctx->surprisePct,
            $beta,
            $growthPremium
        );

        $dampedPriceGap = $priceGapPct * FinancialConstants::PRICE_GAP_DAMPENING;
        $ctx->priceGapPct = max(-FinancialConstants::MAX_PRICE_GAP, min(FinancialConstants::MAX_PRICE_GAP, $dampedPriceGap));
        $ctx->totalShockPct = $ctx->priceGapPct;
        $ctx->corporateActionDescriptions = "";

        $customEventLore = $ctx->eventType !== null ? $this->narrativeEngine->generateLore($ctx->eventType, $ctx->eventContext) : null;
        if ($customEventLore) {
            $ctx->corporateActionDescriptions .= "\n• " . $customEventLore;
        }

        if ($ctx->goodwillImpairment > 0.0) {
            $impairmentLore = $this->narrativeEngine->generateLore(\App\Service\Event\ShockEvent::GOODWILL_IMPAIRMENT, [
                'amount' => number_format($ctx->goodwillImpairment / 1_000_000_000, 2),
            ]);
            if ($impairmentLore) {
                $ctx->corporateActionDescriptions .= "\n• " . $impairmentLore;
            }
        }

        if (!empty($ctx->allocation['events'])) {
            foreach ($ctx->allocation['events'] as $subEvent) {
                if (isset($subEvent['event_type'])) {
                    $desc = $this->narrativeEngine->generateLore($subEvent['event_type'], $subEvent['context'] ?? []);
                } else {
                    $desc = $subEvent['description'] ?? '';
                }
                $ctx->corporateActionDescriptions .= "\n• " . $desc;
                $ctx->totalShockPct += ($subEvent['shock'] / 100.0);
            }
        }

        $ctx->totalShockPct = max(-FinancialConstants::MAX_QUARTERLY_PRICE_CIRCUIT_BREAKER, min(FinancialConstants::MAX_QUARTERLY_PRICE_CIRCUIT_BREAKER, $ctx->totalShockPct));

        $exDivPrice = ($currentPrice * (1.0 + $ctx->totalShockPct)) - $ctx->allocation['dividend_paid'];
        $newPrice = max(0.01, $exDivPrice);
        $stock->setPrice(number_format($newPrice, 8, '.', ''));
    }

    private function publishEventAndReport(EarningsSimulationContext $ctx): array
    {
        $stock = $ctx->stock;

        $ctx->wacc = $ctx->strategy->getHurdleRate($ctx->health);
        $capital = $ctx->strategy->getPhysicalCapital($stock);
        $ctx->quarterlyEconomicProfit = ($capital * ($ctx->truePostTaxReturn - $ctx->wacc)) / 4.0;

        $evaAbs = abs($ctx->quarterlyEconomicProfit);
        $formattedEva = $evaAbs >= 1_000_000_000
            ? '$' . number_format($evaAbs / 1_000_000_000, 2) . 'B'
            : '$' . number_format($evaAbs / 1_000_000, 2) . 'M';

        $evaString = $ctx->quarterlyEconomicProfit >= 0 ? "+{$formattedEva} EVA" : "-{$formattedEva} EVA";

        $formattedEps = $ctx->actualQuarterlyEps < 0 ? '-$' . number_format(abs($ctx->actualQuarterlyEps), 2) : '$' . number_format($ctx->actualQuarterlyEps, 2);
        $roundedSurprise = round(abs($ctx->surpriseAmountQuarterly), 2);
        $formattedSurprise = '$' . number_format($roundedSurprise, 2);

        if ($roundedSurprise >= 0.01 && $ctx->surpriseAmountQuarterly > 0.0) {
            $description = "Q-Earnings: {$formattedEps} (Beat expectations by {$formattedSurprise} | {$evaString}).";
        } elseif ($roundedSurprise >= 0.01 && $ctx->surpriseAmountQuarterly < 0.0) {
            $description = "Q-Earnings: {$formattedEps} (Missed expectations by {$formattedSurprise} | {$evaString}).";
        } else {
            $description = "Q-Earnings: {$formattedEps} (Met expectations exactly | {$evaString}).";
        }

        $description .= $ctx->corporateActionDescriptions;

        $earningsEvent = $this->marketEvent->publish($stock, 'EARNINGS', $description, $ctx->totalShockPct * 100);

        // Update previous revenue for next quarter's NWC calculation (annualized)
        $stock->setPreviousRevenue((string) ($ctx->actualRevenue * 4.0));

        $this->eventDispatcher->dispatch(new EarningsReportedEvent($ctx));

        return [$earningsEvent];
    }

    /**
     * Resolves the denominator that turns an earnings surprise into a standardized one (SUE).
     *
     * Unexpected earnings are standardized by the dispersion of the firm's OWN past unexpected earnings
     * (Foster, Olsen & Shevlin 1984), not by a static per-sector constant. The sector constants describe how
     * well analysts cover an industry; they say nothing about how large the surprises this engine actually
     * generates are, and the two had drifted apart badly. Measured over forty quarters the realized surprise
     * scale ran from 1.5x the assumed dispersion for a bank to 5.8x for an industrial, so the "sigma event"
     * threshold was tripped in 42% to 95% of quarters instead of the ~13% a true Z-score implies, and the
     * volatility shock that rides on it kept half the district permanently elevated.
     *
     * The sector's analyst dispersion remains a floor: it carries the coverage quality signal and scales with
     * market volatility, so forecasts still fan out in a panicked regime. Until the firm has enough reports
     * to estimate its own scale, that floor is all there is.
     */
    private function resolveSurpriseDispersion(EarningsSimulationContext $ctx): float
    {
        $analystDispersion = max(self::MIN_ESTIMATE_DISPERSION, $ctx->estimateDispersion);

        $history = $ctx->stock->getEarningsSurpriseHistory() ?? [];
        if (count($history) < self::SUE_MIN_HISTORY_QUARTERS) {
            return $analystDispersion;
        }

        $realizedScale = $this->mathUtility->calculateMeanAbsoluteScale(array_map('floatval', array_values($history)));

        return max($analystDispersion, $realizedScale);
    }

    /**
     * Appends this quarter's surprise to the rolling SUE sample, after it has been standardized against the
     * prior quarters. Standardizing a surprise partly by itself would shrink every outlier toward the mean.
     */
    private function recordSurprise(Stock $stock, float $surprisePct): void
    {
        $history = $stock->getEarningsSurpriseHistory() ?? [];
        $history[] = $surprisePct;

        $stock->setEarningsSurpriseHistory(
            array_values(array_map('floatval', array_slice($history, -self::SUE_HISTORY_QUARTERS)))
        );
    }

    private function applyVolatilityShock(Stock $stock, float $earningsZ, float $baselineVol): void
    {
        $currentVol = (float) $stock->getCurrentVolatility();
        $zScore = abs($earningsZ);

        if ($zScore > FinancialConstants::SURPRISE_Z_SCORE_THRESHOLD) {
            $shockFactor = $earningsZ < 0
                ? FinancialConstants::VOLATILITY_SHOCK_FACTOR * FinancialConstants::NEGATIVE_SURPRISE_VOL_MULTIPLIER
                : FinancialConstants::VOLATILITY_SHOCK_FACTOR;

            $shockMultiplier = 1.0 + (($zScore - 1.0) * $shockFactor);
            $newVol = min($currentVol * $shockMultiplier, $baselineVol * FinancialConstants::MAX_VOLATILITY_MULTIPLIER);
            $stock->setCurrentVolatility((string) $newVol);
        } elseif ($zScore < FinancialConstants::BORING_Z_SCORE_THRESHOLD && $currentVol > $baselineVol) {
            $newVol = $currentVol - (($currentVol - $baselineVol) * FinancialConstants::VOLATILITY_COOLING_FACTOR);
            $stock->setCurrentVolatility((string) max($newVol, $baselineVol));
        }
    }
}
