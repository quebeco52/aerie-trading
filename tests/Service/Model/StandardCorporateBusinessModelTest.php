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

    /**
     * The target return is what EarningsEngine::calculateGrowthCapEx tests against the hurdle, so
     * saturation has to be able to push it BELOW the hurdle — that is the NPV rule doing its job. It used
     * to be floored at policy rate + ERP, which pinned it at the cost of equity from the first saturated
     * quarter on and left the growth gate reading a constant while capital ran past the whole market.
     */
    public function testSaturationCanPushTheTargetReturnBelowTheHurdle(): void
    {
        $macroState = new MacroStateDTO(policyRate: 0.04, equityRiskPremium: 0.05, nominalGdpIndex: 1.0);
        $costOfEquity = 0.09;

        $unsaturated = new Stock();
        $unsaturated->setTicker('CORP');
        $unsaturated->setSamRatio('1.00');
        $unsaturated->setBaselineRoic('0.12');
        $unsaturated->setRoicTtm('0.12');
        $unsaturated->setTotalEquity('100000000000.0'); // 10% of a $1T market: no diseconomy yet

        $saturated = new Stock();
        $saturated->setTicker('CORP');
        $saturated->setSamRatio('1.00');
        $saturated->setBaselineRoic('0.12');
        $saturated->setRoicTtm('0.12');
        $saturated->setTotalEquity('1500000000000.0'); // 150% of the market it serves

        $this->assertEqualsWithDelta(0.12, $this->model->getTargetMetrics($unsaturated, $macroState, $this->mathUtility)['baseline_roic'], 1e-9, 'Below optimal scale the structural return is untouched.');

        $saturatedReturn = $this->model->getTargetMetrics($saturated, $macroState, $this->mathUtility)['baseline_roic'];
        $this->assertLessThan($costOfEquity, $saturatedReturn, 'A firm whose capital exceeds its market must see a marginal return below its cost of capital, or growth never stops.');
        $this->assertGreaterThanOrEqual(0.0, $saturatedReturn, 'A marginal return is a rate on the next dollar; below zero it is simply not invested.');
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

    public function testInflationPassThroughIsKeyedOnPricingPowerNotBeta(): void
    {
        $macro = new MacroStateDTO(tipsBreakevenEma: 0.04);

        // Same firm, same pricing power, wildly different systematic risk.
        $defensive = new Stock();
        $defensive->setTicker('IBHI');
        $defensive->setBeta('0.50');

        $cyclical = new Stock();
        $cyclical->setTicker('IBHI');
        $cyclical->setBeta('2.50');

        $defensiveMultiplier = $this->model->getMacroPhysics($defensive, $macro)['pricing_power_multiplier'];
        $cyclicalMultiplier = $this->model->getMacroPhysics($cyclical, $macro)['pricing_power_multiplier'];

        // Keying pass-through off beta gave these 1.02 and 1.10: a five-fold difference in the price a firm
        // can charge, driven by a measure of market risk that already scales the demand shift separately.
        $this->assertEqualsWithDelta(
            $defensiveMultiplier,
            $cyclicalMultiplier,
            1e-9,
            'Beta must not change how much inflation a firm recovers in price.'
        );

        // Pricing power does drive it: a price taker recovers less than a price setter.
        $priceSetter = new Stock();
        $priceSetter->setTicker('RIVE'); // Pricing power 0.85 against IBHI's 0.35
        $priceSetter->setBeta('0.50');

        $this->assertGreaterThan(
            $defensiveMultiplier,
            $this->model->getMacroPhysics($priceSetter, $macro)['pricing_power_multiplier']
        );

        // Elasticity is centred so the median firm recovers expected inflation exactly.
        $this->assertEqualsWithDelta(1.0 + (0.04 * (0.50 + 0.35)), $defensiveMultiplier, 1e-9);
    }

    public function testInflationPassThroughReachesNewExpectationsGraduallyNotInOneQuarter(): void
    {
        $stock = new Stock();
        $stock->setTicker('RIVE'); // Pricing power 0.85, so elasticity 1.35
        $stock->setBeta('1.00');

        // First report seeds at the target: with no repricing history the firm is assumed at equilibrium.
        $settled = $this->model->getMacroPhysics($stock, new MacroStateDTO(tipsBreakevenEma: 0.02));
        $this->assertEqualsWithDelta(1.0 + (0.02 * 1.35), $settled['pricing_power_multiplier'], 1e-9);

        // Expectations triple. Menu costs and contract terms mean posted prices cannot follow at once.
        $shocked = $this->model->getMacroPhysics($stock, new MacroStateDTO(tipsBreakevenEma: 0.06));
        $shockedPassThrough = $shocked['pricing_power_multiplier'] - 1.0;

        $this->assertGreaterThan(0.02 * 1.35, $shockedPassThrough, 'Prices must start moving toward the new level.');
        $this->assertLessThan(0.06 * 1.35, $shockedPassThrough, 'Prices must not reprice fully within one quarter.');

        // One quarterly step of an exponential lag on a three-quarter time constant.
        $weight = 1.0 - exp(-0.25 / StandardCorporateBusinessModel::PRICE_PASS_THROUGH_LAG_YEARS);
        $expected = (0.02 * 1.35) + $weight * ((0.06 * 1.35) - (0.02 * 1.35));
        $this->assertEqualsWithDelta($expected, $shockedPassThrough, 1e-9);

        // Held there, the firm converges on full pass-through rather than stalling part way.
        for ($quarter = 0; $quarter < 20; $quarter++) {
            $converged = $this->model->getMacroPhysics($stock, new MacroStateDTO(tipsBreakevenEma: 0.06));
        }
        $this->assertEqualsWithDelta(1.0 + (0.06 * 1.35), $converged['pricing_power_multiplier'], 1e-4);
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
