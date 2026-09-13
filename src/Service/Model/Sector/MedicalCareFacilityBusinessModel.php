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
 * Earnings strategy for Hospital Networks, Health Systems & Medical Care Facilities.
 * 
 * Financial Physics:
 * - Tri-Stream Architecture:
 *   1. Acute Inpatient Care: Inelastic bed volume (trauma/acute illness). Immune to macroeconomic downturns,
 *      but vulnerable to clinical nurse/physician wage inflation during tight labor/high inflation regimes.
 *   2. Elective Outpatient Procedures: High-margin ambulatory and elective surgeries. Pro-cyclical with
 *      consumer discretionary wealth and macro output gap.
 *   3. Insurance Arbitrage & Billing: Algorithmic DRG coding optimization and insurer arbitration fee extraction.
 *      Expands top-line revenue and margins during medical CPI / healthcare inflation acceleration.
 * - Wage Inflation vs. Pricing Power: Severe clinical labor shortages are dynamically mitigated by the firm's
 *   PricingPowerIndex (exclusive regional hospital network leverage over commercial payers).
 */
class MedicalCareFacilityBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Acute care is inelastic; elective procedures carry the cyclicality. */
    public const OPERATING_CYCLICALITY = 0.60;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.10;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.40;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base bought in tracked input markets (energy, metals, agri, freight, wholesale goods, variable payroll). */
    public const INPUT_COST_EXPOSURES = ['labor' => 0.60, 'ppi' => 0.15, 'energy' => 0.03];

    // --- Services Pricing ---
    /** Elasticity of fee and rate pricing to services (supercore) inflation. Reimbursement follows medical services inflation, capped by payer contracts. */
    public const PRICING_ELASTICITY = 0.60;
    /** Services price off core services inflation ex-housing, not goods breakevens. */
    public const PRICING_INFLATION_BASIS = 'supercore_inflation_ema';

    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: Q1 flu season admissions.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [1.06, 0.98, 0.96, 1.00];
    }

    // --- Balance Sheet Realism ---
    /** Capitalized operating lease liabilities as a fraction of annual revenue (IFRS 16 / ASC 842). Hospital campuses and clinics under long-term leases. */
    public const LEASE_LIABILITY_INTENSITY = 0.30;

    // --- Coverage Sensitivity ---
    /** Elective outpatient volume lost per unit of unemployment above the natural rate (2.0 = -2% volume per point): job loss ends employer coverage and elective procedures are deferred. */
    public const UNEMPLOYMENT_ELECTIVE_SENSITIVITY = 2.0;

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Nursing and clinical staff payroll dominates hospital overhead. */
    public const FIXED_COST_LABOR_SHARE = 0.70;

    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for hospital networks with steady public reporting. */
    public const BASE_COVERAGE_VISIBILITY = 0.35;

    /** Base coverage forecasting error for healthcare providers. */
    public const BASE_COVERAGE_ERROR = 0.06;

    // --- Default Tri-Stream Weights ---
    /** Baseline revenue share from non-discretionary acute inpatient hospital admissions. */
    public const INPATIENT_CARE_WEIGHT = 0.50;

    /** Baseline revenue share from high-margin elective outpatient and ambulatory surgeries. */
    public const ELECTIVE_OUTPATIENT_WEIGHT = 0.30;

    /** Baseline revenue share from algorithmic billing optimization, coding maximization & arbitration. */
    public const INSURANCE_ARBITRAGE_WEIGHT = 0.20;

    /** Baseline pricing power and insurer reimbursement negotiation leverage. */
    public const PRICING_POWER_INDEX = 0.70;

    // --- Stream Volatility Scalars ---
    /** Volatility scalar for acute inpatient care (highly stable bed occupancy). */
    public const INPATIENT_VARIANCE_SCALAR = 0.08;

    /** Volatility scalar for elective outpatient procedures (discretionary consumer surgeries). */
    public const OUTPATIENT_VARIANCE_SCALAR = 0.35;

    /** Volatility scalar for insurance coding and billing arbitration extraction. */
    public const ARBITRAGE_VARIANCE_SCALAR = 0.20;

    // --- Clinical Labor & Inflation Squeeze ---
    /** Payer contracts reprice on multi-year cycles, so clinical wage moves take longer to reach reimbursement rates. */
    public const INPUT_PASS_THROUGH_LAG_YEARS = 1.25;

    /** Multiplier for insurance arbitrage revenue expansion during high healthcare inflation regimes. */
    public const MEDICAL_CPI_EXPANSION_SCALAR = 1.50;

    // --- Tail Risk & Shock Events ---
    /** Positive Z-score threshold for mandatory government healthcare spending expansion. */
    public const MANDATORY_SPENDING_Z_SCORE = 2.20;

    /** Top-line revenue windfall multiplier from mandatory state healthcare spending packages. */
    public const MANDATORY_SPENDING_MULT = 1.15;

    /** Negative Z-score threshold for aggressive insurer billing audits and clawback fines. */
    public const BILLING_AUDIT_Z_SCORE = -2.20;

    /** Variable cost penalty from regulatory coding audits, clawbacks, and arbitration settlements. */
    public const BILLING_AUDIT_PENALTY = 0.05;

        public function getMinIcr(): float { return 1.8; }
    public function getWholesaleLeverageLimit(): float { return 2.0; }
    public function getBuybackMinIcr(): float { return 1.8; }
    public function getReversionSpeed(): float { return 0.12; }
    public function getMoatSpread(): float { return 0.015; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return 0.08; }
    public function getCapExCompletionRate(Stock $stock): float { return 0.35; }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.025; // Demographic aging secular tailwind
    }

    public function getCapexCyclicality(): float
    {
        return 0.60; // Specialized medical equipment & facility upgrades
    }

    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.65, 'revenue_weight' => 0.35];
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Nullify generic demand shifts to calculate healthcare macro physics discretely per stream.
        $physics['macro_demand_shift'] = 0.0;

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
            ModelParam::InpatientCareWeight->value       => self::INPATIENT_CARE_WEIGHT,
            ModelParam::ElectiveOutpatientWeight->value  => self::ELECTIVE_OUTPATIENT_WEIGHT,
            ModelParam::InsuranceArbitrageWeight->value  => self::INSURANCE_ARBITRAGE_WEIGHT,
            ModelParam::PricingPowerIndex->value         => self::PRICING_POWER_INDEX,
        ]);

        $inpatientWeight  = $params[ModelParam::InpatientCareWeight];
        $outpatientWeight = $params[ModelParam::ElectiveOutpatientWeight];
        $arbitrageWeight  = $params[ModelParam::InsuranceArbitrageWeight];
        $pricingPower     = $params[ModelParam::PricingPowerIndex];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $beta = $this->getOperatingCyclicality($stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'inpatient_care'      => $params[ModelParam::InpatientCareWeight],
            'elective_outpatient' => $params[ModelParam::ElectiveOutpatientWeight],
            'insurance_arbitrage' => $params[ModelParam::InsuranceArbitrageWeight],
        ]);

        $inpatientWeight  = $activeWeights['inpatient_care'];
        $outpatientWeight = $activeWeights['elective_outpatient'];
        $arbitrageWeight  = $activeWeights['insurance_arbitrage'];

        // Independent stream Z-scores with persistent momentum
        $inpatientZ  = $streams->generateZ('inpatient_care', 0.35);
        $outpatientZ = $streams->generateZ('elective_outpatient', 0.25);
        $arbitrageZ  = $streams->generateZ('insurance_arbitrage', 0.40);
        $eventZ      = $streams->generateExogenousZ('event', 0.10);

        // --- Macro Demand Sensitivities ---
        $outputGap = $macroState->outputGapEma;
        $inflation = $macroState->inflationEma;

        // Inpatient care is completely inelastic (0.0 output gap sensitivity)
        // Elective outpatient procedures are pro-cyclical with consumer wealth, and are deferred outright when
        // job losses strip employer coverage: the uninsured postpone the knee, not the heart attack.
        $unemploymentGap = max(0.0, $macroState->unemploymentRateEma - MacroEngine::NATURAL_UNEMPLOYMENT);
        $outpatientMacroBoost = ($outputGap * 1.2 * $beta) - ($unemploymentGap * self::UNEMPLOYMENT_ELECTIVE_SENSITIVITY);

        // Insurance arbitrage expands when general & medical inflation accelerates
        $inflationExcess = max(0.0, $inflation - MacroEngine::TARGET_INFLATION);
        $arbitrageInflationBoost = ($inflationExcess * self::MEDICAL_CPI_EXPANSION_SCALAR);

        // --- Tail Risk Events ---
        $spendingMultiplier = 1.0;
        $eventType = null;
        $auditPenalty = 0.0;

        if ($eventZ > self::MANDATORY_SPENDING_Z_SCORE) {
            $spendingMultiplier = self::MANDATORY_SPENDING_MULT;
            $eventType = ShockEvent::MANDATORY_HEALTHCARE_EXPANSION;
        } elseif ($eventZ < self::BILLING_AUDIT_Z_SCORE) {
            $auditPenalty = self::BILLING_AUDIT_PENALTY;
            $eventType = ShockEvent::HEALTHCARE_AUDIT_CLAWBACK;
        }

        // --- Tri-Stream Revenue Calculation ---
        $govShift = ($macroState->governmentSpendingIndexEma - 100.0) / 100.0;
        $inpatientShock  = $inpatientZ * ($baselineVol * self::INPATIENT_VARIANCE_SCALAR);
        $outpatientShock = $outpatientZ * ($baselineVol * self::OUTPATIENT_VARIANCE_SCALAR);
        $arbitrageShock  = $arbitrageZ * ($baselineVol * self::ARBITRAGE_VARIANCE_SCALAR);

        $inpatientRevenue  = max(0.0, $expectedRevenue * $inpatientWeight * (1.0 + $inpatientShock + ($govShift * 0.30)) * $spendingMultiplier);
        $outpatientRevenue = max(0.0, $expectedRevenue * $outpatientWeight * (1.0 + $outpatientShock + $outpatientMacroBoost));
        $arbitrageRevenue  = max(0.0, $expectedRevenue * $arbitrageWeight * (1.0 + $arbitrageShock + $arbitrageInflationBoost));
        // Coding and reimbursement uplift bills the same procedures at higher rates: price, not care delivered.
        $priceRevenue      = max(0.0, $expectedRevenue * $arbitrageWeight * $arbitrageInflationBoost);

        $streamRevenues = [
            'inpatient_care'      => $inpatientRevenue,
            'elective_outpatient' => $outpatientRevenue,
            'insurance_arbitrage' => $arbitrageRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // --- Clinical Wage Inflation Squeeze & Pricing Power Mitigation ---
        // Nursing and physician payroll follows wage growth above trend; regional network leverage over
        // commercial payers recovers part of it through contract repricing.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);

        $rawMargin = $realizedVariableMargin + $inputCostDrag + $auditPenalty;
        $clampedMargin = $this->clampMargin($rawMargin);

        $primaryShockZ = $streams->resolveDominantShockZ([$inpatientZ, $outpatientZ, $arbitrageZ], $eventZ);

        // Inpatient care and mandatory spending packages are publicly visible; billing arbitrage is opaque
        $observableShockZ = ($inpatientZ * $inpatientWeight * self::INPATIENT_VARIANCE_SCALAR * 0.70) +
            ($outpatientZ * $outpatientWeight * self::OUTPATIENT_VARIANCE_SCALAR * 0.40) +
            ($arbitrageZ * $arbitrageWeight * self::ARBITRAGE_VARIANCE_SCALAR * 0.15);
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
            priceRevenue: $priceRevenue,
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
            'energy_cost_push_lag',
            'exchange_rate_index_ema',
            'government_spending_index_ema',
            'inflation_ema',
            'output_gap_ema',
            'producer_price_inflation_ema',
            'supercore_inflation_ema',
            'tips_breakeven_ema',
            'unemployment_rate_ema',
            'wage_growth_ema',
        ];
    }
}
