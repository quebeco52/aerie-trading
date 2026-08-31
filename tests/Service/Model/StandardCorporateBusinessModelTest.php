<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class StandardCorporateBusinessModelTest extends TestCase
{
    private StandardCorporateBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new StandardCorporateBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testGetTargetMetricsBlendsBaselineAndTtmRoic(): void
    {
        $stock = new Stock();
        $stock->setTicker('CORP');
        $stock->setTotalEquity('100000000.0');
        $stock->setWholesaleDebt('50000000.0');
        $stock->setCorporateTreasury('10000000.0');
        $stock->setBaselineRoic('0.20');
        $stock->setRoicTtm('0.10');

        $macroState = new MacroStateDTO(
            policyRate: 0.04,
            equityRiskPremium: 0.05
        );

        $metrics = $this->model->getTargetMetrics($stock, $macroState, $this->mathUtility);

        $this->assertArrayHasKey('invested_capital', $metrics);
        $this->assertArrayHasKey('baseline_roic', $metrics);

        // Blended ROIC: (0.20 * 0.50) + (0.10 * 0.50) = 0.15
        $this->assertEqualsWithDelta(0.15, $metrics['baseline_roic'], 0.01);
        $this->assertEqualsWithDelta(140000000.0, $metrics['invested_capital'], 1.0);
    }

    public function testMacroPhysicsDemandShiftAndPricingPower(): void
    {
        $stock = new Stock();
        $stock->setTicker('CORP');
        $stock->setBeta('1.2');

        $macro = new MacroStateDTO(
            outputGapEma: 0.02,
            inflationEma: 0.05,
            exchangeRateIndexEma: 110.0
        );

        $physics = $this->model->getMacroPhysics($stock, $macro);

        $this->assertArrayHasKey('macro_demand_shift', $physics);
        $this->assertArrayHasKey('pricing_power_multiplier', $physics);
        $this->assertGreaterThan(1.0, $physics['pricing_power_multiplier']);
    }

    public function testHighInflationCompressesMarginsForLowPricingPower(): void
    {
        $stockLow = new Stock();
        $stockLow->setTicker('LOW_PRICING');
        $stockLow->setBeta('1.0');

        // Inflation at 6% (well above 2% target)
        $highInflationMacro = new MacroStateDTO(inflationEma: 0.06);

        $result = $this->model->computeActualFinancials(
            $stockLow,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 10_000_000.0,
            baselineVol: 0.0,
            macroState: $highInflationMacro,
            mathUtility: $this->mathUtility
        );

        $this->assertGreaterThan(0.0, $result->actualRevenue);
        $this->assertGreaterThanOrEqual(0.01, $result->clampedMargin);
    }

    public function testCalculateEarningsValueWithPositiveFcfDoesNotDoubleAnnualize(): void
    {
        $revenueFloorValue = 50.0;
        $peFairValue = 100.0;
        $annualFcfPerShare = 4.0; // Already annualized $4.00/share FCF
        $liveWacc = 0.08; // 8% WACC

        $earningsValue = $this->model->calculateEarningsValue(
            $revenueFloorValue,
            $peFairValue,
            $annualFcfPerShare,
            $liveWacc,
            $this->mathUtility
        );

        $multiplier = $this->mathUtility->calculateDcfMultiplier($liveWacc, StandardCorporateBusinessModel::DCF_TERMINAL_GROWTH_RATE);
        $expectedDcf = min(max(0.01, $annualFcfPerShare * $multiplier), $peFairValue * StandardCorporateBusinessModel::MAX_DCF_TO_PE_CAP_MULT);
        $expectedEarningsValue = ($peFairValue + $expectedDcf) / 2.0;

        $this->assertEqualsWithDelta($expectedEarningsValue, $earningsValue, 0.0001);
    }
}
