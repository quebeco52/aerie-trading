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
    /** Sensitivity of hospital variable cost margin to clinical wage inflation (nursing/physician overtime). */
    public const WAGE_INFLATION_PENALTY_SCALAR = 1.00;

    /** Multiplier for insurance arbitrage revenue expansion during high healthcare inflation regimes. */
    public const MEDICAL_CPI_EXPANSION_SCALAR = 1.50;

    /** Maximum percentage of clinical wage inflation mitigated by pristine pricing power. */
    public const MAX_PRICING_POWER_MITIGATION = 0.60;

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
        $physics['pricing_power_multiplier'] = 1.0;

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
        $streams = new StreamContext($momentum, $mathUtility);
        $beta = abs((float) $stock->getBeta());

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
        $eventZ      = $streams->generateZ('event', 0.10);

        // --- Macro Demand Sensitivities ---
        $outputGap = $macroState->outputGapEma;
        $inflation = $macroState->inflationEma;

        // Inpatient care is completely inelastic (0.0 output gap sensitivity)
        // Elective outpatient procedures are pro-cyclical with consumer wealth
        $outpatientMacroBoost = ($outputGap * 1.2 * $beta);

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

        $streamRevenues = [
            'inpatient_care'      => $inpatientRevenue,
            'elective_outpatient' => $outpatientRevenue,
            'insurance_arbitrage' => $arbitrageRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // --- Clinical Wage Inflation Squeeze & Pricing Power Mitigation ---
        $baseWageInflationPenalty = $inflationExcess * $beta * self::WAGE_INFLATION_PENALTY_SCALAR;
        $effectiveWageDrag = $baseWageInflationPenalty * (1.0 - ($pricingPower * self::MAX_PRICING_POWER_MITIGATION));

        $rawMargin = $realizedVariableMargin + $effectiveWageDrag + $auditPenalty;
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
        );
    }
}
