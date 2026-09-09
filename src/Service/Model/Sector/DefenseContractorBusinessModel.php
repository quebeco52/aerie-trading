<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\DTO\StreamContext;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Heavy Defense Contractors & Aerospace Weapons Manufacturers.
 * 
 * Financial Physics:
 * - Tri-Stream Defense Engine:
 *      1. Cost-Plus Sovereign Procurement: Protected by FAR 16.3 inflation escalators.
 *      2. Fixed-Price Development (EMD): ASC 606 reach-forward losses; vulnerable to general inflation.
 *      3. Foreign Military Sales (FMS): High-margin exports where Wright's Law drives margin expansion.
 * - Sovereign Fiscal Physics: Immune to consumer recessions; exposed to Continuing Resolution (CR) budget freezes.
 * - FAR Progress Payment Withholding: Program overruns/defects trigger working capital expansion (FAR 32.503-6).
 * - Classified Tooling & Platform Modernization: Multi-decade tooling tech debt vs next-gen franchise margin expansion.
 */
class DefenseContractorBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Appropriations, not GDP, set volume; sole-source programs rarely switch. */
    public const OPERATING_CYCLICALITY = 0.50;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.10;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.30;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base bought in tracked input markets (energy, metals, agri, freight, wholesale goods, variable payroll). */
    public const INPUT_COST_EXPOSURES = ['metals' => 0.10, 'ppi' => 0.20, 'labor' => 0.35, 'energy' => 0.03];
    /** Cost-plus and FMS contracts reprice through FAR escalators within the year; fixed-price EMD never does. */
    public const INPUT_PASS_THROUGH_LAG_YEARS = 0.75;
    /** Cost-plus and FMS work recovers allowable cost by contract; only the fixed-price development share eats an overrun, and fixed-price-heavy primes are tuned down per ticker. */
    public const PRICING_POWER_INDEX = 0.70;

    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: federal fiscal year-end obligation flush in September, continuing resolutions in Q4.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [0.95, 0.98, 1.10, 0.97];
    }

    // --- Analyst Visibility & Forecasting ---
    /** Base coverage visibility for defense contractors from public appropriations. */
    public const BASE_COVERAGE_VISIBILITY = 0.60;
    /** Base analyst forecasting error scalar for defense procurement schedules. */
    public const BASE_COVERAGE_ERROR = 0.08;

    // --- Tri-Stream Architecture Weights ---
    /** Baseline fraction of revenue from sovereign cost-plus procurement and sustainment contracts. */
    public const COST_PLUS_WEIGHT = 0.60;
    /** Baseline fraction of revenue from fixed-price engineering and classified development programs. */
    public const FIXED_PRICE_DEV_WEIGHT = 0.20;
    /** Baseline fraction of revenue from high-margin foreign military sales (FMS) weapon exports. */
    public const FOREIGN_MILITARY_SALES_WEIGHT = 0.20;

    // --- Stream Variance & Volatility Scalars ---
    /** Volatility multiplier for highly stable sovereign cost-plus appropriations. */
    public const COST_PLUS_VARIANCE_SCALAR = 0.04;
    /** Volatility multiplier for developmental milestones and prototype testing. */
    public const FIXED_PRICE_DEV_VARIANCE_SCALAR = 0.15;
    /** Volatility multiplier for international defense sales and export authorizations. */
    public const FMS_VARIANCE_SCALAR = 0.35;

    // --- Sovereign Procurement & Cost-Plus Physics ---
    /** Sensitivity multiplier for FAR 16.3 inflation indexation escalation clauses. */
    public const COST_PLUS_BONUS_SCALAR = 1.50;
    /** Sovereign credit spread threshold triggering Continuing Resolution (CR) budget freeze drag. */
    public const SOVEREIGN_STRESS_THRESHOLD = 0.030;
    /** Revenue drag scalar applied to cost-plus lot allocations during debt ceiling freezes. */
    public const CR_BUDGET_DRAG_SCALAR = 2.50;
    /** Maximum allowable revenue multiplier drag during a severe Continuing Resolution freeze. */
    public const MAX_CR_BUDGET_DRAG = 0.50;

    // --- Fixed-Price ASC 606 & Production Physics ---
    /** Negative Z-score threshold indicating development program overruns and reach-forward losses. */
    public const FORWARD_LOSS_Z_SCORE = -1.50;
    /** Variable margin penalty for ASC 606 reach-forward losses on fixed-price contracts. */
    public const FORWARD_LOSS_PENALTY = 0.08;

    // --- Order Backlog (funded and unfunded) ---
    /** Fraction of the cost-plus procurement backlog executed each quarter (multi-year appropriations, ~5.7 quarters of coverage). */
    public const COST_PLUS_BACKLOG_BURN_RATE = 0.15;
    /** Fraction of the fixed-price development backlog executed each quarter (~4 quarters of coverage). */
    public const FIXED_PRICE_BACKLOG_BURN_RATE = 0.20;
    /** Fraction of the foreign military sales backlog delivered each quarter (~3 quarters of coverage). */
    public const FMS_BACKLOG_BURN_RATE = 0.25;
    /** Regime key for a fixed-price development program in overrun that keeps taking charges until delivery. */
    public const REGIME_PROGRAM_OVERRUN = 'program_overrun';
    /** Quarterly probability the troubled program is delivered or re-baselined (~6 quarter expected overrun). */
    public const PROGRAM_OVERRUN_EXIT_HAZARD = 0.17;
    /** Ongoing quarterly reach-forward charge while the fixed-price program remains in overrun. */
    public const FORWARD_LOSS_ONGOING_PENALTY = 0.03;
    /** Margin efficiency elasticity (Wright's Law) applied to mature FMS production volume scale. */
    public const PRODUCTION_LEARNING_CURVE_ELASTICITY = 0.020;

    // --- Geopolitical Sanctions & Conflict Tail Shocks ---
    /** Negative Z-score threshold indicating congressional foreign military export sanctions or bans. */
    public const CONGRESSIONAL_EXPORT_BAN_Z = -2.00;
    /** Revenue multiplier applied to international sales during arms export embargoes. */
    public const EXPORT_BAN_MULT = 0.50;
    /** Positive Z-score threshold indicating active regional conflict and munition restock surges. */
    public const GEOPOLITICAL_CONFLICT_Z = 2.00;
    /** Export revenue multiplier during active geopolitical conflict surges. */
    public const FMS_CONFLICT_BOOST = 1.50;
    /** Margin drag from emergency wartime supply chain expediting and component premiums. */
    public const WARTIME_SUPPLY_CHAIN_DRAG = 0.035;

    // --- Major Programmatic Contract Shocks ---
    /** Negative Z-score threshold indicating cancellation or failure of a flagship weapon program. */
    public const FLAGSHIP_FAILURE_Z_SCORE = -2.50;
    /** Cost-plus revenue multiplier applied during major contract cancellations. */
    public const FLAGSHIP_FAILURE_MULT = 0.80;
    /** Variable margin penalty from fleet groundings, redesign liabilities, and cancellation fees. */
    public const FLAGSHIP_FAILURE_PENALTY = 0.10;
    /** Positive Z-score threshold indicating a multi-decade prime platform franchise win. */
    public const MEGA_CONTRACT_WIN_Z_SCORE = 2.50;
    /** Revenue multiplier applied to cost-plus stream upon securing prime contractor status. */
    public const MEGA_CONTRACT_WIN_MULT = 1.18;

    // --- Program Execution & Classified Tooling Physics ---
    /** Margin decay rate per unit of underinvestment in classified tooling and secure facilities. */
    public const DEFENSE_TOOLING_DECAY_RATE = 0.018;
    /** Margin gain rate per unit of logarithmic overinvestment in next-gen platforms. */
    public const CLASSIFIED_PLATFORM_GAIN_RATE = 0.009;
    /** Structural minimum operating margin floor under severe tooling tech debt. */
    public const MIN_OPERATING_MARGIN_FLOOR = 0.06;
    /** Structural maximum operating margin ceiling for next-gen platform franchises. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.22;

    // --- Working Capital & FAR Progress Payment Withholding ---
    /** Baseline net working capital intensity under standard FAR progress payment schedules. */
    public const BASE_NWC_INTENSITY = 0.10;
    /** Elevated net working capital intensity during FAR 32.503-6 progress payment withholding. */
    public const WITHHOLDING_NWC_INTENSITY = 0.18;

        public function getWholesaleLeverageLimit(): float { return 2.0; }
    public function getReversionSpeed(): float { return 0.12; }
    public function getMoatSpread(): float { return 0.02; }
    public function getCapExCompletionRate(Stock $stock): float { return 0.2; }

    public function getCapexCyclicality(): float
    {
        return 0.30;
    }

    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.70, 'revenue_weight' => 0.30];
    }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.02;
    }

    public function getWorkingCapitalIntensity(Stock $stock): float
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $fixedPriceZ = (float) ($momentum['fixed_price_development'] ?? 0.0);
        $eventZ = (float) ($momentum['event'] ?? 0.0);

        // FAR 32.503-6 Suspension/Reduction of Progress Payments due to programmatic failures
        if ($fixedPriceZ < self::FORWARD_LOSS_Z_SCORE || $eventZ < self::FLAGSHIP_FAILURE_Z_SCORE) {
            return self::WITHHOLDING_NWC_INTENSITY;
        }

        return self::BASE_NWC_INTENSITY;
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        return [
            'macro_demand_shift'       => 0.0,
            'pricing_power_multiplier' => 1.0,
        ];
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
            ModelParam::CostPlusWeight->value             => self::COST_PLUS_WEIGHT,
            ModelParam::FixedPriceDevWeight->value        => self::FIXED_PRICE_DEV_WEIGHT,
            ModelParam::ForeignMilitarySalesWeight->value => self::FOREIGN_MILITARY_SALES_WEIGHT,
        ]);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Dynamic Revenue Mix Drift ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'cost_plus_procurement'   => $params[ModelParam::CostPlusWeight->value],
            'fixed_price_development' => $params[ModelParam::FixedPriceDevWeight->value],
            'foreign_military_sales'  => $params[ModelParam::ForeignMilitarySalesWeight->value],
        ]);

        $costPlusWeight   = $activeWeights['cost_plus_procurement'];
        $fixedPriceWeight = $activeWeights['fixed_price_development'];
        $fmsWeight        = $activeWeights['foreign_military_sales'];

        $costPlusZ   = $streams->generateZ('cost_plus_procurement', 0.70); // High multi-year appropriation persistence
        $fixedPriceZ = $streams->generateZ('fixed_price_development', 0.30);
        $fmsZ        = $streams->generateZ('foreign_military_sales', 0.20);
        $eventZ      = $streams->generateExogenousZ('event', 0.10);

        // --- Sovereign Procurement & Cost-Plus Fiscal Physics ---
        $inflation = $macroState->inflationEma;
        $govSpendShift = ($macroState->governmentSpendingIndexEma - 100.0) / 100.0;

        // FAR 16.3 Cost-Plus contracts pass through excess inflation as nominal revenue growth
        $costPlusBonus = $inflation > MacroEngine::TARGET_INFLATION
            ? ($inflation - MacroEngine::TARGET_INFLATION) * self::COST_PLUS_BONUS_SCALAR
            : 0.0;

        // Sovereign credit spreads indicate debt ceiling crises triggering Continuing Resolutions (CRs)
        $creditSpread = $macroState->macroCreditSpreadEma;
        $crDrag = $creditSpread > self::SOVEREIGN_STRESS_THRESHOLD
            ? ($creditSpread - self::SOVEREIGN_STRESS_THRESHOLD) * self::CR_BUDGET_DRAG_SCALAR
            : 0.0;

        // --- Program Execution, Forward Losses & Tail Shocks ---
        $costPlusMultiplier   = max(self::MAX_CR_BUDGET_DRAG, 1.0 - $crDrag);
        $fixedPriceMultiplier = 1.0;
        $fmsMultiplier        = 1.0;
        $eventType            = null;
        $forwardLossPenalty   = 0.0;
        $flagshipPenalty      = 0.0;
        $wartimeSupplyDrag    = 0.0;

        // Wright's Law: Learning curve applies to mature FMS export volume
        $learningCurveShift = -self::PRODUCTION_LEARNING_CURVE_ELASTICITY * $fmsZ * $fmsWeight;

        // A fixed-price development program in trouble books its reach-forward loss once, then keeps
        // taking charges every quarter until delivery or re-baselining (the tanker-program pattern).
        $overrunElapsed = $streams->evolveRegime(self::REGIME_PROGRAM_OVERRUN, 0.0, self::PROGRAM_OVERRUN_EXIT_HAZARD);
        if ($fixedPriceZ < self::FORWARD_LOSS_Z_SCORE && $overrunElapsed === 0) {
            $overrunElapsed = $streams->startRegime(self::REGIME_PROGRAM_OVERRUN);
            $eventType = ShockEvent::PROJECT_DELAY;
        }
        $forwardLossPenalty = match (true) {
            $overrunElapsed === 1 => self::FORWARD_LOSS_PENALTY,
            $overrunElapsed > 1   => self::FORWARD_LOSS_ONGOING_PENALTY,
            default               => 0.0,
        };

        if ($eventZ < self::FLAGSHIP_FAILURE_Z_SCORE) {
            $costPlusMultiplier = self::FLAGSHIP_FAILURE_MULT;
            $flagshipPenalty = self::FLAGSHIP_FAILURE_PENALTY;
            $eventType = ShockEvent::DEFENSE_CONTRACT_LOSS;
        } elseif ($eventZ > self::MEGA_CONTRACT_WIN_Z_SCORE) {
            $costPlusMultiplier = self::MEGA_CONTRACT_WIN_MULT;
            $eventType = ShockEvent::DEFENSE_CONTRACT_WIN;
        } elseif ($eventZ < self::CONGRESSIONAL_EXPORT_BAN_Z) {
            $fmsMultiplier = self::EXPORT_BAN_MULT;
            $eventType = ShockEvent::GEOPOLITICAL_EXPORT_BAN;
        }

        if ($fmsZ > self::GEOPOLITICAL_CONFLICT_Z) {
            $fmsMultiplier = self::FMS_CONFLICT_BOOST;
            $wartimeSupplyDrag = self::WARTIME_SUPPLY_CHAIN_DRAG;
            $eventType = ShockEvent::GEOPOLITICAL_CONFLICT;
        }

        // --- Clamped Revenue Streams ---
        $fxShift = ($macroState->exchangeRateIndexEma - 100.0) / 100.0;
        // Input cost basket: titanium and specialty metals, electronics, engineering payroll. Cost-plus and FMS
        // work recovers the move through escalators; the fixed-price development share eats it.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, 1.0 - $fixedPriceWeight, $realizedVariableMargin);

        // Contract awards fund a multi-year backlog; revenue is recognized on percentage of completion, so
        // appropriations, continuing resolutions and export bans hit ORDERS in full and revenue gradually.
        $costPlusBook = $streams->recognizeBacklog('cost_plus_procurement', $expectedRevenue * $costPlusWeight,
            max(0.0, (1.0 + ($costPlusZ * $baselineVol * self::COST_PLUS_VARIANCE_SCALAR) + $costPlusBonus + ($govSpendShift * 0.40)) * $costPlusMultiplier), self::COST_PLUS_BACKLOG_BURN_RATE);
        $fixedPriceBook = $streams->recognizeBacklog('fixed_price_development', $expectedRevenue * $fixedPriceWeight,
            max(0.0, (1.0 + ($fixedPriceZ * $baselineVol * self::FIXED_PRICE_DEV_VARIANCE_SCALAR) + ($govSpendShift * 0.40)) * $fixedPriceMultiplier), self::FIXED_PRICE_BACKLOG_BURN_RATE);
        $fmsBook = $streams->recognizeBacklog('foreign_military_sales', $expectedRevenue * $fmsWeight,
            max(0.0, (1.0 + ($fmsZ * $baselineVol * self::FMS_VARIANCE_SCALAR) - ($fxShift * 0.15)) * $fmsMultiplier), self::FMS_BACKLOG_BURN_RATE);

        $costPlusRevenue = $costPlusBook['revenue'];
        $fixedPriceRevenue = $fixedPriceBook['revenue'];
        $fmsRevenue = $fmsBook['revenue'];

        $backlogOrders = $costPlusBook['orders'] + $fixedPriceBook['orders'] + $fmsBook['orders'];
        $backlogRevenue = $costPlusRevenue + $fixedPriceRevenue + $fmsRevenue;
        $backlogQuarters = ($costPlusBook['backlog'] + $fixedPriceBook['backlog'] + $fmsBook['backlog']) / max(1.0, $expectedRevenue);

        $streamRevenues = [
            'cost_plus_procurement'   => $costPlusRevenue,
            'fixed_price_development' => $fixedPriceRevenue,
            'foreign_military_sales'  => $fmsRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // --- Realized Variable Cost Margin ---
        $rawMargin = $realizedVariableMargin
            + $learningCurveShift
            + ($forwardLossPenalty * $fixedPriceWeight)
            + ($flagshipPenalty * $costPlusWeight)
            + ($wartimeSupplyDrag * $fmsWeight)
            + $inputCostDrag;

        $clampedMargin = $this->clampMargin($rawMargin);

        // --- Shock Determination ---
        $primaryShockZ = $streams->resolveDominantShockZ([$costPlusZ, $fixedPriceZ, $fmsZ], $eventZ);

        // Analysts see awards and book-to-bill, but this quarter's revenue only moves at the burn rate.
        $costPlusShock   = ((($costPlusZ * $baselineVol * self::COST_PLUS_VARIANCE_SCALAR) + $costPlusBonus) * $costPlusMultiplier + ($costPlusMultiplier - 1.0)) * self::COST_PLUS_BACKLOG_BURN_RATE;
        $fixedPriceShock = (($fixedPriceZ * $baselineVol * self::FIXED_PRICE_DEV_VARIANCE_SCALAR) * $fixedPriceMultiplier + ($fixedPriceMultiplier - 1.0)) * self::FIXED_PRICE_BACKLOG_BURN_RATE;
        $fmsShock        = (($fmsZ * $baselineVol * self::FMS_VARIANCE_SCALAR) * $fmsMultiplier + ($fmsMultiplier - 1.0)) * self::FMS_BACKLOG_BURN_RATE;

        $observableShockZ = ($costPlusShock * $costPlusWeight) + ($fixedPriceShock * $fixedPriceWeight) + ($fmsShock * $fmsWeight);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
                    kpis: ['book_to_bill' => $backlogRevenue > 0.0 ? $backlogOrders / $backlogRevenue : 1.0, 'backlog_quarters' => $backlogQuarters],
        );
    }

    /** Under-investment below replacement CapEx erodes operating margin toward the sector floor. */
    public function getDepreciationDecayRate(): float
    {
        return self::DEFENSE_TOOLING_DECAY_RATE;
    }

    /** Over-investment above replacement CapEx compounds margin toward the sector ceiling. */
    public function getModernizationGainRate(): float
    {
        return self::CLASSIFIED_PLATFORM_GAIN_RATE;
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
            'energy_cost_push_lag',
            'exchange_rate_index_ema',
            'government_spending_index_ema',
            'industrial_metals_index_ema',
            'inflation_ema',
            'macro_credit_spread_ema',
            'producer_price_inflation_ema',
            'wage_growth_ema',
        ];
    }
}
