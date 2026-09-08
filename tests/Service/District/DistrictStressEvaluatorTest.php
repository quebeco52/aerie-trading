<?php

declare(strict_types=1);

namespace App\Tests\Service\District;

use App\Data\DistrictMap;
use App\DTO\MacroStateDTO;
use App\Service\District\DistrictStressEvaluator;
use App\Service\Macro\MacroEngine;
use App\Service\Model\Sector\ClearingHouseBusinessModel;
use PHPUnit\Framework\TestCase;

/**
 * Pins each institution's resting-state stress predicate to the exact simulation constant it
 * reuses — a calm baseline must never light a conduit, and crossing the named threshold must
 * always light it.
 */
class DistrictStressEvaluatorTest extends TestCase
{
    private DistrictStressEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new DistrictStressEvaluator();
    }

    public function testCalmBaselineStressesNoInstitution(): void
    {
        $stress = $this->evaluator->evaluate(new MacroStateDTO());

        foreach (array_keys(DistrictMap::INSTITUTIONS) as $institutionId) {
            $this->assertArrayHasKey($institutionId, $stress);
            $this->assertFalse($stress[$institutionId], "{$institutionId} must not be stressed at the default MacroStateDTO baseline.");
        }
    }

    public function testRateCouncilStressedByPersistentInversion(): void
    {
        $macro = new MacroStateDTO(inversionDuration: MacroEngine::SYSTEMIC_INVERSION_ALARM_YEARS);

        $this->assertTrue($this->evaluator->evaluate($macro)['rate-council']);
    }

    public function testRateCouncilStressedNearTheZeroLowerBound(): void
    {
        $macro = new MacroStateDTO(policyRateEma: MacroEngine::ZLB_PROXIMITY_THRESHOLD);

        $this->assertTrue($this->evaluator->evaluate($macro)['rate-council']);
    }

    public function testCreditRegistryStressedByInterbankFreeze(): void
    {
        $macro = new MacroStateDTO(interbankLiquiditySpreadEma: MacroEngine::SYSTEMIC_LIQUIDITY_FREEZE_SPREAD);

        $this->assertTrue($this->evaluator->evaluate($macro)['credit-registry']);
    }

    public function testCreditRegistryStressedByHighYieldSeizure(): void
    {
        $macro = new MacroStateDTO(highYieldCreditSpreadEma: MacroEngine::SYSTEMIC_CREDIT_SEIZURE_SPREAD);

        $this->assertTrue($this->evaluator->evaluate($macro)['credit-registry']);
    }

    public function testCreditRegistryStressedByDeclaredRecessionProbability(): void
    {
        $macro = new MacroStateDTO(recessionProbabilityEma: MacroEngine::SYSTEMIC_RECESSION_DECLARE_PROBABILITY);

        $this->assertTrue($this->evaluator->evaluate($macro)['credit-registry']);
    }

    public function testExchangeFloorStressedAtExtremeVolatility(): void
    {
        $macro = new MacroStateDTO(marketVolatilityEma: ClearingHouseBusinessModel::VIX_EXTREME_THRESHOLD);

        $this->assertTrue($this->evaluator->evaluate($macro)['exchange-floor']);
    }

    public function testStatisticalOfficeStressedByInflationPanic(): void
    {
        $macro = new MacroStateDTO(inflationEma: MacroEngine::CB_INFLATION_PANIC_THRESHOLD);

        $this->assertTrue($this->evaluator->evaluate($macro)['statistical-office']);
    }

    public function testStatisticalOfficeStressedByRecessionaryOutputGap(): void
    {
        $macro = new MacroStateDTO(outputGapEma: MacroEngine::SYSTEMIC_RECESSION_DECLARE_GAP);

        $this->assertTrue($this->evaluator->evaluate($macro)['statistical-office']);
    }

    public function testStatisticalOfficeStressedByElevatedUnemployment(): void
    {
        $macro = new MacroStateDTO(unemploymentRateEma: MacroEngine::EVANS_RULE_UNEMPLOYMENT);

        $this->assertTrue($this->evaluator->evaluate($macro)['statistical-office']);
    }

    public function testLandRegistryStressedByCommercialPropertyDeviation(): void
    {
        // A hair past the threshold, not exactly on it — the boundary itself is exercised
        // separately below, where float rounding at exact equality is handled deliberately.
        $macro = new MacroStateDTO(
            commercialPropertyIndexEma: 100.0 * (1.0 + DistrictMap::LAND_REGISTRY_PROPERTY_STRESS_DEVIATION) + 0.5,
        );

        $this->assertTrue($this->evaluator->evaluate($macro)['land-registry']);
    }

    public function testLandRegistryStressedByResidentialPropertyCollapse(): void
    {
        $macro = new MacroStateDTO(
            residentialPropertyIndexEma: 100.0 * (1.0 - DistrictMap::LAND_REGISTRY_PROPERTY_STRESS_DEVIATION) - 0.5,
        );

        $this->assertTrue($this->evaluator->evaluate($macro)['land-registry']);
    }

    public function testJustBelowThresholdDoesNotStress(): void
    {
        $macro = new MacroStateDTO(highYieldCreditSpreadEma: MacroEngine::SYSTEMIC_CREDIT_SEIZURE_SPREAD - 0.001);

        $this->assertFalse($this->evaluator->evaluate($macro)['credit-registry']);
    }
}
