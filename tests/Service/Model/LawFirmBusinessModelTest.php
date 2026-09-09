<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\LawFirmBusinessModel;
use PHPUnit\Framework\TestCase;

class LawFirmBusinessModelTest extends TestCase
{
    private LawFirmBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new LawFirmBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testTriStreamEmissionAndSumConsistency(): void
    {
        $stock = new Stock();
        $stock->setTicker('GEN_LAW');
        $stock->setBeta('0.4');

        $macro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 15_000_000.0,
            baselineVol: 0.10,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('corporate_retainers', $result->streamRevenue);
        $this->assertArrayHasKey('litigation_settlements', $result->streamRevenue);
        $this->assertArrayHasKey('restructuring_advisory', $result->streamRevenue);

        $this->assertArrayHasKey('corporate_retainers', $result->streamZ);
        $this->assertArrayHasKey('litigation_settlements', $result->streamZ);
        $this->assertArrayHasKey('restructuring_advisory', $result->streamZ);
        $this->assertArrayHasKey('event', $result->streamZ);

        $this->assertGreaterThan(0.0, $result->streamRevenue['corporate_retainers']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['litigation_settlements']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['restructuring_advisory']);

        $sumStreams = $result->streamRevenue['corporate_retainers']
            + $result->streamRevenue['litigation_settlements']
            + $result->streamRevenue['restructuring_advisory'];

        $this->assertEqualsWithDelta($result->actualRevenue, $sumStreams, 1.0);
    }

    public function testRestructuringBillingSurgesDuringCreditDistress(): void
    {
        $stock = new Stock();
        $stock->setTicker('CLAW');
        $stock->setBeta('0.3');

        $normalMacro = new MacroStateDTO(outputGapEma: 0.0, macroCreditSpread: 0.015);
        $distressMacro = new MacroStateDTO(outputGapEma: -0.03, macroCreditSpread: 0.045); // 450bps credit spread blowout

        $normalResult = $this->model->computeActualFinancials(
            $stock,
            100_000_000.0,
            0.30,
            10_000_000.0,
            0.0,
            $normalMacro,
            $this->mathUtility
        );

        $distressResult = $this->model->computeActualFinancials(
            $stock,
            100_000_000.0,
            0.30,
            10_000_000.0,
            0.0,
            $distressMacro,
            $this->mathUtility
        );

        // Restructuring advisory billing explodes when default rates and credit spreads widen
        $this->assertGreaterThan(
            $normalResult->streamRevenue['restructuring_advisory'],
            $distressResult->streamRevenue['restructuring_advisory']
        );
    }

    public function testClawTickerParameterResolution(): void
    {
        $stock = new Stock();
        $stock->setTicker('CLAW');
        $stock->setBeta('0.3');

        $macro = new MacroStateDTO(outputGapEma: 0.0, macroCreditSpread: 0.015);

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->model->computeActualFinancials(
            $stock,
            100_000_000.0,
            0.30,
            10_000_000.0,
            0.0,
            $macro,
            $mathMock
        );

        // CLAW: 40% Retainers, 35% Litigation, 25% Restructuring
        $this->assertEqualsWithDelta(40_000_000.0, $result->streamRevenue['corporate_retainers'], 1.0);
        $this->assertEqualsWithDelta(35_000_000.0, $result->streamRevenue['litigation_settlements'], 1.0);
        $this->assertEqualsWithDelta(25_000_000.0, $result->streamRevenue['restructuring_advisory'], 1.0);
    }

    public function testDealActivityAndCorporateDefaultRateChannels(): void
    {
        $stock = new Stock();
        $stock->setTicker('CLAW');
        $stock->setBeta('0.3');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $baselineMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            dealActivityIndexEma: 100.0,
            corporateDefaultRateEma: 0.020,
            macroCreditSpread: 0.015
        );

        $dealBoomMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            dealActivityIndexEma: 150.0, // Active M&A deal making
            corporateDefaultRateEma: 0.020,
            macroCreditSpread: 0.015
        );

        $defaultSurgeMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            dealActivityIndexEma: 100.0,
            corporateDefaultRateEma: 0.060, // Surging corporate default wave
            macroCreditSpread: 0.015
        );

        $baseResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.30, 10_000_000.0, 0.0, $baselineMacro, $mathMock);
        $dealResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.30, 10_000_000.0, 0.0, $dealBoomMacro, $mathMock);
        $defaultResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.30, 10_000_000.0, 0.0, $defaultSurgeMacro, $mathMock);

        $this->assertGreaterThan(
            $baseResult->streamRevenue['corporate_retainers'],
            $dealResult->streamRevenue['corporate_retainers'],
            'Active M&A deal activity expands corporate advisory and retainer billing.'
        );

        $this->assertGreaterThan(
            $baseResult->streamRevenue['restructuring_advisory'],
            $defaultResult->streamRevenue['restructuring_advisory'],
            'Surging corporate default waves expand Chapter 11 bankruptcy restructuring billing.'
        );
    }
    public function testBillingRatesPriceOffServicesInflationNotGoodsBreakevens(): void
    {
        $model = new LawFirmBusinessModel();

        // The repricing lag state persists on the stock, so each scenario gets a fresh firm with no history.
        $cheapStock = new Stock();
        $cheapStock->setTicker('LAW');
        $cheapStock->setBeta('0.8');
        $dearStock = new Stock();
        $dearStock->setTicker('LAW');
        $dearStock->setBeta('0.8');

        $cheapServices = $model->getMacroPhysics($cheapStock, new MacroStateDTO(supercoreInflationEma: 0.02, tipsBreakevenEma: 0.06));
        $dearServices  = $model->getMacroPhysics($dearStock, new MacroStateDTO(supercoreInflationEma: 0.06, tipsBreakevenEma: 0.02));

        // A goods-inflation spike with calm services prices does not lift billing rates; services inflation does.
        $this->assertGreaterThan($cheapServices['pricing_power_multiplier'], $dearServices['pricing_power_multiplier']);
        $this->assertEqualsWithDelta(1.0 + (0.06 * LawFirmBusinessModel::PRICING_ELASTICITY), $dearServices['pricing_power_multiplier'], 1e-9);
        // Input prices (associate and staff pay) follow services inflation one for one whatever the firm bills.
        $this->assertEqualsWithDelta(1.06, $dearServices['input_cost_multiplier'], 1e-9);
    }

}
