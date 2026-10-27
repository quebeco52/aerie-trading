<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\CreditRisk;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Model\ModelParam;
use App\Service\Model\ModelParameters;

/**
 * Earnings strategy for Multi-Industry Conglomerates & Industrial Holding Trusts.
 *
 * Financial Physics:
 * - Open Portfolio Architecture: a conglomerate is defined by WHICH businesses it owns, not by a fixed
 *   recipe, so a stream left at zero weight is not drawn, costs nothing and never appears in segment
 *   reporting. That is what lets a value holding company, a diversified multi-industrial and a serial
 *   acquirer share one class, and what lets a sub-model declare a portfolio this one has no stream for
 *   (MerchantHouseBusinessModel), exactly as the insurance models do.
 * - Tri-Stream Architecture:
 *   1. Industrial Manufacturing: heavy engineering, specialized chemicals and B2B components (pro-cyclical,
 *      exposed to the output gap and the industrial CapEx cycle).
 *   2. Defensive Staples & Infrastructure: inelastic household goods, civic utilities and infrastructure
 *      tollbooths (stable recurring cash flows with regulated/contractual escalators).
 *   3. Contrarian Float & Financial Investments: treasury cash, short sovereign paper, high-yield
 *      catastrophe bonds and value-investing dry powder. Decomposed into the four effects a real credit
 *      book experiences separately: **risk-free carry** on the cash leg, **mark-to-market repricing** as
 *      spreads gap wider (an immediate loss), **carry alpha** from deploying dry powder at an elevated
 *      spread level (a lagged, saturating gain), and **credit losses** as issuers default.
 * - Deals are NOT modelled here. AcquisitionEngine already runs conglomerate acquisitions end to
 *   end: hazard from ManagementProfile::acquisitionBias(), price from hubrisPremium(), funded against real
 *   leverage and cash tests, and announced as a CONGLOMERATE EXPANSION when it takes the group into a new
 *   industry. A revenue multiplier standing in for an
 *   acquisition here would be the same behaviour counted twice, which that engine has already been bitten
 *   by once (see its style_priced flag). What remains is the one-sided operating event: a subsidiary
 *   writedown, which is a restructuring charge rather than a deal.
 */
class ConglomerateBusinessModel extends StandardCorporateBusinessModel
{
    // --- Firm-Level Common Factor ---
    /**
     * One-factor loading of each stream on the firm-wide demand innovation (rho^2 = 12%). Unrelated subsidiaries share
     * management and brand but not customers, so a segment's quarter says little about its siblings.
     */
    public const FIRM_FACTOR_LOADING = 0.35;
    /**
     * Two-factor loading on the persistent demand factor of the holding company's reported sector (rho_s^2 = 6%). A
     * conglomerate spans sectors, so its own sector's cycle explains less of any one segment than it would for a
     * focused peer.
     */
    public const SECTOR_FACTOR_LOADING = 0.25;

    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Diversified
     * subsidiaries dampen the cycle.
     */
    public const OPERATING_CYCLICALITY = 0.90;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.50;
    /**
     * Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market.
     * "Conglomerates" is a listing convention, not a product market: a rail-and-insurance group and a staples group
     * sell nothing the other buys, so a group's gain is won in its segments' end markets, none of it from its listed
     * peers.
     */
    public const INDUSTRY_SUBSTITUTABILITY = 0.00;

    // --- Input Cost Basket ---
    /**
     * Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain
     * content (labor still the model's own).
     */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::CONGLOMERATE;

    // --- Price Pass-Through ---
    /** Regulated tollbooth escalators and staples list-price resets reprice about once a year. */
    public const PRICE_PASS_THROUGH_LAG_YEARS = 1.00;

    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for multi-industry conglomerates with complex multi-segment reporting. */
    public const BASE_COVERAGE_VISIBILITY = 0.35;

    /** Base coverage forecasting error given diversification across unlisted subsidiaries. */
    public const BASE_COVERAGE_ERROR = 0.05;

    // --- Portfolio Composition (Three-Stream Architecture) ---
    /** Baseline fraction of the plant's revenue from cyclical industrial manufacturing subsidiaries. */
    public const INDUSTRIAL_CONGLOMERATE_WEIGHT = 0.50;

    /** Baseline fraction of the plant's revenue from defensive consumer staples & infrastructure tollbooths. */
    public const DEFENSIVE_STAPLES_WEIGHT = 0.50;

    /** Baseline pricing power and monopoly leverage across conglomerate product lines. */
    public const PRICING_POWER_INDEX = 0.70;

