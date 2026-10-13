<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Repository\CorporateReportRepository;
use App\Service\Math\FinancialConstants;
use App\Service\Model\StreamContext;
use App\Service\View\BusinessMapBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The "How the business works" map: demand lines are the report's own measured stream effects, cost lines the cost
 * side the quarter filed, and every line joins two cards in neighbouring columns or bows within one.
 */
final class BusinessMapBuilderTest extends TestCase
{
    private BusinessMapBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new BusinessMapBuilder($this->createStub(CorporateReportRepository::class));
    }

    public function testDemandLinesCarryTheMeasuredStreamEffects(): void
    {
        $map = $this->map();

        $gap = $this->card($map['outside'], 'macro:output_gap_lag_15m');
        $this->assertSame('Output Gap (15M Lag)', $gap['label']);
        $this->assertSame(-0.005, $gap['value']);
        $this->assertContains(['macro:output_gap_lag_15m', 'ops:equipment_sales', -1, 0.0029], $map['edges']);
        $this->assertContains(['macro:capacity_utilization_rate_ema', 'ops:equipment_sales', -1, 0.0033], $map['edges']);
        $this->assertSame('Demand', $map['outside'][0]['group']);
    }

    public function testAStreamSoldFromABacklogShowsItsOrderBook(): void
    {
        $map = $this->map();

        $orders = $this->card($map['inside'], 'ops:equipment_sales');
        $this->assertSame('Order book, equipment sales', $orders['label']);
        $this->assertSame(1.07, $orders['value']);
        $this->assertSame('Strong execution', $orders['chips'][0]['text']);
        $this->assertSame('Aftermarket services', $this->card($map['inside'], 'ops:aftermarket_services')['label'], 'no backlog, no order book');
        $this->assertEqualsWithDelta(0.6, $this->card($map['produced'], 'rev:equipment_sales')['share'], 1e-12);
    }

    /** Input lines point the way the price moved the cost base: metals above normal hurt, pay below productivity helped. */
    public function testInputLinesFollowThePriceGapTheQuarterBoughtAt(): void
    {
        $map = $this->map();

        $this->assertSame('Input markets', $this->groupBefore($map['outside'], 'input:metals'));
        $this->assertSame(-1, $this->edge($map, 'input:metals', 'recover')[2]);
        $this->assertSame(1, $this->edge($map, 'input:labor', 'recover')[2]);
        $this->assertNull($this->find($map['outside'], 'input:agri'), 'only inputs in the model basket');
        $this->assertSame(-1, $this->edge($map, 'recover', 'cost')[2], 'an unrecovered cost rise hurts');
        $this->assertEqualsWithDelta(-0.94, $this->card($map['inside'], 'recover')['chips'][0]['figure'], 1e-9);
    }

    public function testTheMarginCarriesItsChangeOnTheQuarter(): void
    {
        $map = $this->map();

        $margin = $this->card($map['produced'], 'margin');
        $this->assertSame(0.219, $margin['value']);
        $this->assertEqualsWithDelta(6.1, $margin['chips'][0]['figure'], 1e-9);
        $this->assertEqualsWithDelta(0.1, $map['handoff']['change'], 1e-12);
    }

    /** A report from before the cost side was filed still maps its demand; it draws no cost cards it cannot fill. */
    public function testAReportWithoutTheCostSideMapsDemandOnly(): void
    {
        $map = $this->map(kpis: ['book_to_bill' => 1.07, 'backlog_quarters' => 1.9]);

        $this->assertNull($this->find($map['inside'], 'recover'));
        $this->assertNull($this->find($map['produced'], 'cost'));
        $this->assertNotNull($this->find($map['produced'], 'rev:equipment_sales'));
    }

    /** A lender's cost of sale is not a production cost, so a bank gets the demand side until its own layout exists. */
    public function testAFinancialFirmGetsNoProductionCostSide(): void
    {
        $map = $this->map(industry: 'Banks - Diversified');

        $this->assertNull($this->find($map['produced'], 'cost'));
        $this->assertNull($this->find($map['outside'], 'input:metals'));
    }

    public function testEveryLineJoinsTwoCards(): void
    {
        $map = $this->map();
        $ids = array_column(array_filter([...$map['outside'], ...$map['inside'], ...$map['produced']], static fn (array $c): bool => isset($c['id'])), 'id');

        foreach ($map['edges'] as [$from, $to]) {
            $this->assertContains($from, $ids);
            $this->assertContains($to, $ids);
        }
        $this->assertContains('order book, equipment sales', $this->card($map['outside'], 'macro:output_gap_lag_15m')['feeds']);
    }

    public function testNoReportNoMap(): void
    {
        $this->assertNull($this->builder->fromReports($this->stock('Specialty Industrial Machinery'), null, null));
    }

    // --- Fixtures ---

    /**
     * @param array<string, float>|null $kpis
     * @return array<string, mixed>
     */
    private function map(?array $kpis = null, string $industry = 'Specialty Industrial Machinery'): array
    {
        $kpis ??= [
            'book_to_bill' => 1.07, 'backlog_quarters' => 1.9,
            'variable_cost_ratio' => 0.427, 'fixed_costs' => 300.0,
            'input_cost_level' => 0.037, 'input_cost_recovery' => 0.015, 'input_cost_recovered_share' => 0.68,
            'input_cost_drag' => 0.0094, 'input_gap_metals' => 0.378, 'input_gap_labor' => -0.024,
        ];
        $report = $this->report(1100.0, '0.2190', $kpis);
        $previous = $this->report(1000.0, '0.1580', null);

        $map = $this->builder->fromReports($this->stock($industry), $report, $previous);
        $this->assertNotNull($map);

        return $map;
    }

    /** @param array<string, float>|null $kpis */
    private function report(float $revenue, string $margin, ?array $kpis): CorporateReport
    {
        $report = new CorporateReport();
        $report->setRevenue((string) $revenue);
        $report->setOperatingMargin($margin);
        $report->setRevenueStreams(['equipment_sales' => $revenue * 0.6, 'aftermarket_services' => $revenue * 0.4]);
        $report->setStreamDetails([
            'equipment_sales' => ['revenue' => $revenue * 0.6, 'share' => 0.6, 'qoq_delta' => 0.107, 'drivers' => [
                $this->driver('capacity_utilization_rate_ema', 'Capacity Utilisation', 'pct', 0.765, -0.0033),
                $this->driver('output_gap_lag_15m', 'Output Gap (15M Lag)', 'pct', -0.005, -0.0029),
                ['label' => 'Strong Operational Execution', 'z' => 2.67, 'type' => 'momentum', 'readings' => [], 'direction' => 1, 'strength' => 3],
            ]],
            'aftermarket_services' => ['revenue' => $revenue * 0.4, 'share' => 0.4, 'qoq_delta' => 0.095, 'drivers' => []],
        ]);
        $report->setReportedKpis($kpis);

        return $report;
    }

    /** @return array<string, mixed> */
    private function driver(string $field, string $label, string $unit, float $value, float $impact): array
    {
        return [
            'label' => $label, 'impact' => $impact, 'share' => $impact, 'type' => 'macro',
            'readings' => [['field' => $field, 'label' => $label, 'unit' => $unit, 'value' => $value]],
            'direction' => $impact <=> 0.0, 'strength' => 1,
        ];
    }

    private function stock(string $industry): Stock
    {
        $stock = new Stock();
        $stock->setTicker('ALCA');
        $stock->setIndustry($industry);
        $stock->setEarningsMomentumZ([
            StreamContext::BACKLOG_STATE_PREFIX . 'equipment_sales' => 1.9,
            FinancialConstants::STATE_INPUT_COST_LEVEL => 0.037,
        ]);

        return $stock;
    }

    /**
     * @param list<array<string, mixed>> $cards
     * @return array<string, mixed>|null
     */
    private function find(array $cards, string $id): ?array
    {
        foreach ($cards as $card) {
            if (($card['id'] ?? null) === $id) {
                return $card;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $cards
     * @return array<string, mixed>
     */
    private function card(array $cards, string $id): array
    {
        $card = $this->find($cards, $id);
        $this->assertNotNull($card, "no card $id");

        return $card;
    }

    /** @param list<array<string, mixed>> $cards */
    private function groupBefore(array $cards, string $id): ?string
    {
        $group = null;
        foreach ($cards as $card) {
            if (isset($card['group'])) {
                $group = $card['group'];
            }
            if (($card['id'] ?? null) === $id) {
                return $group;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $map
     * @return array{0: string, 1: string, 2: int, 3: float}
     */
    private function edge(array $map, string $from, string $to): array
    {
        foreach ($map['edges'] as $edge) {
            if ($edge[0] === $from && $edge[1] === $to) {
                return $edge;
            }
        }
        $this->fail("no line $from → $to");
    }
}
