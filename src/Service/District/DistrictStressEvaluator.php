<?php

declare(strict_types=1);

namespace App\Service\District;

use App\Data\DistrictMap;
use App\DTO\MacroStateDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Model\Sector\ClearingHouseBusinessModel;

/**
 * Decides which district institutions are "stressed" — the gate for which macro conduits render
 * on a ward at rest, before any building or institution is selected.
 *
 * Every predicate reuses a threshold the simulation itself already treats as a distress signal
 * (one of MacroEngine's systemic-event constants, or a business model's own named crisis
 * threshold) rather than inventing a new one, per the district's no-invented-math rule — see the
 * class docblock on App\Data\DistrictMap. The one exception is the Land Registry's property-index
 * deviation, where no such constant exists anywhere in the simulation; that threshold is defined
 * locally on DistrictMap under its own docblock.
 */
class DistrictStressEvaluator
{
    /**
     * @return array<string, bool> institution id => stressed
     */
    public function evaluate(MacroStateDTO $macro): array
    {
        return [
            'rate-council' => $this->isRateCouncilStressed($macro),
            'credit-registry' => $this->isCreditRegistryStressed($macro),
            'exchange-floor' => $this->isExchangeFloorStressed($macro),
            'statistical-office' => $this->isStatisticalOfficeStressed($macro),
            'land-registry' => $this->isLandRegistryStressed($macro),
        ];
    }

    /** A yield curve inversion that has persisted long enough to count as a systemic alarm, or a policy rate pinned near the zero lower bound. */
    private function isRateCouncilStressed(MacroStateDTO $macro): bool
    {
        return $macro->inversionDuration >= MacroEngine::SYSTEMIC_INVERSION_ALARM_YEARS
            || $macro->policyRateEma <= MacroEngine::ZLB_PROXIMITY_THRESHOLD;
    }

    /** An interbank funding freeze, a high-yield credit seizure, or a declared recession probability. */
    private function isCreditRegistryStressed(MacroStateDTO $macro): bool
    {
        return $macro->interbankLiquiditySpreadEma >= MacroEngine::SYSTEMIC_LIQUIDITY_FREEZE_SPREAD
            || $macro->highYieldCreditSpreadEma >= MacroEngine::SYSTEMIC_CREDIT_SEIZURE_SPREAD
            || $macro->recessionProbabilityEma >= MacroEngine::SYSTEMIC_RECESSION_DECLARE_PROBABILITY;
    }

    /** Volatility at the extreme threshold ClearingHouseBusinessModel itself treats as a panic regime. */
    private function isExchangeFloorStressed(MacroStateDTO $macro): bool
    {
        return $macro->marketVolatilityEma >= ClearingHouseBusinessModel::VIX_EXTREME_THRESHOLD;
    }

    /** Inflation past the central bank's own panic threshold, a recessionary output gap, or unemployment past the Evans Rule level. */
    private function isStatisticalOfficeStressed(MacroStateDTO $macro): bool
    {
        return $macro->inflationEma >= MacroEngine::CB_INFLATION_PANIC_THRESHOLD
            || $macro->outputGapEma <= MacroEngine::SYSTEMIC_RECESSION_DECLARE_GAP
            || $macro->unemploymentRateEma >= MacroEngine::EVANS_RULE_UNEMPLOYMENT;
    }

    /** Commercial or residential property valuations displaced far enough from their 100.0 baseline to read as a real-estate shock. */
    private function isLandRegistryStressed(MacroStateDTO $macro): bool
    {
        $commercialShift = abs($macro->commercialPropertyIndexEma - 100.0) / 100.0;
        $residentialShift = abs($macro->residentialPropertyIndexEma - 100.0) / 100.0;

        return $commercialShift >= DistrictMap::LAND_REGISTRY_PROPERTY_STRESS_DEVIATION
            || $residentialShift >= DistrictMap::LAND_REGISTRY_PROPERTY_STRESS_DEVIATION;
    }
}