    // --- Stream Volatility Scalars ---
    /** Volatility multiplier for cyclical industrial manufacturing throughput. */
    public const INDUSTRIAL_VARIANCE_SCALAR = 0.35;

    /** Volatility multiplier for defensive consumer staples and contracted infrastructure assets. */
    public const DEFENSIVE_VARIANCE_SCALAR = 0.08;

    /** Volatility multiplier for financial investment returns and mark-to-market float gains. */
    public const FLOAT_VARIANCE_SCALAR = 0.60;

    // --- Macro Physics ---
    /** Sensitivity of industrial manufacturing to broader GDP output gap expansion. */
    public const INDUSTRIAL_MACRO_SCALAR = 1.20;

    /**
     * Beta floor for cyclical stream sensitivity; a reported beta near zero is a data artifact, not true acyclicality.
     */
    public const MIN_CYCLICAL_BETA_FLOOR = 0.35;

    /** Residual output gap sensitivity of staples volume and tollbooth throughput (defensive, but not macro-immune). */
    public const DEFENSIVE_MACRO_SCALAR = 0.15;

    // --- Contrarian Float Deployment & Credit Book Physics ---
    /** Sensitivity multiplier translating economic contraction into contrarian buyout alpha. */
    public const FLOAT_RECESSION_ALPHA_SCALAR = 2.50;

    /** Sensitivity multiplier translating the sustained credit spread level into distressed float carry. */
    public const FLOAT_SPREAD_BLOWOUT_SCALAR = 12.00;

    /** Maximum asymptotic float revenue expansion from contrarian dry powder deployment. */
    public const MAX_CONTRARIAN_FLOAT_EXPANSION = 0.60;

    /** Distress intensity signal at which half the maximum contrarian float expansion is realized. */
    public const CONTRARIAN_HALF_SATURATION_POINT = 0.50;

    /**
     * Revenue elasticity of the float segment to the policy rate's gap from neutral. About 40% of the float is cash and
     * short sovereign paper earning the front rate; the denominator is the BOOK's blended yield, not the neutral policy
     * rate, because the other 60% is high-yield and catastrophe paper yielding well above it. Blending 40% at ~3.5%
     * with 60% at ~7% gives ~5.6%, so a 1pp gap moves segment revenue by 0.40 / 0.056 ~ 7%. Dry powder is not free
     * money in a ZIRP decade, but it is not a pure rates bet either.
     */
    public const FLOAT_RATE_CARRY_ELASTICITY = 7.00;

    /** Spread duration (years) of the float credit book: short corporates, preferreds, and cat bonds. */
    public const FLOAT_SPREAD_DURATION = 4.00;

    /**
     * Realized credit loss on float revenue per unit of the corporate default rate over its measured trend, relative,
     * both ways.
     */
    public const FLOAT_CREDIT_LOSS_SCALAR = 0.08;

    // --- Treasury Credit Book ---
    /**
     * Share of the cash above operating needs the float holds in credit (corporates, preferreds, catastrophe paper) in
     * calm markets (20%): a value holder sits mostly in bills until spreads blow out.
     */
    public const CREDIT_BOOK_STANDING_SHARE = 0.20;
    /** Share it holds there at the height of a distress episode (60%), as dry powder is put to work. */
    public const CREDIT_BOOK_MAX_SHARE = 0.60;
    /** Spread the credit book earns over the money market (3.5pp), on paper riskier than the market's average. */
    public const CREDIT_BOOK_SPREAD = 0.035;
    /** Loss given default on the book's senior and convertible paper (45%). */
    public const CREDIT_BOOK_LOSS_GIVEN_DEFAULT = 0.45;

    // --- Tail Risk & Shock Events ---
    /** Negative Z-score threshold indicating an operational bottleneck or subsidiary restructuring drag. */
    public const SUBSIDIARY_WRITEDOWN_Z_SCORE = -2.30;

    /** Variable cost penalty applied during multi-subsidiary restructuring or supply chain write-offs. */
    public const RESTRUCTURING_DRAG_PENALTY = 0.04;
    /** Share of the restructuring penalty a subsidiary with full pricing power passes on in price (half). */
    public const RESTRUCTURING_PRICING_PASS_THROUGH = 0.50;

    // --- Industrial & Deal Activity Sensitivities ---
    /** Sensitivity of industrial conglomerate subsidiary demand to manufacturing PMI. */
    public const PMI_INDUSTRIAL_SENSITIVITY = 0.40;
    /** Sensitivity of subsidiary divestiture proceeds and advisory alpha to capital markets deal activity. */
    public const DEAL_ACTIVITY_DIVESTITURE_SCALAR = 0.15;

