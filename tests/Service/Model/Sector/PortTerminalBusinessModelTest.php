<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\Subsystem\Aggregate\OpenEconomy;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\PortTerminalBusinessModel;
use PHPUnit\Framework\TestCase;

final class PortTerminalBusinessModelTest extends TestCase
{
    private const EXPECTED_REVENUE = 100.0;
    private const VARIABLE_MARGIN = 0.25;

    private PortTerminalBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new PortTerminalBusinessModel();
    }

    /**
     * Every demand draw at zero; the exogenous event draw (no common loading) at $eventZ, and the strike's exit
     * draw answering $settles.
     */
    private function draws(float $eventZ = 0.0, bool $settles = false): MathUtility
    {
        return new class ($eventZ, $settles) extends MathUtility {
            public function __construct(private readonly float $eventZ, private readonly bool $settles) {}

            public function generatePersistentZ(float $previousZ, float $phi, ?float $commonInnovation = null, float $commonLoading = 0.0): float
            {
                return $commonInnovation === null ? $this->eventZ : 0.0;
            }

            public function generateStandardNormal(): float
            {
                return 0.0;
            }

            public function checkProbability(float $probability): bool
            {
                return $this->settles;
            }
        };
    }

    private function terminal(): Stock
    {
        $stock = new Stock();
        $stock->setTicker('GEN_PORT');

        return $stock;
    }

    private function report(Stock $stock, MacroStateDTO $macro, ?MathUtility $draws = null): ActualFinancialsDTO
    {
        $actuals = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: self::EXPECTED_REVENUE,
            realizedVariableMargin: self::VARIABLE_MARGIN,
            fixedCosts: 0.0,
            baselineVol: 0.20,
            macroState: $macro,
            mathUtility: $draws ?? $this->draws(),
        );
        $stock->setEarningsMomentumZ($actuals->streamZ);

        return $actuals;
    }

    private static function exportShare(): float
    {
        return OpenEconomy::goodsExportShareOfTrade();
    }

    /**
     * The dwell factor at a given supply-chain pressure: log-linear, Jensen-compensated at the index's unit variance.
     */
    private static function dwell(float $pressure): float
    {
        $s = PortTerminalBusinessModel::STORAGE_DWELL_SENSITIVITY;

        return exp(($s * $pressure) - (0.5 * $s * $s * PortTerminalBusinessModel::SUPPLY_CHAIN_PRESSURE_VARIANCE));
    }

    public function testAtTrendTheTerminalEarnsItsExpectedRevenueLessTheDwellCompensation(): void
    {
        $actuals = $this->report($this->terminal(), new MacroStateDTO());

        // At the index's median the dwell factor sits the Jensen term under one, so that it averages one over the
        // cycle.
        $storageBase = self::EXPECTED_REVENUE * PortTerminalBusinessModel::STORAGE_SERVICES_WEIGHT;
        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE - ($storageBase * (1.0 - self::dwell(0.0))), $actuals->actualRevenue, 1e-9);
        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE * PortTerminalBusinessModel::GATEWAY_HANDLING_WEIGHT, $actuals->streamRevenue['gateway_handling'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $actuals->kpis['throughput_index'], 1e-12);
        $this->assertEqualsWithDelta(self::dwell(0.0), $actuals->kpis['dwell_index'], 1e-12);
        $this->assertNull($actuals->eventType);
    }

    public function testTheDwellFactorAveragesOneOverAStandardNormalPressure(): void
    {
        // Gauss-Hermite by brute force: integrate the factor over N(0, 1).
        $mean = 0.0;
        $weight = 0.0;
        for ($x = -8.0; $x <= 8.0; $x += 0.001) {
            $density = exp(-0.5 * $x * $x);
            $mean += $density * self::dwell($x);
            $weight += $density;
        }

        $this->assertEqualsWithDelta(1.0, $mean / $weight, 1e-9);
    }

    public function testGatewayImportsFollowDomesticDemandAndRelaysDoNot(): void
    {
        $actuals = $this->report($this->terminal(), new MacroStateDTO(domesticDemandGapEma: -0.03));

        $importShift = (1.0 - self::exportShare()) * OpenEconomy::goodsImportVolumeGap(-0.03, 0.0);
        $gatewayBase = self::EXPECTED_REVENUE * PortTerminalBusinessModel::GATEWAY_HANDLING_WEIGHT;
        $this->assertLessThan(0.0, $importShift);
        $this->assertEqualsWithDelta($gatewayBase * (1.0 + $importShift), $actuals->streamRevenue['gateway_handling'], 1e-9);
        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE * PortTerminalBusinessModel::TRANSSHIPMENT_WEIGHT, $actuals->streamRevenue['transshipment'], 1e-9);
    }

    public function testATariffCostsGatewayBoxesBothWaysButNotRelaysInBond(): void
    {
        $duty = log(1.10);
        $actuals = $this->report($this->terminal(), new MacroStateDTO(tariffTradeLag: $duty));

        // Imports lose volume on the duty; exports on what partners answer with.
        $expectedShift = (self::exportShare() * OpenEconomy::goodsExportVolumeGap(0.0, 0.0, $duty))
            + ((1.0 - self::exportShare()) * OpenEconomy::goodsImportVolumeGap(0.0, 0.0, $duty));
        $gatewayBase = self::EXPECTED_REVENUE * PortTerminalBusinessModel::GATEWAY_HANDLING_WEIGHT;

        $this->assertLessThan(0.0, $expectedShift);
        $this->assertEqualsWithDelta($gatewayBase * (1.0 + $expectedShift), $actuals->streamRevenue['gateway_handling'], 1e-9);
        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE * PortTerminalBusinessModel::TRANSSHIPMENT_WEIGHT, $actuals->streamRevenue['transshipment'], 1e-9);
    }

    public function testRelaysFollowWorldTradeAndLeaveWhenTheCurrencyMakesTheHubDear(): void
    {
        $relayBase = self::EXPECTED_REVENUE * PortTerminalBusinessModel::TRANSSHIPMENT_WEIGHT;

        $slump = $this->report($this->terminal(), new MacroStateDTO(foreignOutputGapEma: -0.02, worldActivityGap: -0.02));
        $this->assertEqualsWithDelta($relayBase * (1.0 - (PortTerminalBusinessModel::TRANSSHIPMENT_TRADE_INCOME_ELASTICITY * 0.02)), $slump->streamRevenue['transshipment'], 1e-9);

        $dear = $this->report($this->terminal(), new MacroStateDTO(realExchangeRateTradeLag: 0.10));
        $hubPriceLoss = OpenEconomy::exportPriceVolumeLoss(0.10);
        $this->assertGreaterThan(0.0, $hubPriceLoss);
        $this->assertEqualsWithDelta($relayBase * (1.0 - $hubPriceLoss), $dear->streamRevenue['transshipment'], 1e-9);
    }

    public function testCongestionEarnsStorageAsPriceOnSpaceAlreadyBuilt(): void
    {
        $storageBase = self::EXPECTED_REVENUE * PortTerminalBusinessModel::STORAGE_SERVICES_WEIGHT;

        $congested = $this->report($this->terminal(), new MacroStateDTO(supplyChainPressureIndexEma: 2.0));
        $dwell = self::dwell(2.0);
        $this->assertGreaterThan(1.4, $dwell);
        $this->assertEqualsWithDelta($dwell, $congested->kpis['dwell_index'], 1e-12);
        $this->assertEqualsWithDelta($storageBase * $dwell, $congested->streamRevenue['storage_services'], 1e-9);
        // The uplift carries no variable cost: only the volume part of revenue is costed.
        $this->assertEqualsWithDelta($storageBase * ($dwell - 1.0), $congested->priceRevenue, 1e-9);
        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE * self::VARIABLE_MARGIN, $congested->actualVariableCosts, 1e-9);
    }

    public function testSlackDwellForgoesStorageWithoutACostSaving(): void
    {
        $slack = $this->report($this->terminal(), new MacroStateDTO(supplyChainPressureIndexEma: -3.0));

        $this->assertLessThan(0.55, $slack->kpis['dwell_index']);
        $this->assertSame(0.0, $slack->priceRevenue);
        // Boxes handled are the same; the yard simply earns less on them, so variable costs do not fall.
        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE * self::VARIABLE_MARGIN, $slack->actualVariableCosts, 1e-9);
        $this->assertLessThan(self::EXPECTED_REVENUE, $slack->actualRevenue);
    }

    public function testADockStrikeLosesRelaysAndDefersTheDistrictsOwnBoxes(): void
    {
        $stock = $this->terminal();
        $gatewayBase = self::EXPECTED_REVENUE * PortTerminalBusinessModel::GATEWAY_HANDLING_WEIGHT;
        $relayBase = self::EXPECTED_REVENUE * PortTerminalBusinessModel::TRANSSHIPMENT_WEIGHT;
        $loss = PortTerminalBusinessModel::DOCK_STRIKE_THROUGHPUT_LOSS;

        $walkout = $this->report($stock, new MacroStateDTO(), $this->draws(eventZ: PortTerminalBusinessModel::DOCK_STRIKE_Z_SCORE - 0.5));
        $this->assertSame(ShockEvent::DOCK_STRIKE, $walkout->eventType);
        $this->assertEqualsWithDelta($gatewayBase * (1.0 - $loss), $walkout->streamRevenue['gateway_handling'], 1e-9);
        $this->assertEqualsWithDelta($relayBase * (1.0 - $loss), $walkout->streamRevenue['transshipment'], 1e-9);
        // Yard services follow the boxes handled.
        $this->assertEqualsWithDelta(1.0 - $loss, $walkout->kpis['throughput_index'], 1e-12);

        $held = $this->report($stock, new MacroStateDTO(), $this->draws(settles: false));
        $this->assertNull($held->eventType, 'Only the walkout is news; the strike that holds is the same strike.');
        $this->assertEqualsWithDelta(1.0 - $loss, $held->kpis['throughput_index'], 1e-12);
        $this->assertEqualsWithDelta(2.0 * $loss, $held->streamZ[PortTerminalBusinessModel::STATE_DEFERRED_GATEWAY], 1e-12);

        // Settled: two quarters of the District's waiting boxes are lifted at once; the diverted relays stay gone. The
        // active mix has drifted a little after two odd quarters, hence the tolerance.
        $settled = $this->report($stock, new MacroStateDTO(), $this->draws(settles: true));
        $this->assertEqualsWithDelta($gatewayBase * (1.0 + (2.0 * $loss)), $settled->streamRevenue['gateway_handling'], 0.005 * $gatewayBase);
        $this->assertEqualsWithDelta($relayBase, $settled->streamRevenue['transshipment'], 0.005 * $relayBase);

        $this->assertSame(0.0, $settled->streamZ[PortTerminalBusinessModel::STATE_DEFERRED_GATEWAY], 'The backlog clears once.');
    }

    public function testCrewsAreStaffedForBoxesHandledNotForDwell(): void
    {
        $stock = $this->terminal();
        $recession = new MacroStateDTO(domesticDemandGapEma: -0.03, foreignOutputGapEma: -0.03, worldActivityGap: -0.03);

        $gatewayShift = (1.0 - self::exportShare()) * OpenEconomy::goodsImportVolumeGap(-0.03, 0.0);
        $relayShift = PortTerminalBusinessModel::TRANSSHIPMENT_TRADE_INCOME_ELASTICITY * -0.03;
        $expected = (PortTerminalBusinessModel::GATEWAY_HANDLING_WEIGHT * $gatewayShift) + (PortTerminalBusinessModel::TRANSSHIPMENT_WEIGHT * $relayShift);

        $this->assertEqualsWithDelta($expected, $this->model->resolveSectorActivityShift($stock, $recession), 1e-12);
        $congested = new MacroStateDTO(domesticDemandGapEma: -0.03, foreignOutputGapEma: -0.03, worldActivityGap: -0.03, supplyChainPressureIndexEma: 3.0);
        $this->assertEqualsWithDelta($expected, $this->model->resolveSectorActivityShift($stock, $congested), 1e-12);
        $this->assertSame(0.0, $this->model->getMacroPhysics($stock, $recession)['macro_demand_shift']);
        $this->assertEqualsWithDelta($expected, $this->model->getCapexCycleSignal($stock, $recession), 1e-12, 'Quays are budgeted on the boxes, not the District gap.');
    }

    public function testConcessionFeesRecoverInflationInFullAndNegotiatedFeesWhatTheHubCan(): void
    {
        $stock = $this->terminal();
        $inflation = 0.05;
        $macro = new MacroStateDTO(inflationEma: $inflation);

        // Step the annual repricing lag to its settled level.
        for ($quarter = 0; $quarter < 200; $quarter++) {
            $multipliers = $this->model->resolvePricingMultipliers($stock, $macro);
        }

        $negotiated = PortTerminalBusinessModel::PASS_THROUGH_BASE_ELASTICITY + PortTerminalBusinessModel::PRICING_POWER_INDEX;
        $gateway = PortTerminalBusinessModel::GATEWAY_HANDLING_WEIGHT;
        $elasticity = ($gateway * PortTerminalBusinessModel::CONCESSION_FEE_INDEXATION) + ((1.0 - $gateway) * $negotiated);

        $this->assertEqualsWithDelta(1.0 + ($inflation * $elasticity), $multipliers['pricing_power_multiplier'], 1e-9);
        $this->assertEqualsWithDelta(1.0 + $inflation, $multipliers['input_cost_multiplier'], 1e-9);
    }
}
