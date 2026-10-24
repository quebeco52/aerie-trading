<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\LawFirmBusinessModel;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class LawFirmBusinessModelTest extends TestCase
{
    private LawFirmBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new LawFirmBusinessModel();
        $this->mathUtility = MathUtility::ownStream(20261024);
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
            mathUtility: $this->mathUtility,
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
        // 450bps credit spread blowout
        $distressMacro = new MacroStateDTO(outputGapEma: -0.03, macroCreditSpread: 0.045);

        $normalResult = $this->model->computeActualFinancials(
            $stock,
            100_000_000.0,
            0.30,
            10_000_000.0,
            0.0,
            $normalMacro,
            $this->mathUtility,
        );

        $distressResult = $this->model->computeActualFinancials(
            $stock,
            100_000_000.0,
            0.30,
            10_000_000.0,
            0.0,
            $distressMacro,
            $this->mathUtility,
        );

        // Restructuring advisory billing explodes when default rates and credit spreads widen
        $this->assertGreaterThan(
            $normalResult->streamRevenue['restructuring_advisory'],
            $distressResult->streamRevenue['restructuring_advisory'],
        );
    }

    public function testClawTickerParameterResolution(): void
    {
        $stock = new Stock();
        $stock->setTicker('CLAW');
        $stock->setBeta('0.3');

        $macro = new MacroStateDTO(outputGapEma: 0.0, macroCreditSpread: 0.015, macroCreditSpreadTrend: 0.015);

        $mathMock = new ScriptedDraws(generatePersistentZ: 0.0);

        $result = $this->model->computeActualFinancials(
            $stock,
            100_000_000.0,
            0.30,
            10_000_000.0,
            0.0,
            $macro,
            $mathMock,
        );

        // CLAW: 40% Retainers, 35% Litigation, 25% Restructuring; a quarter with no verdict sits the verdicts' mean
        // below the average year.
        $this->assertEqualsWithDelta(40_000_000.0, $result->streamRevenue['corporate_retainers'], 1.0);
        $this->assertEqualsWithDelta(35_000_000.0 / LawFirmBusinessModel::expectedVerdictMultiplier(), $result->streamRevenue['litigation_settlements'], 1.0);
        $this->assertEqualsWithDelta(25_000_000.0, $result->streamRevenue['restructuring_advisory'], 1.0);
    }

    /**
     * Restructuring work reads the credit spread as it stands. It used to swap in the smoothed spread whenever the spot
     * sat exactly at its opening value, which the driver attribution's neutral run sets, so the earnings report
     * credited the spread with a move the model never made.
     */
    public function testRestructuringReadsTheSpotSpreadEvenAtItsOpeningValue(): void
    {
        $stock = (new Stock())->setTicker('CLAW');
        $mathMock = new ScriptedDraws(generatePersistentZ: 0.0);

        $atOpening = static fn(float $smoothed): MacroStateDTO => new MacroStateDTO(
            outputGapEma: 0.0,
            macroCreditSpread: MacroConstants::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: $smoothed,
        );

        $calm = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.30, 10_000_000.0, 0.0, $atOpening(0.010), $mathMock);
        $wide = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.30, 10_000_000.0, 0.0, $atOpening(0.040), $mathMock);

        $this->assertEqualsWithDelta($calm->streamRevenue['restructuring_advisory'], $wide->streamRevenue['restructuring_advisory'], 1e-6);
    }

    public function testDealActivityAndCorporateDefaultRateChannels(): void
    {
        $stock = new Stock();
        $stock->setTicker('CLAW');
        $stock->setBeta('0.3');

        $mathMock = new ScriptedDraws(generatePersistentZ: 0.0);

        $baselineMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            dealActivityIndexEma: 100.0,
            corporateDefaultRateEma: 0.020,
            macroCreditSpread: 0.015,
        );

        $dealBoomMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            dealActivityIndexEma: 150.0, // Active M&A deal making
            corporateDefaultRateEma: 0.020,
            macroCreditSpread: 0.015,
        );

        $defaultSurgeMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            dealActivityIndexEma: 100.0,
            corporateDefaultRateEma: 0.060, // Surging corporate default wave
            macroCreditSpread: 0.015,
        );

        $baseResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.30, 10_000_000.0, 0.0, $baselineMacro, $mathMock);
        $dealResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.30, 10_000_000.0, 0.0, $dealBoomMacro, $mathMock);
        $defaultResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.30, 10_000_000.0, 0.0, $defaultSurgeMacro, $mathMock);

        $this->assertGreaterThan(
            $baseResult->streamRevenue['corporate_retainers'],
            $dealResult->streamRevenue['corporate_retainers'],
            'Active M&A deal activity expands corporate advisory and retainer billing.',
        );

        $this->assertGreaterThan(
            $baseResult->streamRevenue['restructuring_advisory'],
            $defaultResult->streamRevenue['restructuring_advisory'],
            'Surging corporate default waves expand Chapter 11 bankruptcy restructuring billing.',
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

    public function testTheCycleReachesTheCostBaseThroughTheStreamVolumes(): void
    {
        $stock = (new Stock())->setTicker('BRIEF_ACTIVITY')->setBeta('1.0');
        // Spreads, defaults and deal flow at their trends: only the gap moves.
        $recession = new MacroStateDTO(outputGapEma: -0.03, macroCreditSpread: MacroConstants::CREDIT_SPREAD_LONG_RUN_MEAN, exchangeRateIndexEma: 100.0);

        $this->assertEqualsWithDelta(0.0, $this->model->getMacroPhysics($stock, $recession)['macro_demand_shift'], 1e-12);

        // Restructuring rises against the gap, 0.20 x (0.03 x 3.0 x 0.80) = +0.0144, and retainers fall with it,
        // 0.45 x (0.03 x 0.60 x 0.80) = -0.00648: +0.00792.
        $expected = (LawFirmBusinessModel::RESTRUCTURING_ADVISORY_WEIGHT
                * (0.03 * LawFirmBusinessModel::RESTRUCTURING_RECESSION_SCALAR * LawFirmBusinessModel::OPERATING_CYCLICALITY))
            - (LawFirmBusinessModel::CORPORATE_RETAINER_WEIGHT
                * (0.03 * LawFirmBusinessModel::RETAINER_MACRO_SCALAR * LawFirmBusinessModel::OPERATING_CYCLICALITY));
        $this->assertEqualsWithDelta($expected, $this->model->resolveSectorActivityShift($stock, $recession), 1e-9);
    }

    /**
     * A landmark verdict is the event draw's outcome: a settlement flow far above its mean books its own lumpiness and
     * no verdict on top, which counted that draw's tail twice.
     */
    public function testAVerdictIsTheEventDrawNotTheSettlementFlowsTail(): void
    {
        $quarter = function (float $litigationZ, float $eventZ): \App\DTO\ActualFinancialsDTO {
            $math = new ScriptedDraws();
            $stock = (new Stock())->setTicker('CLAW')->setBeta('0.3');
            $stock->setEarningsMomentumZ(['litigation_settlements' => $litigationZ / 0.10, 'event' => $eventZ / 0.10]);

            return $this->model->computeActualFinancials($stock, 100_000_000.0, 0.30, 10_000_000.0, 0.10, new MacroStateDTO(), $math);
        };

        $this->assertNull($quarter(2.5, 0.0)->eventType, 'A strong settlement flow is not a verdict.');
        $win = $quarter(0.0, 2.5);
        $this->assertSame(\App\Service\Event\ShockEvent::LITIGATION_SETTLEMENT_WIN, $win->eventType);
        $this->assertEqualsWithDelta(
            35_000_000.0 * LawFirmBusinessModel::LITIGATION_WIN_MULT / LawFirmBusinessModel::expectedVerdictMultiplier(),
            $win->streamRevenue['litigation_settlements'],
            1.0,
        );
    }
}
