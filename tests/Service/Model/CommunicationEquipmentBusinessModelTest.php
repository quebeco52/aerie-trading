<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\DTO\StreamContext;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\CommunicationEquipmentBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class CommunicationEquipmentBusinessModelTest extends TestCase
{
    private const EXPECTED_REVENUE = 100_000_000.0;
    private const VARIABLE_COST_RATIO = 0.35;

    private CommunicationEquipmentBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new CommunicationEquipmentBusinessModel();
    }

    /**
     * Runs one quarter with every normal draw at zero and no regime hazard firing. Momentum markers of -9.9
     * on an exogenous stream script that stream's Z for the quarter; $hazardFires decides which regime
     * hazards checkProbability() answers yes to.
     *
     * @param array<string, float> $momentum
     * @param list<float>          $hazardFires
     */
    private function runQuarter(array $momentum = [], float $scriptedZ = 0.0, array $hazardFires = [], ?MacroStateDTO $macro = null, float $baselineVol = 0.0): ActualFinancialsDTO
    {
        $stock = (new Stock())->setTicker('ERNE_TEST')->setBeta('0.85');
        $stock->setEarningsMomentumZ($momentum);

        $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generatePersistentZ', 'checkProbability'])->getMock();
        $math->method('generatePersistentZ')->willReturnCallback(fn (float $prev): float => $prev === -9.9 ? $scriptedZ : 0.0);
        $math->method('checkProbability')->willReturnCallback(fn (float $p): bool => in_array($p, $hazardFires, true));

        return $this->model->computeActualFinancials(
            $stock,
            self::EXPECTED_REVENUE,
            self::VARIABLE_COST_RATIO,
            20_000_000.0,
            $baselineVol,
            $macro ?? new MacroStateDTO(),
            $math
        );
    }

    public function testThreeStreamDecompositionReproducesTheTargetCostRatioAtBaseline(): void
    {
        $result = $this->runQuarter();

        $this->assertEqualsWithDelta(75_000_000.0, $result->streamRevenue['carrier_networks'], 1.0);
        $this->assertEqualsWithDelta(10_000_000.0, $result->streamRevenue['sep_licensing'], 1.0);
        $this->assertEqualsWithDelta(15_000_000.0, $result->streamRevenue['consumer_terminals'], 1.0);
        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE, $result->actualRevenue, 1.0);

        // Fixed royalty and gateway cost ratios plus the derived network ratio must land on the engine's target.
        $this->assertEqualsWithDelta(self::VARIABLE_COST_RATIO, $result->clampedMargin, 1e-9);

        // The backlog is seeded at steady state, so a flat quarter books exactly what it bills.
        $steadyStateQuarters = (1.0 - CommunicationEquipmentBusinessModel::NETWORK_BACKLOG_BURN_RATE) / CommunicationEquipmentBusinessModel::NETWORK_BACKLOG_BURN_RATE;
        $this->assertEqualsWithDelta(1.0, $result->kpis['book_to_bill'], 1e-9);
        $this->assertEqualsWithDelta($steadyStateQuarters, $result->kpis['backlog_quarters'], 1e-9);
        $this->assertNull($result->eventType);
    }

    /**
     * Carrier capex follows the output gap, but a tender won this quarter is delivered over the backlog: only
     * the burn-rate share of the order uplift reaches revenue and the rest sits in the book.
     */
    public function testCarrierCapexBoomReachesRevenueOnlyThroughTheBacklogBurn(): void
    {
        $base = $this->runQuarter();
        $boom = $this->runQuarter(macro: new MacroStateDTO(outputGapEma: 0.05));

        $orderUplift = $boom->kpis['book_to_bill'] - 1.0;
        $revenueUplift = ($boom->streamRevenue['carrier_networks'] / $base->streamRevenue['carrier_networks']) - 1.0;

        $this->assertGreaterThan(0.0, $orderUplift);
        $this->assertGreaterThan(0.0, $revenueUplift);
        $this->assertLessThan($orderUplift, $revenueUplift, 'Percentage-of-completion recognition must cushion the order shock.');
        $this->assertGreaterThan($base->kpis['backlog_quarters'], $boom->kpis['backlog_quarters']);

        // Royalties are billed per device, not per base station: the carrier cycle leaves them untouched.
        $this->assertEqualsWithDelta($base->streamRevenue['sep_licensing'], $boom->streamRevenue['sep_licensing'], 1.0);
    }

    public function testCapitalStockOverhangAndStrongCurrencyBothCutNetworkOrders(): void
    {
        $base = $this->runQuarter();
        $overhang = $this->runQuarter(macro: new MacroStateDTO(capitalStockOverhangEma: 0.10));
        $strongCurrency = $this->runQuarter(macro: new MacroStateDTO(exchangeRateIndexEma: 120.0));

        $this->assertLessThan(1.0, $overhang->kpis['book_to_bill'], 'Over-built carriers digest before they re-order.');
        $this->assertLessThan($base->streamRevenue['carrier_networks'], $overhang->streamRevenue['carrier_networks']);
        $this->assertLessThan($base->streamRevenue['carrier_networks'], $strongCurrency->streamRevenue['carrier_networks'], 'Global tenders are lost when the home currency appreciates.');
    }

    /**
     * Handset upgrades follow sentiment, so the royalty base and the consumer channel move with it while the
     * carrier book does not.
     */
    public function testConsumerSentimentMovesRoyaltiesAndTerminalsButNotTheCarrierBook(): void
    {
        $base = $this->runQuarter();
        // Confidence is read against the level the index sits at with output at trend, so the uplift is the
        // deviation from THAT and not from the constant the index is built down from.
        $upbeatMacro = new MacroStateDTO(consumerSentimentIndexEma: 110.0);
        $upbeat = $this->runQuarter(macro: $upbeatMacro);

        $this->assertGreaterThan($base->streamRevenue['sep_licensing'], $upbeat->streamRevenue['sep_licensing']);
        $this->assertGreaterThan($base->streamRevenue['consumer_terminals'], $upbeat->streamRevenue['consumer_terminals']);
        $this->assertEqualsWithDelta($base->streamRevenue['carrier_networks'], $upbeat->streamRevenue['carrier_networks'], 1.0);
        $this->assertEqualsWithDelta(
            10_000_000.0 * (1.0 + ($upbeatMacro->sentimentDeviation() * CommunicationEquipmentBusinessModel::DEVICE_SHIPMENT_SENTIMENT_SENSITIVITY)),
            $upbeat->streamRevenue['sep_licensing'],
            1.0
        );
    }

    /**
     * A licensee that stops paying withholds royalties for as long as the dispute runs; settlement returns most
     * of the arrears as one catch-up payment and the remainder is the settlement discount.
     */
    public function testRoyaltyDisputeWithholdsArrearsUntilSettlementPaysMostOfThemBack(): void
    {
        $regimeKey = StreamContext::REGIME_STATE_PREFIX . CommunicationEquipmentBusinessModel::REGIME_ROYALTY_DISPUTE;
        $expectedRoyalties = 10_000_000.0;
        $withheldShare = CommunicationEquipmentBusinessModel::ROYALTY_DISPUTE_WITHHELD_SHARE;

        // Onset: the litigation draw crosses the threshold, one flagship licensee's share stops arriving.
        $onset = $this->runQuarter(['litigation' => -9.9], -2.5);
        $this->assertSame(ShockEvent::SEP_ROYALTY_DISPUTE, $onset->eventType);
        $this->assertTrue($onset->isPublicEvent);
        $this->assertSame(1.0, $onset->streamZ[$regimeKey]);
        $this->assertEqualsWithDelta($expectedRoyalties * (1.0 - $withheldShare), $onset->streamRevenue['sep_licensing'], 1.0);
        $this->assertEqualsWithDelta($withheldShare, $onset->kpis['withheld_royalty_quarters'], 1e-9);

        // Quarter 2: the dispute continues silently and the arrears keep accruing. From here the revenue mix
        // drifts toward the realized (withheld) share, so the expected royalty base is the active weight the
        // context registered rather than the 10% target.
        $second = $this->runQuarter($onset->streamZ);
        $this->assertNull($second->eventType);
        $this->assertSame(2.0, $second->streamZ[$regimeKey]);
        $secondBase = self::EXPECTED_REVENUE * $second->streamZ['weight:sep_licensing'];
        $this->assertLessThan($expectedRoyalties, $secondBase, 'Mix drift pulls the royalty weight toward the withheld reality.');
        $this->assertEqualsWithDelta($secondBase * (1.0 - $withheldShare), $second->streamRevenue['sep_licensing'], 1.0);
        $this->assertEqualsWithDelta(2.0 * $withheldShare, $second->kpis['withheld_royalty_quarters'], 1e-9);

        // Quarter 3: the exit hazard fires. Royalties normalize and the catch-up payment lands on top.
        $settled = $this->runQuarter($second->streamZ, hazardFires: [CommunicationEquipmentBusinessModel::ROYALTY_DISPUTE_EXIT_HAZARD]);
        $this->assertSame(ShockEvent::SEP_ROYALTY_SETTLEMENT, $settled->eventType);
        $this->assertTrue($settled->isPublicEvent);
        $this->assertSame(0.0, $settled->streamZ[$regimeKey]);
        $settledBase = self::EXPECTED_REVENUE * $settled->streamZ['weight:sep_licensing'];
        $catchUp = 2.0 * $withheldShare * CommunicationEquipmentBusinessModel::ROYALTY_SETTLEMENT_RECOVERY_SHARE * $settledBase;
        $this->assertEqualsWithDelta($settledBase + $catchUp, $settled->streamRevenue['sep_licensing'], 1.0);
        $this->assertGreaterThan($expectedRoyalties, $settled->streamRevenue['sep_licensing'], 'The settlement quarter out-earns a normal one.');
        $this->assertSame(0.0, $settled->kpis['withheld_royalty_quarters']);

        // Quarter 4: nothing left to collect.
        $after = $this->runQuarter($settled->streamZ);
        $this->assertNull($after->eventType);
        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE * $after->streamZ['weight:sep_licensing'], $after->streamRevenue['sep_licensing'], 1.0);
        $this->assertLessThan($settled->streamRevenue['sep_licensing'], $after->streamRevenue['sep_licensing']);
    }

    /**
     * A generational rollout is announced once, then lifts order intake quietly for as long as it lasts.
     */
    public function testRolloutWaveAnnouncesAtOnsetAndLiftsOrdersWhileItRuns(): void
    {
        $onset = $this->runQuarter(hazardFires: [CommunicationEquipmentBusinessModel::ROLLOUT_WAVE_ONSET_HAZARD]);
        $this->assertSame(ShockEvent::NETWORK_ROLLOUT_WAVE, $onset->eventType);
        $this->assertSame(1.0, $onset->kpis['rollout_wave_quarters']);
        $burn = CommunicationEquipmentBusinessModel::NETWORK_BACKLOG_BURN_RATE;
        $orders = 1.0 + CommunicationEquipmentBusinessModel::ROLLOUT_WAVE_ORDER_UPLIFT;
        $this->assertEqualsWithDelta($orders / ((1.0 - $burn) + ($burn * $orders)), $onset->kpis['book_to_bill'], 1e-9, 'Book-to-bill is orders over recognized revenue.');

        $second = $this->runQuarter($onset->streamZ);
        $this->assertNull($second->eventType);
        $this->assertSame(2.0, $second->kpis['rollout_wave_quarters']);
        $this->assertGreaterThan(1.0, $second->kpis['book_to_bill']);
        $this->assertGreaterThan($onset->streamRevenue['carrier_networks'], $second->streamRevenue['carrier_networks'], 'The backlog built in the onset quarter is delivered in the next.');

        // Digestion: the wave ends, orders fall back to replacement and the book is worked down.
        $ended = $this->runQuarter($second->streamZ, hazardFires: [CommunicationEquipmentBusinessModel::ROLLOUT_WAVE_EXIT_HAZARD]);
        $this->assertSame(0.0, $ended->kpis['rollout_wave_quarters']);
        $this->assertLessThan(1.0, $ended->kpis['book_to_bill']);
    }

    /**
     * A fab shortage does not lose carrier orders, it delays them: deliveries slip into the backlog while
     * expedited sourcing taxes the cost ratio.
     */
    public function testComponentShortageSlipsDeliveriesIntoTheBacklogAndTaxesMargin(): void
    {
        $seed = $this->runQuarter();
        $base = $this->runQuarter($seed->streamZ);
        $shortage = $this->runQuarter([...$seed->streamZ, 'event' => -9.9], -2.5);

        $this->assertSame(ShockEvent::SEMICONDUCTOR_FAB_SHORTAGE, $shortage->eventType);
        $this->assertLessThan($base->streamRevenue['carrier_networks'], $shortage->streamRevenue['carrier_networks']);
        $this->assertGreaterThan($base->kpis['backlog_quarters'], $shortage->kpis['backlog_quarters'], 'Undelivered orders stay in the book rather than vanishing.');
        $this->assertGreaterThan(1.0, $shortage->kpis['book_to_bill']);
        $this->assertLessThan($base->streamRevenue['consumer_terminals'], $shortage->streamRevenue['consumer_terminals']);
        $this->assertGreaterThan($base->clampedMargin, $shortage->clampedMargin);
    }

    public function testSupplyChainPressureAndComponentInflationRaiseTheCostRatio(): void
    {
        $base = $this->runQuarter();
        $gscpi = $this->runQuarter(macro: new MacroStateDTO(supplyChainPressureIndexEma: 2.0));
        $ppi = $this->runQuarter(macro: new MacroStateDTO(producerPriceInflationEma: 0.08));

        $this->assertGreaterThan($base->clampedMargin, $gscpi->clampedMargin);
        $this->assertGreaterThan($base->clampedMargin, $ppi->clampedMargin);
    }

    public function testDeclaresSectorIdentityConstantsWithinTheirBands(): void
    {
        $this->assertEqualsWithDelta(1.0, CommunicationEquipmentBusinessModel::ENTERPRISE_WEIGHT + CommunicationEquipmentBusinessModel::PATENT_LICENSING_WEIGHT + CommunicationEquipmentBusinessModel::CONSUMER_WEIGHT, 1e-9);
        $this->assertEqualsWithDelta(4.0, array_sum($this->model->getSeasonalityFactors()), 1e-9);
        $this->assertLessThan(CommunicationEquipmentBusinessModel::CONSUMER_VARIABLE_COST_RATIO, CommunicationEquipmentBusinessModel::LICENSING_VARIABLE_COST_RATIO, 'Royalties carry almost no unit cost; gateways are commodity hardware.');
        $this->assertSame(CommunicationEquipmentBusinessModel::RADIO_RND_DECAY_RATE, $this->model->getDepreciationDecayRate());
        $this->assertSame(CommunicationEquipmentBusinessModel::GENERATIONAL_PLATFORM_GAIN_RATE, $this->model->getModernizationGainRate());
    }
}
