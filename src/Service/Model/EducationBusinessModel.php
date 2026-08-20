<?php

declare(strict_types=1);

namespace App\Service\Model;

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
    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.40;
    public const BASE_COVERAGE_ERROR = 0.06;

    public function getModelThresholds(): array
    {
        return ['min_icr' => 2.50, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 2.0,  'dividend_crisis_icr' => 1.75, 'buyback_min_icr' => 2.50, 'reversion_speed' => 0.10, 'moat_spread' => 0.015, 'nwc_intensity' => 0.05, 'capex_completion_rate' => 0.20];
    }

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
    public const ENTERPRISE_VARIANCE_SCALAR = 0.40; // Pro-cyclical corporate training
    public const LMS_VARIANCE_SCALAR        = 0.08; // Highly sticky software ARR

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
        $streams  = new \App\DTO\StreamContext($momentum, $mathUtility);
        $beta     = abs((float) $stock->getBeta());

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'degree_tuition_enrollment' => $params[ModelParam::DegreeTuitionWeight],
            'enterprise_b2b_training'   => $params[ModelParam::EnterpriseTrainingWeight],
            'digital_lms_licensing'     => $params[ModelParam::LmsLicensingWeight],
        ]);

        $tuitionWeight    = $activeWeights['degree_tuition_enrollment'];
        $enterpriseWeight = $activeWeights['enterprise_b2b_training'];
        $lmsWeight        = $activeWeights['digital_lms_licensing'];

        // Counter-cyclical student enrollment boost during recessions and government subsidies
        $outputGap = $macroState->outputGapEma;
        $govShift = ($macroState->governmentSpendingIndexEma - 100.0) / 100.0;
        $counterCyclicalEnrollmentBoost = ($outputGap < 0.0 ? abs($outputGap) * 1.2 * $beta : -($outputGap * 0.4)) + ($govShift * 0.40);
        $proCyclicalEnterpriseShift     = $outputGap * 1.5 * $beta;

        $tuitionZ    = $streams->generateZ('degree_tuition_enrollment', 0.50);
        $enterpriseZ = $streams->generateZ('enterprise_b2b_training', 0.20);
        $lmsZ        = $streams->generateZ('digital_lms_licensing', 0.60);

        $tuitionRevenue    = max(0.0, $expectedRevenue * $tuitionWeight    * (1.0 + ($tuitionZ * ($baselineVol * self::TUITION_VARIANCE_SCALAR)) + $counterCyclicalEnrollmentBoost));
        $enterpriseRevenue = max(0.0, $expectedRevenue * $enterpriseWeight * (1.0 + ($enterpriseZ * ($baselineVol * self::ENTERPRISE_VARIANCE_SCALAR)) + $proCyclicalEnterpriseShift));
        $lmsRevenue        = max(0.0, $expectedRevenue * $lmsWeight        * (1.0 + ($lmsZ * ($baselineVol * self::LMS_VARIANCE_SCALAR))));

        $streamRevenues = [
            'degree_tuition_enrollment' => $tuitionRevenue,
            'enterprise_b2b_training'   => $enterpriseRevenue,
            'digital_lms_licensing'     => $lmsRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        $clampedMargin = $this->clampMargin($realizedVariableMargin);

        $primaryShockZ = abs($enterpriseZ) > abs($tuitionZ) ? $enterpriseZ : $tuitionZ;
        if (abs($lmsZ) > abs($primaryShockZ)) {
            $primaryShockZ = $lmsZ;
        }

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
        );
    }
}
