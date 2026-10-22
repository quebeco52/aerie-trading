<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\DTO\MacroStateDTO;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Model\ModelParam;

/**
 * Earnings strategy for Security, Defense & Private Military Contractors (PMCs).
 *
 * Financial Physics:
 * - Tri-Stream Engine:
 *      1. Government Contracts: Rock-solid, sovereign cost-plus revenue (0% GDP sensitivity, ultra-low variance).
 *      2. Corporate Retainers: Highly defensive, non-negotiable security contracts (mild GDP expansion sensitivity).
 *      3. Expeditionary / Black-Ops: Counter-cyclical, high-margin conflict & extraction services (VIX & Credit Spread
 *         driven).
 * - Macro Differentiators:
 *      - Government Stream -> Pulls from appropriations (the budget the state sets for security contracts).
 *      - Corporate Stream -> Pulls from Output Gap (Corporate HQ expansion).
 *      - Expeditionary Stream -> Pulls from VIX & Credit Spreads (Panic & Geopolitical Stress).
 * - Tail Risk: A high-profile tactical failure or assassination causes immediate contract cancellations and legal
 *   fallout.
 */
class SecurityProtectionBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Guarding contracts
     * are a survival expense.
     */
    public const OPERATING_CYCLICALITY = 0.60;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.40;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.50;

    // --- Input Cost Basket ---
    /**
     * Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain
     * content (labor still the model's own).
     */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::SECURITY_PROTECTION;
    /** Cost-plus government work and annual retainer resets recover guard wage moves almost in full. */
    public const PRICING_POWER_INDEX = 0.85;

    // --- Services Pricing ---
    /**
     * Elasticity of fee and rate pricing to services (supercore) inflation. Contract guard rates pass through services
     * wage inflation.
     */
    public const PRICING_ELASTICITY = 0.85;
    /** Services price off core services inflation ex-housing, not goods breakevens. */
    public const PRICING_INFLATION_BASIS = 'supercore_inflation_ema';

    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for security and private military contractors. */
    public const BASE_COVERAGE_VISIBILITY = 0.20;
    /** Base analyst forecasting error scalar for obfuscated security operations. */
    public const BASE_COVERAGE_ERROR = 0.10;

    // --- Secular Demand ---
    /**
     * Administrative and support services value added as a share of US nominal GDP in 1997 (BEA GDP by Industry, value
     * added).
     */
    public const SECULAR_SHARE_1997 = 0.0226;
    /** The same share in 2019. */
    public const SECULAR_SHARE_2019 = 0.0284;

    // --- Return Reversion ---
    /** Pull of the trailing return toward the required return plus the moat (0.15 a year). */
    public const ROIC_REVERSION_SPEED = 0.15;
    /** Excess return over the required return the moat sustains (2.5pp). */
    public const MOAT_SPREAD = 0.025;

    // --- Capital Cycle ---
    /** Elasticity of the capital budget to the cycle signal (0.8). Fleet, armor, and tactical hardware. */
    public const CAPEX_CYCLICALITY = 0.8;
    /** Share of construction in progress completed into plant each quarter (20%). */
    public const CAPEX_COMPLETION_RATE = 0.2;
    /** Net working capital as a share of revenue (5%). */
    public const WORKING_CAPITAL_INTENSITY = 0.05;

    // --- Seasonality ---
    /** Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: summer event and site staffing peak. */
    public const SEASONALITY_FACTORS = [0.98, 1.00, 1.02, 1.00];

    /** Trend real growth plus the sector's measured drift in its share of GDP. */
    public function getSecularGrowthRate(Stock $stock): float
    {
        return MacroConstants::TREND_REAL_GROWTH
            + MacroTransmission::gdpShareDrift(self::SECULAR_SHARE_1997, self::SECULAR_SHARE_2019, FinancialConstants::SECULAR_SHARE_WINDOW_YEARS);
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
    /**
     * Elasticity of government guarding volume to the state spending index (0.30 = a 10% budget lift adds 3% contracted
     * posts).
     */
    public const APPROPRIATIONS_VOLUME_SCALAR = 0.30;
    /**
     * Elasticity of corporate retainer posts to the output gap at unit cyclicality, both ways (new facilities need
     * guards, and closed ones release them).
     */
    public const CORPORATE_EXPANSION_SCALAR = 0.40;

    /**
     * Expeditionary demand per unit of market volatility against its anchor, both ways: panic buys executive
     * protection, and calm markets let the contracts lapse.
     */
    public const FEAR_PREMIUM_SCALAR = 0.80;
    /** Expeditionary demand per unit of the credit spread against its measured trend, both ways. */
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
     * stream's volume. Price effects stay out; the
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
     * Each stream's macro volume shift: government posts on state appropriations; corporate retainers on the gap;
     * expeditionary deployments on market volatility and credit spreads. Each reads its driver against where it
     * settles, both ways, because the average year is already in the baseline.
     *
     * @return array{government_contracts: float, corporate_retainers: float, expeditionary_ops: float}
     */
    private function resolveStreamMacroShifts(Stock $stock, MacroStateDTO $macroState): array
    {
        $govSpendShift = ($macroState->governmentSpendingIndexEma - 100.0) / 100.0;
        $fearPremium = ($macroState->marketVolatilityEma - MacroConstants::MACRO_VOL_BASE_ANCHOR) * self::FEAR_PREMIUM_SCALAR;
        $creditDistressPremium = ($macroState->macroCreditSpreadEma - $macroState->macroCreditSpreadTrend) * self::CREDIT_STRESS_SCALAR;

        return [
            'government_contracts' => $govSpendShift * self::APPROPRIATIONS_VOLUME_SCALAR,
            'corporate_retainers'  => $macroState->outputGapEma * self::CORPORATE_EXPANSION_SCALAR * $this->getOperatingCyclicality($stock),
            'expeditionary_ops'    => $fearPremium + $creditDistressPremium,
        ];
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        // Resolve company-specific tuned parameters (stream weights per firm)
        $params = $this->resolveModelParameters($stock, [
            ModelParam::GovernmentContractWeight->value => self::GOVERNMENT_CONTRACT_WEIGHT,
            ModelParam::RetainerWeight->value           => self::RETAINER_WEIGHT,
            ModelParam::ExpeditionaryWeight->value      => self::EXPEDITIONARY_WEIGHT,
        ]);

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

        // 1. Government Contracts -> state appropriations
        // 2. Corporate Retainers -> the output gap (corporate HQ footprint)
        // 3. Expeditionary & Black-Ops -> VIX (Fear) & Credit Spreads (Geopolitical/Financial Stress)
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
        $govRevenue = max(0.0, $expectedRevenue * $govWeight * (1.0 + ($govZ * $baselineVol * self::GOVERNMENT_VARIANCE_SCALAR) + $macroShifts['government_contracts']));

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

        // --- Cost & Margin Physics --- Guard payroll follows wage growth; contract rates recover most of it (cost-plus
        // government work, annual retainer resets).
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);
        $rawMargin = $realizedVariableMargin + $inputCostDrag;
        $clampedMargin = $this->clampMargin($rawMargin);
        $this->registerPublicSpendingExposure($streams, $expectedRevenue * $govWeight * self::APPROPRIATIONS_VOLUME_SCALAR, $clampedMargin, $actualRevenue);

        $specialCharge = $tacticalFailurePenalty * $actualRevenue;

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
            'import_price_level',
            'macro_credit_spread_ema',
            'market_volatility_ema',
            'output_gap_ema',
            'supercore_inflation_ema',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}
