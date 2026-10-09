<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\Service\Math\MacroTransmission;
use App\Service\Model\BusinessModelInterface;

use App\Service\Model\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\DTO\MacroStateDTO;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;

/**
 * Earnings strategy for Security, Defense & Private Military Contractors (PMCs).
 * 
 * Financial Physics:
 * - Tri-Stream Engine:
 *      1. Government Contracts: Rock-solid, sovereign cost-plus revenue (0% GDP sensitivity, ultra-low variance).
 *      2. Corporate Retainers: Highly defensive, non-negotiable security contracts (mild GDP expansion sensitivity).
 *      3. Expeditionary / Black-Ops: Counter-cyclical, high-margin conflict & extraction services (VIX & Credit Spread driven).
 * - Macro Differentiators:
 *      - Government Stream -> Pulls from Inflation (Cost-Plus escalators).
 *      - Corporate Stream -> Pulls from Output Gap (Corporate HQ expansion).
 *      - Expeditionary Stream -> Pulls from VIX & Credit Spreads (Panic & Geopolitical Stress).
 * - Tail Risk: A high-profile tactical failure or assassination causes immediate contract cancellations and legal fallout.
 */
class SecurityProtectionBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Guarding contracts are a survival expense. */
    public const OPERATING_CYCLICALITY = 0.60;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.40;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.50;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain content (labor still the model's own). */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::SECURITY_PROTECTION;
    /** Cost-plus government work and annual retainer resets recover guard wage moves almost in full. */
    public const PRICING_POWER_INDEX = 0.85;

    // --- Services Pricing ---
    /** Elasticity of fee and rate pricing to services (supercore) inflation. Contract guard rates pass through services wage inflation. */
    public const PRICING_ELASTICITY = 0.85;
    /** Services price off core services inflation ex-housing, not goods breakevens. */
    public const PRICING_INFLATION_BASIS = 'supercore_inflation_ema';

    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: summer event and site staffing peak.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [0.98, 1.00, 1.02, 1.00];
    }

    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for security and private military contractors. */
    public const BASE_COVERAGE_VISIBILITY = 0.20;
    /** Base analyst forecasting error scalar for obfuscated security operations. */
    public const BASE_COVERAGE_ERROR = 0.10;

        public function getWholesaleLeverageLimit(): float { return 1.5; }
    public function getReversionSpeed(): float { return 0.15; }
    public function getMoatSpread(): float { return 0.025; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return 0.05; }
    public function getCapExCompletionRate(Stock $stock): float { return 0.2; }

    // --- Secular Demand ---
    /** Administrative and support services value added as a share of US nominal GDP in 1997 (BEA GDP by Industry, value added). */
    public const SECULAR_SHARE_1997 = 0.0226;
    /** The same share in 2019. */
    public const SECULAR_SHARE_2019 = 0.0284;

    /** Trend real growth plus the sector's measured drift in its share of GDP. */
    public function getSecularGrowthRate(Stock $stock): float
    {
        return MacroEngine::TREND_REAL_GROWTH
            + MacroTransmission::gdpShareDrift(self::SECULAR_SHARE_1997, self::SECULAR_SHARE_2019, FinancialConstants::SECULAR_SHARE_WINDOW_YEARS);
    }
    public function getCapexCyclicality(): float
    {
        return 0.8;
    } // Fleet, armor, and tactical hardware
    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.60, 'revenue_weight' => 0.40];
    }

    // --- Tri-Stream Architecture Weights ---
    /** Baseline fraction of revenue from rock-solid sovereign government defense contracts. */
    public const GOVERNMENT_CONTRACT_WEIGHT = 0.40;
    /** Baseline fraction of revenue from sticky corporate campus and municipal security retainers. */
    public const RETAINER_WEIGHT            = 0.40;
    /** Baseline fraction of revenue from foreign expeditionary, black-label extraction, and conflict logistics. */
    public const EXPEDITIONARY_WEIGHT        = 0.20;

    // --- Stream Variance Scalars ---
    /** Variance scalar for highly stable sovereign government contracts. */
    public const GOVERNMENT_VARIANCE_SCALAR  = 0.02;
    /** Variance scalar for corporate security retainers. */
    public const RETAINER_VARIANCE_SCALAR    = 0.05;
    /** Variance scalar for volatile, conflict-driven expeditionary contracts. */
    public const EXPEDITIONARY_VARIANCE_SCALAR = 0.60;

    // --- Macro Physics Constants ---
    /** Cost-Plus Inflation Bonus: Government contracts guarantee profit margins on top of material/wage inflation. */
    public const COST_PLUS_BONUS_SCALAR = 1.20;
    /** Elasticity of government guarding volume to the state spending index (0.30 = a 10% budget lift adds 3% contracted posts). */
    public const APPROPRIATIONS_VOLUME_SCALAR = 0.30;
    /** Elasticity of corporate retainer posts to a positive output gap at unit cyclicality (new facilities need guards). */
    public const CORPORATE_EXPANSION_SCALAR = 0.40;

    /** Baseline VIX threshold above which corporate panic triggers surge spending on executive protection. */
    public const VIX_FEAR_THRESHOLD = 0.20;
    /** Sensitivity scalar translating market panic (VIX) into direct top-line expeditionary bonuses. */
    public const FEAR_PREMIUM_SCALAR = 0.80;

    /** Credit spread threshold indicating geopolitical/systemic credit stress. */
    public const CREDIT_STRESS_THRESHOLD = 0.025;
    /** Sensitivity scalar translating systemic credit distress into high-margin PMC deployment demand. */
    public const CREDIT_STRESS_SCALAR = 4.00;

    // --- Asymmetric Tail Risk Events ---
    /** Z-score threshold indicating a catastrophic, highly publicized tactical failure or VIP assassination. */
    public const TACTICAL_FAILURE_Z_SCORE = -2.50;
    /** Revenue haircut applied due to immediate contract terminations following a security breach. */
    public const TACTICAL_FAILURE_REV_MULT = 0.85;
    /** Variable cost penalty applied to fund legal settlements, fines, and crisis PR. */
    public const TACTICAL_FAILURE_PENALTY = 0.12;

    /** Z-score threshold indicating a massive sovereign collapse or supply chain conflict. */
    public const GEOPOLITICAL_CONFLICT_Z_SCORE = 2.40;
    /** Revenue multiplier applied to the expeditionary stream during active geopolitical conflicts. */
    public const GEOPOLITICAL_CONFLICT_MULT = 1.40;

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Security is a survival expense. We nullify the global demand shift to prevent double-dipping,
        // allowing each stream to pull directly from its assigned macro variables in calculateSectorPhysics.
        $physics['macro_demand_shift'] = 0.0;


        return $physics;
    }

    /**
     * The guard posts and deployments the firm is staffed to cover: the target-mix weighted macro shift of each
     * stream's volume. The cost-plus inflation escalator on government work is a price and stays out; the
     * expeditionary surge on fear and credit stress is counter-cyclical and keeps its sign.
     */
    public function resolveSectorActivityShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::GovernmentContractWeight->value => self::GOVERNMENT_CONTRACT_WEIGHT,
            ModelParam::RetainerWeight->value           => self::RETAINER_WEIGHT,
            ModelParam::ExpeditionaryWeight->value      => self::EXPEDITIONARY_WEIGHT,
        ]);
        $weights = [
            'government_contracts' => $params[ModelParam::GovernmentContractWeight],
            'corporate_retainers'  => $params[ModelParam::RetainerWeight],
            'expeditionary_ops'    => $params[ModelParam::ExpeditionaryWeight],
        ];
        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0.0) {
            return 0.0;
        }

        $shifts = $this->resolveStreamMacroShifts($stock, $macroState);
        $activity = 0.0;
        foreach ($weights as $key => $weight) {
            $activity += $weight * $shifts[$key];
        }

        return $activity / $totalWeight;
    }

    /**
     * Each stream's macro volume shift: government posts on state appropriations; corporate retainers on the
     * boom-side gap only (guarding is a survival expense nobody cuts); expeditionary deployments on market
     * volatility and credit spreads above their stress thresholds.
     *
     * @return array{government_contracts: float, corporate_retainers: float, expeditionary_ops: float}
     */
    private function resolveStreamMacroShifts(Stock $stock, MacroStateDTO $macroState): array
    {
        $govSpendShift = ($macroState->governmentSpendingIndexEma - 100.0) / 100.0;
        $fearPremium = max(0.0, ($macroState->marketVolatilityEma - self::VIX_FEAR_THRESHOLD) * self::FEAR_PREMIUM_SCALAR);
        $creditDistressPremium = max(0.0, ($macroState->macroCreditSpreadEma - self::CREDIT_STRESS_THRESHOLD) * self::CREDIT_STRESS_SCALAR);

        return [
            'government_contracts' => $govSpendShift * self::APPROPRIATIONS_VOLUME_SCALAR,
            'corporate_retainers'  => max(0.0, $macroState->outputGapEma * self::CORPORATE_EXPANSION_SCALAR * $this->getOperatingCyclicality($stock)),
            'expeditionary_ops'    => $fearPremium + $creditDistressPremium,
        ];
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        // Resolve company-specific tuned parameters (e.g. GRIP = 80% Gov, WATCH = 60% Retainer, OSPR = 80% Expeditionary)
        $params = $this->resolveModelParameters($stock, [
            ModelParam::GovernmentContractWeight->value => self::GOVERNMENT_CONTRACT_WEIGHT,
            ModelParam::RetainerWeight->value           => self::RETAINER_WEIGHT,
            ModelParam::ExpeditionaryWeight->value      => self::EXPEDITIONARY_WEIGHT,
        ]);

        $govWeight          = $params[ModelParam::GovernmentContractWeight];
        $retainerWeight     = $params[ModelParam::RetainerWeight];
        $expeditionaryWeight = $params[ModelParam::ExpeditionaryWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'government_contracts' => $params[ModelParam::GovernmentContractWeight],
            'corporate_retainers'  => $params[ModelParam::RetainerWeight],
            'expeditionary_ops'    => $params[ModelParam::ExpeditionaryWeight],
        ]);

        $govWeight          = $activeWeights['government_contracts'];
        $retainerWeight     = $activeWeights['corporate_retainers'];
        $expeditionaryWeight = $activeWeights['expeditionary_ops'];

        // Independent stream Z-scores
        $govZ           = $streams->generateZ('government_contracts', 0.60); // High persistence (multi-year budgets)
        $retainerZ      = $streams->generateZ('corporate_retainers', 0.40); // High persistence
        $expeditionaryZ = $streams->generateZ('expeditionary_ops', 0.10); // Unpredictable
        $eventZ         = $streams->generateExogenousZ('event', 0.05);

        // =========================================================================
        // DIVERGENT MACRO PULLS
        // =========================================================================

        // 1. Government Contracts -> Rock Solid + Fiscal Appropriations + Cost-Plus Inflation Capture
        // Inflation running above target boosts revenue via cost-plus escalation, and state appropriations scale the baseline budget.
        // 2. Corporate Retainers -> Mild GDP Expansion (Corporate HQ footprint expansion)
        // 3. Expeditionary & Black-Ops -> VIX (Fear) & Credit Spreads (Geopolitical/Financial Stress)
        $inflation = $macroState->inflationEma;
        $costPlusBonus = $inflation > MacroEngine::TARGET_INFLATION
            ? ($inflation - MacroEngine::TARGET_INFLATION) * self::COST_PLUS_BONUS_SCALAR
            : 0.0;
        $govSpendShift = ($macroState->governmentSpendingIndexEma - 100.0) / 100.0;
        $macroShifts = $this->resolveStreamMacroShifts($stock, $macroState);

        // --- Asymmetric Tail Risk Events ---
        $retainerMultiplier = 1.0;
        $expeditionaryMultiplier = 1.0;
        $tacticalFailurePenalty = 0.0;
        $eventType = null;

        if ($eventZ < self::TACTICAL_FAILURE_Z_SCORE) {
            $eventType = ShockEvent::SECURITY_BREACH;
            $retainerMultiplier = self::TACTICAL_FAILURE_REV_MULT; // Publicized failure causes client flight
            $tacticalFailurePenalty = self::TACTICAL_FAILURE_PENALTY; // Fines, lawsuits, PR cleanup
        } elseif ($eventZ > self::GEOPOLITICAL_CONFLICT_Z_SCORE) {
            $eventType = ShockEvent::GEOPOLITICAL_CONFLICT;
            $expeditionaryMultiplier = self::GEOPOLITICAL_CONFLICT_MULT; // Conflicts explode PMC demand
        }

        // --- Clamped Tri-Stream Revenue Calculation ---

        // STREAM 1: Government (Rock Solid + Appropriations)
        $govRevenue = max(0.0, $expectedRevenue * $govWeight * (1.0 + ($govZ * $baselineVol * self::GOVERNMENT_VARIANCE_SCALAR) + $costPlusBonus + $macroShifts['government_contracts']));

        // STREAM 2: Corporate Retainers (Changes a little bit)
        $retainerRevenue = max(0.0, $expectedRevenue * $retainerWeight * (1.0 + ($retainerZ * $baselineVol * self::RETAINER_VARIANCE_SCALAR) + $macroShifts['corporate_retainers']) * $retainerMultiplier);

        // STREAM 3: Expeditionary / Black-Ops (Hyper-Volatile & Counter-Cyclical)
        $expeditionaryRevenue = max(0.0, $expectedRevenue * $expeditionaryWeight * (1.0 + ($expeditionaryZ * $baselineVol * self::EXPEDITIONARY_VARIANCE_SCALAR) + $macroShifts['expeditionary_ops']) * $expeditionaryMultiplier);

        $streamRevenues = [
            'government_contracts' => $govRevenue,
            'corporate_retainers'  => $retainerRevenue,
            'expeditionary_ops'    => $expeditionaryRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // --- Cost & Margin Physics ---
        // Guard payroll follows wage growth; contract rates recover most of it (cost-plus government work, annual retainer resets).
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);
        // Apply the tactical failure legal penalty directly to the baseline variable margin
        $rawMargin = $realizedVariableMargin + $inputCostDrag + $tacticalFailurePenalty;
        $clampedMargin = $this->clampMargin($rawMargin);

        // Primary shock is whichever stream deviated the most, overridden by tail events
        $primaryShockZ = $streams->resolveDominantShockZ([$expeditionaryZ, $retainerZ, $govZ], $eventZ);

        // Visibility: Government cost-plus inflation is fully public, Retainers are moderately public, Expeditionary Black Ops are opaque.
        $observableShockZ = ($govZ * $govWeight * self::GOVERNMENT_VARIANCE_SCALAR * 1.0) +
            ($retainerZ * $retainerWeight * self::RETAINER_VARIANCE_SCALAR * 0.50) +
            ($expeditionaryZ * $expeditionaryWeight * self::EXPEDITIONARY_VARIANCE_SCALAR * 0.05) +
            ($govSpendShift * $govWeight * self::APPROPRIATIONS_VOLUME_SCALAR);
        $observableShockZ *= $baselineVol;

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
     * MacroStateDTO fields (snake_case) this model's operating physics genuinely reads in
     * calculateSectorPhysics()/getMacroPhysics() — see OperatingStrategyInterface for the full rule.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array
    {
        return [
            'exchange_rate_index_ema',
            'government_spending_index_ema',
            'inflation_ema',
            'macro_credit_spread_ema',
            'market_volatility_ema',
            'output_gap_ema',
            'supercore_inflation_ema',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}