    // --- Return Reversion ---
    /** Pull of the trailing return toward the required return plus the moat (0.1 a year). */
    public const ROIC_REVERSION_SPEED = 0.1;
    /** Excess return over the required return the moat sustains (1.5pp). */
    public const MOAT_SPREAD = 0.015;

    // --- Capital Cycle ---
    /**
     * Elasticity of the capital budget to the cycle signal (0.5). Blended across heavy industrial CapEx and asset-light
     * investment float.
     */
    public const CAPEX_CYCLICALITY = 0.50;
    /** Share of construction in progress completed into plant each quarter (15%). */
    public const CAPEX_COMPLETION_RATE = 0.15;

    // --- Seasonality ---
    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: industrial spring deliveries and year-end
     * shipments.
     */
    public const SEASONALITY_FACTORS = [0.96, 1.02, 1.00, 1.02];

    // --- Credit Thresholds ---
    /** Minimum interest coverage the firm is held to (2.5x). */
    public const MIN_ICR = 2.5;
    /** Interest coverage below which a firm without a cash buffer is in a dividend crisis (1.75x). */
    public const DIVIDEND_CRISIS_ICR = 1.75;
    /** Interest coverage below which the firm stops repurchasing stock (2.5x). */
    public const BUYBACK_MIN_ICR = 2.5;

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Nullify global demand shifts to handle cyclicality discretely per stream.
        $physics['macro_demand_shift'] = 0.0;

        // Conglomerate pricing power is real but lagged: regulated tollbooth escalators and staples list-price
        // resets reprice about once a year (PRICE_PASS_THROUGH_LAG_YEARS), which the parent's pass-through carries.

