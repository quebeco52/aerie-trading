<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Event\ShockEvent;

/**
 * Earnings strategy for Higher Education, EdTech & Professional Workforce Upskilling.
 * 
 * Financial Physics:
 * - Counter-Cyclical Enrollment: During economic downturns and recessions, student enrollment
 *   in degrees and professional certifications surges as unemployed workers seek retraining.
 * - Tri-Stream Architecture:
 *      1. Degree & Tuition Enrollment: Counter-cyclical, backed by government student loans / upfront tuition.
 *      2. Enterprise B2B Training: Pro-cyclical corporate upskilling and executive development contracts.
 *      3. Digital LMS & Courseware Licensing: High-margin, sticky software subscription licensing.
 * - Structural Margin: Digital LMS licensing carries near-zero variable delivery cost.
 */
class EducationBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Counter-cyclical enrollment offsets pro-cyclical corporate training. */
    public const OPERATING_CYCLICALITY = 0.70;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.40;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.50;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base bought in tracked input markets (energy, metals, agri, freight, wholesale goods, variable payroll). */
    public const INPUT_COST_EXPOSURES = ['labor' => 0.65];

    // --- Services Pricing ---
    /** Elasticity of fee and rate pricing to services (supercore) inflation. Tuition follows services inflation with a lag from annual rate setting. */
    public const PRICING_ELASTICITY = 0.70;
    /** Services price off core services inflation ex-housing, not goods breakevens. */
    public const PRICING_INFLATION_BASIS = 'supercore_inflation_ema';

    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: spring and fall terms; summer trough.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [1.05, 0.85, 1.00, 1.10];
    }

    // --- Balance Sheet Realism ---
    /** Capitalized operating lease liabilities as a fraction of annual revenue (IFRS 16 / ASC 842). Campus and classroom leases. */
    public const LEASE_LIABILITY_INTENSITY = 0.25;

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Faculty and administrative payroll dominates education overhead. */
    public const FIXED_COST_LABOR_SHARE = 0.75;

    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.40;
    public const BASE_COVERAGE_ERROR = 0.06;

        public function getMinIcr(): float { return 2.5; }
    public function getWholesaleLeverageLimit(): float { return 2.0; }
    public function getDividendCrisisIcr(): float { return 1.75; }
    public function getBuybackMinIcr(): float { return 2.5; }
    public function getReversionSpeed(): float { return 0.1; }
    public function getMoatSpread(): float { return 0.015; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return 0.05; }
    public function getCapExCompletionRate(Stock $stock): float { return 0.2; }

    public function getSecularGrowthRate(Stock $stock): float { return 0.02; }
    
    public function getCapexCyclicality(): float { return 0.10; }
    
    public function getSurpriseBlendWeights(): array { return ['eps_weight' => 0.60, 'revenue_weight' => 0.40]; }

    // --- Stream Weights ---
    /** Baseline fraction of revenue derived from degree programs and student tuition. */
    public const DEGREE_TUITION_WEIGHT     = 0.50;
    /** Baseline fraction of revenue derived from corporate B2B training contracts. */
    public const ENTERPRISE_TRAINING_WEIGHT = 0.30;
    /** Baseline fraction of revenue derived from digital LMS courseware subscription licensing. */
    public const LMS_LICENSING_WEIGHT      = 0.20;

    // --- Physics & Variances ---
    public const TUITION_VARIANCE_SCALAR    = 0.10; // Stable enrollment base
    /** Fraction of deferred tuition recognized each quarter (semester-length programs, ratable over the term). */
    public const TUITION_RECOGNITION_RATE   = 0.50;
    public const ENTERPRISE_VARIANCE_SCALAR = 0.40; // Pro-cyclical corporate training
    public const LMS_VARIANCE_SCALAR        = 0.08; // Highly sticky software ARR
    /** Countercyclical sensitivity of degree enrollment to elevated unemployment rates (workforce retraining). */
    public const UNEMPLOYMENT_RETRAINING_SCALAR = 0.80;

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);


        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::DegreeTuitionWeight->value      => self::DEGREE_TUITION_WEIGHT,
            ModelParam::EnterpriseTrainingWeight->value => self::ENTERPRISE_TRAINING_WEIGHT,
            ModelParam::LmsLicensingWeight->value       => self::LMS_LICENSING_WEIGHT,
        ]);

        $tuitionWeight    = $params[ModelParam::DegreeTuitionWeight];
        $enterpriseWeight = $params[ModelParam::EnterpriseTrainingWeight];
        $lmsWeight        = $params[ModelParam::LmsLicensingWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $beta     = $this->getOperatingCyclicality($stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'degree_tuition_enrollment' => $params[ModelParam::DegreeTuitionWeight],
            'enterprise_b2b_training'   => $params[ModelParam::EnterpriseTrainingWeight],
            'digital_lms_licensing'     => $params[ModelParam::LmsLicensingWeight],
        ]);

        $tuitionWeight    = $activeWeights['degree_tuition_enrollment'];
        $enterpriseWeight = $activeWeights['enterprise_b2b_training'];
        $lmsWeight        = $activeWeights['digital_lms_licensing'];

        // Counter-cyclical student enrollment boost during recessions, unemployment spikes, and government subsidies
        $outputGap = $macroState->outputGapEma;
        $govShift = ($macroState->governmentSpendingIndexEma - 100.0) / 100.0;
        $unemploymentSurge = max(0.0, $macroState->unemploymentRateEma - MacroEngine::NATURAL_UNEMPLOYMENT);
        $counterCyclicalEnrollmentBoost = ($outputGap < 0.0 ? abs($outputGap) * 1.2 * $beta : -($outputGap * 0.4))
            + ($govShift * 0.40)
            + ($unemploymentSurge * self::UNEMPLOYMENT_RETRAINING_SCALAR * $beta);
        $proCyclicalEnterpriseShift     = $outputGap * 1.5 * $beta;

        $tuitionZ    = $streams->generateZ('degree_tuition_enrollment', 0.50);
        $enterpriseZ = $streams->generateZ('enterprise_b2b_training', 0.20);
        $lmsZ        = $streams->generateZ('digital_lms_licensing', 0.60);

        // Tuition is billed at enrollment and recognized ratably over the term from deferred revenue.
        $tuitionBook = $streams->recognizeBacklog('degree_tuition_enrollment', $expectedRevenue * $tuitionWeight,
            max(0.0, 1.0 + ($tuitionZ * ($baselineVol * self::TUITION_VARIANCE_SCALAR)) + $counterCyclicalEnrollmentBoost), self::TUITION_RECOGNITION_RATE);
        $tuitionRevenue    = $tuitionBook['revenue'];
        $enterpriseRevenue = max(0.0, $expectedRevenue * $enterpriseWeight * (1.0 + ($enterpriseZ * ($baselineVol * self::ENTERPRISE_VARIANCE_SCALAR)) + $proCyclicalEnterpriseShift));
        $lmsRevenue        = max(0.0, $expectedRevenue * $lmsWeight        * (1.0 + ($lmsZ * ($baselineVol * self::LMS_VARIANCE_SCALAR))));

        $streamRevenues = [
            'degree_tuition_enrollment' => $tuitionRevenue,
            'enterprise_b2b_training'   => $enterpriseRevenue,
            'digital_lms_licensing'     => $lmsRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // Faculty and instructor payroll follows wage growth; tuition and contract rates recover it on annual resets.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);
        $clampedMargin = $this->clampMargin($realizedVariableMargin + $inputCostDrag);

        $primaryShockZ = $streams->resolveDominantShockZ([$enterpriseZ, $tuitionZ, $lmsZ]);

        $observableShockZ = ($tuitionZ * $tuitionWeight * self::TUITION_VARIANCE_SCALAR * $baselineVol) +
            ($enterpriseZ * $enterpriseWeight * self::ENTERPRISE_VARIANCE_SCALAR * $baselineVol) +
            ($counterCyclicalEnrollmentBoost * $tuitionWeight) +
            ($proCyclicalEnterpriseShift * $enterpriseWeight);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: null,
            isPublicEvent: null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
                    kpis: ['enrollment_to_revenue' => $tuitionBook['book_to_bill'], 'deferred_tuition_quarters' => $tuitionBook['backlog_quarters']],
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
            'output_gap_ema',
            'supercore_inflation_ema',
            'tips_breakeven_ema',
            'unemployment_rate_ema',
            'wage_growth_ema',
        ];
    }
}
