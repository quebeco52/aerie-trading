<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\DTO\MacroStateDTO;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;

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

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.025;
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

        // PMCs have immense pricing power to pass wage and gear inflation through to corporate and government clients.
        $physics['pricing_power_multiplier'] = 1.0 + ($macroState->inflationEma * 0.80);

        return $physics;
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
        $streams = new \App\DTO\StreamContext($momentum, $mathUtility);
        $beta = abs((float) $stock->getBeta());

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
        $eventZ         = $streams->generateZ('event', 0.05);

        // =========================================================================
        // DIVERGENT MACRO PULLS
        // =========================================================================

        // 1. Government Contracts -> Rock Solid + Fiscal Appropriations + Cost-Plus Inflation Capture
        // Inflation running above target boosts revenue via cost-plus escalation, and state appropriations scale the baseline budget.
        $inflation = $macroState->inflationEma;
        $costPlusBonus = $inflation > MacroEngine::TARGET_INFLATION
            ? ($inflation - MacroEngine::TARGET_INFLATION) * self::COST_PLUS_BONUS_SCALAR
            : 0.0;
        $govSpendShift = ($macroState->governmentSpendingIndexEma - 100.0) / 100.0;

        // 2. Corporate Retainers -> Mild GDP Expansion (Corporate HQ footprint expansion)
        // Mildly increases when the economy expands (companies build new facilities needing guards).
        $corporateExpansionShift = max(0.0, $macroState->outputGapEma * 0.40 * $beta);

        // 3. Expeditionary & Black-Ops -> VIX (Fear) & Credit Spreads (Geopolitical/Financial Stress)
        // Thrives counter-cyclically on market panic and credit market distress.
        $vixEma = $macroState->marketVolatilityEma;
        $fearPremium = max(0.0, ($vixEma - self::VIX_FEAR_THRESHOLD) * self::FEAR_PREMIUM_SCALAR);

        $creditSpread = $macroState->macroCreditSpreadEma;
        $creditDistressPremium = max(0.0, ($creditSpread - self::CREDIT_STRESS_THRESHOLD) * self::CREDIT_STRESS_SCALAR);

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
        $govRevenue = max(0.0, $expectedRevenue * $govWeight * (1.0 + ($govZ * $baselineVol * self::GOVERNMENT_VARIANCE_SCALAR) + $costPlusBonus + ($govSpendShift * 0.30)));

        // STREAM 2: Corporate Retainers (Changes a little bit)
        $retainerRevenue = max(0.0, $expectedRevenue * $retainerWeight * (1.0 + ($retainerZ * $baselineVol * self::RETAINER_VARIANCE_SCALAR) + $corporateExpansionShift) * $retainerMultiplier);

        // STREAM 3: Expeditionary / Black-Ops (Hyper-Volatile & Counter-Cyclical)
        $expeditionaryRevenue = max(0.0, $expectedRevenue * $expeditionaryWeight * (1.0 + ($expeditionaryZ * $baselineVol * self::EXPEDITIONARY_VARIANCE_SCALAR) + $fearPremium + $creditDistressPremium) * $expeditionaryMultiplier);

        $streamRevenues = [
            'government_contracts' => $govRevenue,
            'corporate_retainers'  => $retainerRevenue,
            'expeditionary_ops'    => $expeditionaryRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // --- Cost & Margin Physics ---
        // Apply the tactical failure legal penalty directly to the baseline variable margin
        $rawMargin = $realizedVariableMargin + $tacticalFailurePenalty;
        $clampedMargin = $this->clampMargin($rawMargin);

        // Primary shock is whichever stream deviated the most, overridden by tail events
        $primaryShockZ = $streams->resolveDominantShockZ([$expeditionaryZ, $retainerZ, $govZ], $eventZ);

        // Visibility: Government cost-plus inflation is fully public, Retainers are moderately public, Expeditionary Black Ops are opaque.
        $observableShockZ = ($govZ * $govWeight * self::GOVERNMENT_VARIANCE_SCALAR * 1.0) +
            ($retainerZ * $retainerWeight * self::RETAINER_VARIANCE_SCALAR * 0.50) +
            ($expeditionaryZ * $expeditionaryWeight * self::EXPEDITIONARY_VARIANCE_SCALAR * 0.05) +
            ($govSpendShift * $govWeight * 0.30);
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
}