        return $physics;
    }

    /**
     * The volume the operating subsidiaries are staffed to: industrial throughput and staples/tollbooth
     * traffic at their target weights. The float earns on the treasury, which no plant or payroll is sized to.
     */
    public function resolveSectorActivityShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $params = $this->resolvePortfolioParameters($stock);
        $industrialWeight = $params[ModelParam::IndustrialConglomerateWeight];
        $defensiveWeight = $params[ModelParam::DefensiveStaplesWeight];
        $totalWeight = $industrialWeight + $defensiveWeight;
        if ($totalWeight <= 0.0) {
            return 0.0;
        }

        return (($industrialWeight * $this->resolveIndustrialVolumeShift($stock, $macroState))
            + ($defensiveWeight * $this->resolveDefensiveVolumeShift($macroState))) / $totalWeight;
    }

    private function resolvePortfolioParameters(Stock $stock): ModelParameters
    {
        return $this->resolveModelParameters($stock, [
            ModelParam::IndustrialConglomerateWeight->value => self::INDUSTRIAL_CONGLOMERATE_WEIGHT,
            ModelParam::DefensiveStaplesWeight->value       => self::DEFENSIVE_STAPLES_WEIGHT,
        ]);
    }

    /** Industrial throughput: the output gap and manufacturing PMI, both at the firm's floored cyclical beta. */
    private function resolveIndustrialVolumeShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $beta = max(self::MIN_CYCLICAL_BETA_FLOOR, $this->getOperatingCyclicality($stock));
        $pmiShift = MacroTransmission::calculatePmiDemandShift(
            $macroState->manufacturingPmiEma,
            MacroConstants::PMI_BASELINE,
            self::PMI_INDUSTRIAL_SENSITIVITY,
        );

        return ($macroState->outputGapEma * self::INDUSTRIAL_MACRO_SCALAR * $beta) + ($pmiShift * $beta);
    }

    /**
     * Staples volume and tollbooth throughput: defensive, but not macro-immune (industrial load, toll traffic).
     * Protected because a merchant house's terminal estate is this stream unchanged.
     */
    protected function resolveDefensiveVolumeShift(MacroStateDTO $macroState): float
    {
        return $macroState->outputGapEma * self::DEFENSIVE_MACRO_SCALAR;
    }

    protected function calculateSectorPhysics(
        Stock $stock,
        float $expectedRevenue,
        float $realizedVariableMargin,
        float $fixedCosts,
        float $baselineVol,
        MacroStateDTO $macroState,
        MathUtility $mathUtility,
    ): SectorPhysicsResult {
        $params = $this->resolvePortfolioParameters($stock);

        $pricingPower = $this->resolvePricingPower($stock);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        // A stream the firm does not own stays at exactly zero through the simplex, so the portfolio a
        // conglomerate declares is the portfolio it is priced on.
        // The plant earns the operating subsidiaries' sales; the float is the treasury's, earned on the cash itself
        // (calculateInterestIncome()).
        $activeWeights = $streams->resolveActiveStreamWeights([
            'industrial_manufacturing' => $params[ModelParam::IndustrialConglomerateWeight],
            'defensive_staples'        => $params[ModelParam::DefensiveStaplesWeight],
        ]);

        $industrialWeight = $activeWeights['industrial_manufacturing'];
        $defensiveWeight  = $activeWeights['defensive_staples'];

        // Independent stream Z-scores with persistent AR(1) momentum. A dormant stream is never drawn, so
        // adding one to the architecture leaves every firm that does not run it bit-for-bit unchanged.
        $industrialZ = $industrialWeight > 0.0 ? $streams->generateZ('industrial_manufacturing', 0.25) : 0.0;
        $defensiveZ  = $defensiveWeight > 0.0 ? $streams->generateZ('defensive_staples', 0.45) : 0.0;
        $eventZ      = $streams->generateExogenousZ('event', 0.10);

        // --- Macro Sensitivities ---
        $industrialMacroBoost = $this->resolveIndustrialVolumeShift($stock, $macroState);
        $defensiveMacroBoost = $this->resolveDefensiveVolumeShift($macroState);

        // --- Tail Risk & Event Physics ---
        $eventType = null;
        $restructuringPenalty = 0.0;

        if ($eventZ < self::SUBSIDIARY_WRITEDOWN_Z_SCORE) {
            $restructuringPenalty = self::RESTRUCTURING_DRAG_PENALTY * (1.0 - ($pricingPower * self::RESTRUCTURING_PRICING_PASS_THROUGH));
            $eventType = ShockEvent::CONGLOMERATE_SUBSIDIARY_WRITEDOWN;
        }

        // --- Three-Stream Revenue Calculation ---
        $industrialShock = $industrialZ * ($baselineVol * self::INDUSTRIAL_VARIANCE_SCALAR);
        $defensiveShock  = $defensiveZ  * ($baselineVol * self::DEFENSIVE_VARIANCE_SCALAR);

        $streamRevenues = [];
        if ($industrialWeight > 0.0) {
            $streamRevenues['industrial_manufacturing'] = max(0.0, $expectedRevenue * $industrialWeight * (1.0 + $industrialShock + $industrialMacroBoost));
        }
        if ($defensiveWeight > 0.0) {
            $streamRevenues['defensive_staples'] = max(0.0, $expectedRevenue * $defensiveWeight * (1.0 + $defensiveShock + $defensiveMacroBoost));
        }

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // Input costs across the operating subsidiaries, recovered in list prices and escalators at the
        // group's pricing power.
        $ppiCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);

        // Realized variable margin scaling
        $rawMargin = $realizedVariableMargin
            + $ppiCostDrag;
        $clampedMargin = $this->clampMargin($rawMargin);

        $specialCharge = $restructuringPenalty * $actualRevenue;

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            specialCharge: $specialCharge,
        );
    }

    /**
     * The float is the treasury's: the cash above operating needs earns the money market, and the share of it held in
     * credit earns that book's spread less its losses on top. Earned on the cash the company holds, not on its plant.
     */
    public function calculateInterestIncome(Stock $stock, MacroStateDTO $macroState, ?float $realizedWholesaleRate = null): float
    {
        return parent::calculateInterestIncome($stock, $macroState, $realizedWholesaleRate) + $this->resolveCreditBookIncome($stock, $macroState);
    }

    /**
     * The credit book's annual income over the money market: the cash above operating needs, the share of it held in
     * credit, which rises from the standing share toward the most it deploys as spreads blow out, the economy turns
     * down and sellers line up (the contrarian signal), times the book's spread less its losses. Its paper is as much
     * riskier than the market's as its spread is wider, so it loses that multiple of the market's defaults, at its loss
     * given default.
     */
    protected function resolveCreditBookIncome(Stock $stock, MacroStateDTO $macroState): float
    {
        $operatingBase = max((float) $stock->getTotalRevenue(), (float) $stock->getTotalEquity(), FinancialConstants::MIN_OPERATING_BASE_CASH);
        $excessCash = max(0.0, (float) $stock->getCorporateTreasury() - $this->calculateTargetOperatingCash($operatingBase, 0.0, (float) $stock->getWholesaleDebt()));
        if ($excessCash <= 0.0) {
            return 0.0;
        }

        $signal = (max(0.0, $macroState->macroCreditSpreadEma - $macroState->macroCreditSpreadTrend) * self::FLOAT_SPREAD_BLOWOUT_SCALAR)
            + (max(0.0, -$macroState->outputGapEma) * self::FLOAT_RECESSION_ALPHA_SCALAR)
            + (max(0.0, $macroState->dealActivityDeviation()) * self::DEAL_ACTIVITY_DIVESTITURE_SCALAR);
        $deployed = self::CREDIT_BOOK_STANDING_SHARE + ((self::CREDIT_BOOK_MAX_SHARE - self::CREDIT_BOOK_STANDING_SHARE)
            * CreditRisk::calculateDiminishingDistressMultiplier($signal, 1.0, self::CONTRARIAN_HALF_SATURATION_POINT));
        // The spread pays for the losses at the long-run default rate; defaults above or below their measured trend
        // are the news, and hit this riskier book harder than the market's.
        $riskMultiple = self::CREDIT_BOOK_SPREAD / MacroConstants::CREDIT_SPREAD_LONG_RUN_MEAN;
        $loss = $riskMultiple * self::CREDIT_BOOK_LOSS_GIVEN_DEFAULT
            * (MacroConstants::CORPORATE_DEFAULT_LONG_RUN_MEAN + $macroState->corporateDefaultRateEma - $macroState->corporateDefaultRateTrend);

        return $excessCash * $deployed * (self::CREDIT_BOOK_SPREAD - $loss);
    }

    /**
     * Contrarian Float: risk-free carry, mark-to-market, deployment alpha and credit losses.
     *
     * The four are separate effects on one book and they do not arrive together. The cash leg earns the
     * front rate whatever credit is doing; spreads gapping wider reprice the held book down this quarter;
     * dry powder committed at the new level earns an elevated premium over the following ones; and the
     * spread was compensating for defaults all along, so the losses land last. Widening hurts now; the
     * plateau pays later; and none of it happens at all if the policy rate is on the floor.
     *
     * Protected because the sub-models book it as a revenue line on a balance of their own: a merchant house's trade
     * credit inside its working capital, a trust's treasury sized from its balance sheet.
     */
    protected function resolveContrarianFloatSurge(
        MacroStateDTO $macroState,
        float $outputGap,
        float $spreadLevel,
        float $spreadImpulse,
        float $divestitureAlpha,
    ): float {
        // Risk-free carry: cash and short sovereign paper earn the front rate, measured against the neutral
        // rate the economy itself perceives. A float is an asset at 5% and an idle drag at zero.
        $rateCarry = ($macroState->policyRateEma - $macroState->perceivedNeutralRate) * self::FLOAT_RATE_CARRY_ELASTICITY;

        // Deployment alpha: dry powder committed at a distressed spread level earns an elevated risk premium,
        // saturating as the opportunity set outgrows the balance sheet available to fund it.
        $recessionDepth = max(0.0, -$outputGap);
        $excessSpread   = max(0.0, $spreadLevel - $macroState->macroCreditSpreadTrend);

        $rawDeploymentSignal = ($excessSpread * self::FLOAT_SPREAD_BLOWOUT_SCALAR)
            + ($recessionDepth * self::FLOAT_RECESSION_ALPHA_SCALAR)
            + $divestitureAlpha;

        $deploymentAlpha = CreditRisk::calculateDiminishingDistressMultiplier(
            $rawDeploymentSignal,
            self::MAX_CONTRARIAN_FLOAT_EXPANSION,
            self::CONTRARIAN_HALF_SATURATION_POINT,
        );

        // Credit losses: spreads compensate for defaults, they are not free yield. Net carry = spread - expected loss.
        $creditLossDrag = $macroState->corporateDefaultDeviation() * self::FLOAT_CREDIT_LOSS_SCALAR;

        // Mark-to-market: spreads gapping wider reprice the held book downward this quarter (dP/P ~ -D_s * ds).
        $floatMarkToMarket = CreditRisk::calculateCreditSpreadMarkToMarket($spreadImpulse, self::FLOAT_SPREAD_DURATION);

        return $rateCarry + $deploymentAlpha - $creditLossDrag + $floatMarkToMarket;
    }

    /**
     * MacroStateDTO fields (snake_case) this model's operating physics genuinely reads in
     * calculateSectorPhysics()/getMacroPhysics() — see OperatingStrategyInterface for the full rule.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array
    {
        return [
            'corporate_default_rate_ema',
            'deal_activity_index_ema',
            'energy_cost_push_lag',
            'exchange_rate_index_ema',
            'import_price_level',
            'industrial_metals_index_ema',
            'macro_credit_spread_ema',
            'manufacturing_pmi_ema',
            'output_gap_ema',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}
