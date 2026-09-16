<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;

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
 * - Deals are NOT modelled here. MergerAndAcquisitionEngine already runs conglomerate acquisitions end to
 *   end: hazard from ManagementProfile::acquisitionBias(), price from hubrisPremium(), funded against real
 *   leverage and cash tests and booked as a CONGLOMERATE EXPANSION. A revenue multiplier standing in for an
 *   acquisition here would be the same behaviour counted twice, which that engine has already been bitten
 *   by once (see its style_priced flag). What remains is the one-sided operating event: a subsidiary
 *   writedown, which is a restructuring charge rather than a deal.
 */
class ConglomerateBusinessModel extends StandardCorporateBusinessModel
{
    // --- Firm-Level Common Factor ---
    /** One-factor loading of each stream on the firm-wide demand innovation (rho^2 = 12%). Unrelated subsidiaries share management and brand but not customers, so a segment's quarter says little about its siblings. */
    public const FIRM_FACTOR_LOADING = 0.35;
    /** Two-factor loading on the persistent demand factor of the holding company's reported sector (rho_s^2 = 6%). A conglomerate spans sectors, so its own sector's cycle explains less of any one segment than it would for a focused peer. */
    public const SECTOR_FACTOR_LOADING = 0.25;

    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Diversified subsidiaries dampen the cycle. */
    public const OPERATING_CYCLICALITY = 0.90;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.50;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. "Conglomerates" is a listing convention, not a product market, so a group's gain is mostly won in the end markets its segments actually serve. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.40;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base bought in tracked input markets (energy, metals, agri, freight, wholesale goods, variable payroll). */
    public const INPUT_COST_EXPOSURES = ['ppi' => 0.20, 'energy' => 0.06, 'metals' => 0.08, 'labor' => 0.25];

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. A blend across unrelated subsidiaries lands near the all-industry middle by construction. */
    public const FIXED_COST_LABOR_SHARE = 0.55;
    /** Regulated tollbooth escalators and staples list-price resets reprice about once a year. */
    public const PRICE_PASS_THROUGH_LAG_YEARS = 1.00;

    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: industrial spring deliveries and year-end shipments.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [0.96, 1.02, 1.00, 1.02];
    }

    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for multi-industry conglomerates with complex multi-segment reporting. */
    public const BASE_COVERAGE_VISIBILITY = 0.35;

    /** Base coverage forecasting error given diversification across unlisted subsidiaries. */
    public const BASE_COVERAGE_ERROR = 0.05;

    // --- Portfolio Composition (Five-Stream Architecture) ---
    /** Baseline fraction of revenue derived from cyclical industrial manufacturing subsidiaries. */
    public const INDUSTRIAL_CONGLOMERATE_WEIGHT = 0.40;

    /** Baseline fraction of revenue derived from defensive consumer staples & infrastructure tollbooths. */
    public const DEFENSIVE_STAPLES_WEIGHT = 0.40;

    /** Baseline fraction of revenue derived from financial float, investments, and contrarian dry powder. */
    public const CONTRARIAN_FLOAT_WEIGHT = 0.20;

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

    /** Beta floor for cyclical stream sensitivity; a reported beta near zero is a data artifact, not true acyclicality. */
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

    /** Revenue elasticity of the float segment to the policy rate's gap from neutral. About 40% of a holding company's float is cash and short sovereign paper earning the front rate against a ~3.5% neutral yield, so a 1pp gap moves segment revenue by 0.40 / 0.035 ~ 12%. Dry powder is not free money in a ZIRP decade. */
    public const FLOAT_RATE_CARRY_ELASTICITY = 12.00;

    /** Spread duration (years) of the float credit book: short corporates, preferreds, and cat bonds. */
    public const FLOAT_SPREAD_DURATION = 4.00;

    /** Realized credit loss drag on float revenue per unit of relative corporate default rate excess. */
    public const FLOAT_CREDIT_LOSS_SCALAR = 0.08;

    // --- Tail Risk & Shock Events ---
    /** Negative Z-score threshold indicating an operational bottleneck or subsidiary restructuring drag. */
    public const SUBSIDIARY_WRITEDOWN_Z_SCORE = -2.30;

    /** Variable cost penalty applied during multi-subsidiary restructuring or supply chain write-offs. */
    public const RESTRUCTURING_DRAG_PENALTY = 0.04;

    // --- Industrial & Deal Activity Sensitivities ---
    /** Sensitivity of industrial conglomerate subsidiary demand to manufacturing PMI. */
    public const PMI_INDUSTRIAL_SENSITIVITY = 0.40;
    /** Sensitivity of subsidiary divestiture proceeds and advisory alpha to capital markets deal activity. */
    public const DEAL_ACTIVITY_DIVESTITURE_SCALAR = 0.15;

    // --- Analyst Observability ---
    /** Fraction of float mark-to-market noise visible to public consensus; segment reporting obscures the rest. */
    public const FLOAT_OBSERVABLE_DISCOUNT = 0.40;
    /** Fraction of contrarian deployment alpha visible to public consensus ahead of the filing. */
    public const CONTRARIAN_OBSERVABLE_DISCOUNT = 0.50;

    public function getMinIcr(): float { return 2.5; }
    public function getWholesaleLeverageLimit(): float { return 2.0; }
    public function getDividendCrisisIcr(): float { return 1.75; }
    public function getBuybackMinIcr(): float { return 2.5; }
    public function getReversionSpeed(): float { return 0.1; }
    public function getMoatSpread(): float { return 0.015; }
    public function getCapExCompletionRate(Stock $stock): float { return 0.15; }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.02; // Stable mature holding company growth
    }

    public function getCapexCyclicality(): float
    {
        return 0.50; // Blended across heavy industrial CapEx and asset-light investment float
    }

    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.55, 'revenue_weight' => 0.45];
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Nullify global demand shifts to handle cyclicality discretely per stream.
        $physics['macro_demand_shift'] = 0.0;

        // Conglomerate pricing power is real but lagged: regulated tollbooth escalators and staples list-price
        // resets reprice about once a year (PRICE_PASS_THROUGH_LAG_YEARS), which the parent's pass-through carries.

        return $physics;
    }

    protected function calculateSectorPhysics(
        Stock $stock,
        float $expectedRevenue,
        float $realizedVariableMargin,
        float $fixedCosts,
        float $baselineVol,
        MacroStateDTO $macroState,
        MathUtility $mathUtility
    ): SectorPhysicsResult {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::IndustrialConglomerateWeight->value => self::INDUSTRIAL_CONGLOMERATE_WEIGHT,
            ModelParam::DefensiveStaplesWeight->value       => self::DEFENSIVE_STAPLES_WEIGHT,
            ModelParam::ContrarianFloatWeight->value        => self::CONTRARIAN_FLOAT_WEIGHT,
            ModelParam::PricingPowerIndex->value            => self::PRICING_POWER_INDEX,
        ]);

        $pricingPower = max(0.0, min(1.0, $params[ModelParam::PricingPowerIndex]));

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $beta = max(self::MIN_CYCLICAL_BETA_FLOOR, $this->getOperatingCyclicality($stock));

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        // A stream the firm does not own stays at exactly zero through the simplex, so the portfolio a
        // conglomerate declares is the portfolio it is priced on.
        $activeWeights = $streams->resolveActiveStreamWeights([
            'industrial_manufacturing' => $params[ModelParam::IndustrialConglomerateWeight],
            'defensive_staples'        => $params[ModelParam::DefensiveStaplesWeight],
            'financial_investments'    => $params[ModelParam::ContrarianFloatWeight],
        ]);

        $industrialWeight = $activeWeights['industrial_manufacturing'];
        $defensiveWeight  = $activeWeights['defensive_staples'];
        $floatWeight      = $activeWeights['financial_investments'];

        // Independent stream Z-scores with persistent AR(1) momentum. A dormant stream is never drawn, so
        // adding one to the architecture leaves every firm that does not run it bit-for-bit unchanged.
        $industrialZ = $industrialWeight > 0.0 ? $streams->generateZ('industrial_manufacturing', 0.25) : 0.0;
        $defensiveZ  = $defensiveWeight > 0.0 ? $streams->generateZ('defensive_staples', 0.45) : 0.0;
        $floatZ      = $floatWeight > 0.0 ? $streams->generateZ('financial_investments', 0.15) : 0.0;
        $eventZ      = $streams->generateExogenousZ('event', 0.10);

        // --- Macro Sensitivities ---
        $outputGap = $macroState->outputGapEma;

        // The sustained spread level drives carry; the gap between spot and trend is the widening impulse.
        $spreadLevel   = $macroState->macroCreditSpreadEma;
        $spreadImpulse = $macroState->macroCreditSpread - $macroState->macroCreditSpreadEma;

        // Industrial manufacturing is pro-cyclical with GDP output gap and manufacturing PMI
        $pmiShift = MathUtility::calculatePmiDemandShift(
            $macroState->manufacturingPmiEma,
            MacroEngine::PMI_BASELINE,
            self::PMI_INDUSTRIAL_SENSITIVITY
        );
        $industrialMacroBoost = ($outputGap * self::INDUSTRIAL_MACRO_SCALAR * $beta) + ($pmiShift * $beta);

        // Staples volume and tollbooth throughput are defensive but not macro-immune (industrial load, toll traffic).
        $defensiveMacroBoost = $outputGap * self::DEFENSIVE_MACRO_SCALAR;

        // Capital markets deal activity expands strategic acquisition & divestiture opportunities
        $dealActivityShift = ($macroState->dealActivityIndexEma - MacroEngine::DEAL_ACTIVITY_BASELINE) / MacroEngine::DEAL_ACTIVITY_BASELINE;
        $divestitureAlpha = max(0.0, $dealActivityShift) * self::DEAL_ACTIVITY_DIVESTITURE_SCALAR;

        $contrarianSurge = $this->resolveContrarianFloatSurge($macroState, $mathUtility, $outputGap, $spreadLevel, $spreadImpulse, $divestitureAlpha);

        // --- Tail Risk & Event Physics ---
        $eventType = null;
        $restructuringPenalty = 0.0;

        if ($eventZ < self::SUBSIDIARY_WRITEDOWN_Z_SCORE) {
            $restructuringPenalty = self::RESTRUCTURING_DRAG_PENALTY * (1.0 - ($pricingPower * 0.50));
            $eventType = ShockEvent::CONGLOMERATE_SUBSIDIARY_WRITEDOWN;
        }

        // --- Five-Stream Revenue Calculation ---
        $industrialShock = $industrialZ * ($baselineVol * self::INDUSTRIAL_VARIANCE_SCALAR);
        $defensiveShock  = $defensiveZ  * ($baselineVol * self::DEFENSIVE_VARIANCE_SCALAR);
        $floatShock      = $floatZ      * ($baselineVol * self::FLOAT_VARIANCE_SCALAR);

        $streamRevenues = [];
        if ($industrialWeight > 0.0) {
            $streamRevenues['industrial_manufacturing'] = max(0.0, $expectedRevenue * $industrialWeight * (1.0 + $industrialShock + $industrialMacroBoost));
        }
        if ($defensiveWeight > 0.0) {
            $streamRevenues['defensive_staples'] = max(0.0, $expectedRevenue * $defensiveWeight * (1.0 + $defensiveShock + $defensiveMacroBoost));
        }
        if ($floatWeight > 0.0) {
            $streamRevenues['financial_investments'] = max(0.0, $expectedRevenue * $floatWeight * (1.0 + $floatShock + $contrarianSurge));
        }

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // Realized shares, not target weights: the group's cost base is the revenue-weighted blend of its
        // segments' cost bases, so a desk having a spectacular quarter also dilutes the group's margin.
        $floatShare = $actualRevenue > 0.0 ? ($streamRevenues['financial_investments'] ?? 0.0) / $actualRevenue : 0.0;

        // Input costs across the operating subsidiaries, recovered in list prices and escalators at the
        // group's pricing power. The float stream buys no inputs.
        $ppiCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin)
            * max(0.0, 1.0 - $floatShare);

        // Realized variable margin scaling
        $rawMargin = $realizedVariableMargin
            + $restructuringPenalty
            + $ppiCostDrag;
        $clampedMargin = $this->clampMargin($rawMargin);

        $primaryShockZ = $streams->resolveDominantShockZ([$industrialZ, $defensiveZ, $floatZ], $eventZ);

        // Blended observable shock
        $observableShockZ = ($industrialShock * $industrialWeight) +
            ($defensiveShock * $defensiveWeight) +
            ($floatShock * $floatWeight * self::FLOAT_OBSERVABLE_DISCOUNT) +
            ($industrialMacroBoost * $industrialWeight) +
            ($defensiveMacroBoost * $defensiveWeight) +
            ($contrarianSurge * $floatWeight * self::CONTRARIAN_OBSERVABLE_DISCOUNT);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
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
     * Protected because a sub-model with a different operating portfolio still runs the same treasury book:
     * a merchant house's trade-credit ledger is this stream, not a variant of it.
     */
    protected function resolveContrarianFloatSurge(
        MacroStateDTO $macroState,
        MathUtility $mathUtility,
        float $outputGap,
        float $spreadLevel,
        float $spreadImpulse,
        float $divestitureAlpha
    ): float {
        // Risk-free carry: cash and short sovereign paper earn the front rate, measured against the neutral
        // rate the economy itself perceives. A float is an asset at 5% and an idle drag at zero.
        $rateCarry = ($macroState->policyRateEma - $macroState->perceivedNeutralRate) * self::FLOAT_RATE_CARRY_ELASTICITY;

        // Deployment alpha: dry powder committed at a distressed spread level earns an elevated risk premium,
        // saturating as the opportunity set outgrows the balance sheet available to fund it.
        $recessionDepth = max(0.0, -$outputGap);
        $excessSpread   = max(0.0, $spreadLevel - MacroEngine::BASE_CREDIT_SPREAD);

        $rawDeploymentSignal = ($excessSpread * self::FLOAT_SPREAD_BLOWOUT_SCALAR)
            + ($recessionDepth * self::FLOAT_RECESSION_ALPHA_SCALAR)
            + $divestitureAlpha;

        $deploymentAlpha = $mathUtility->calculateDiminishingDistressMultiplier(
            $rawDeploymentSignal,
            self::MAX_CONTRARIAN_FLOAT_EXPANSION,
            self::CONTRARIAN_HALF_SATURATION_POINT
        );

        // Credit losses: spreads compensate for defaults, they are not free yield. Net carry = spread - expected loss.
        $corporateDefaultShift = MathUtility::excessOverBaseline($macroState->corporateDefaultRateEma, MacroEngine::CORPORATE_DEFAULT_BASELINE);
        $creditLossDrag = $corporateDefaultShift * self::FLOAT_CREDIT_LOSS_SCALAR;

        // Mark-to-market: spreads gapping wider reprice the held book downward this quarter (dP/P ~ -D_s * ds).
        $floatMarkToMarket = MathUtility::calculateCreditSpreadMarkToMarket($spreadImpulse, self::FLOAT_SPREAD_DURATION);

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
            'industrial_metals_index_ema',
            'macro_credit_spread',
            'macro_credit_spread_ema',
            'manufacturing_pmi_ema',
            'output_gap_ema',
            'perceived_neutral_rate',
            'policy_rate_ema',
            'producer_price_inflation_ema',
            'tips_breakeven_ema',
            'wage_growth_ema',
        ];
    }
}
