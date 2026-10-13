<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Repository\CorporateReportRepository;
use App\Service\Math\FinancialConstants;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\Model\Sector\HealthInsurerBusinessModel;
use App\Service\Model\Sector\InsuranceBusinessModel;
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
        $this->assertSame('Output gap (15-month lag)', $gap['label'], 'named from the catalogue, not the label the filing stored');
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

    /**
     * With the drag split by input filed, each input's line and chip carry its own measured part: metals still
     * hurting, pay helping, and the state from before the split shown only when it is big enough to print.
     */
    public function testInputLinesCarryTheirMeasuredPartOfTheDrag(): void
    {
        $kpis = [
            'variable_cost_ratio' => 0.427, 'fixed_costs' => 300.0,
            'input_cost_level' => 0.037, 'input_cost_recovery' => 0.015, 'input_cost_recovered_share' => 0.68,
            'input_cost_drag' => 0.0094, 'input_gap_metals' => 0.378, 'input_gap_labor' => -0.024,
            'input_drag_metals' => 0.0120, 'input_drag_labor' => -0.0026, 'input_drag_earlier' => 0.0,
        ];
        $map = $this->map(kpis: $kpis);

        $this->assertSame(['input:metals', 'recover', -1, 0.012], $this->edge($map, 'input:metals', 'recover'));
        $this->assertSame(['input:labor', 'recover', 1, 0.0026], $this->edge($map, 'input:labor', 'recover'));
        $this->assertEqualsWithDelta(-1.2, $this->card($map['outside'], 'input:metals')['chips'][0]['figure'], 1e-9);
        $this->assertEqualsWithDelta(0.26, $this->card($map['outside'], 'input:labor')['chips'][0]['figure'], 1e-9);
        $this->assertNull($this->find($map['outside'], 'input:earlier'), 'nothing earlier to show');

        $map = $this->map(kpis: ['input_drag_earlier' => 0.002] + $kpis);
        $this->assertSame(-1, $this->edge($map, 'input:earlier', 'recover')[2]);
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

    /** A lender's interest stream runs on its book; deposits feed the interest it pays, the reserve its credit losses. */
    public function testALenderShowsItsBookFundingAndReserve(): void
    {
        $report = $this->lenderReport();
        $stock = $this->stock('Banks - Diversified');
        $stock->setEarningsMomentumZ([
            CommercialBankBusinessModel::STATE_BOOK_YIELD_RESIDENTIAL => 0.053,
            CommercialBankBusinessModel::STATE_BOOK_YIELD_FIXED => 0.054,
            CommercialBankBusinessModel::STATE_BOOK_YIELD_SECURITIES => 0.045,
            CommercialBankBusinessModel::STATE_FLOATING_LOAN_SHARE => 0.29,
        ]);
        $previous = $this->lenderReport();
        $previous->setNetInterestMargin('0.0270');

        $map = $this->builder->fromReports($stock, $report, $previous);
        $this->assertNotNull($map);

        $book = $this->card($map['inside'], 'ops:net_interest_income');
        $this->assertSame('Loans and securities', $book['label']);
        $this->assertSame(1000.0, $book['value']);
        $this->assertStringContainsString('Mortgages 5.30%', $book['note']);
        $this->assertContains(['macro:policy_rate_ema', 'ops:net_interest_income', -1, 0.0128], $map['edges'], 'demand lines still land on the book');
        $this->assertSame('Interest income', $this->card($map['produced'], 'rev:net_interest_income')['label']);
        $this->assertSame(['funding', 'paid', 0, 0.3], $this->edge($map, 'funding', 'paid'));
        $this->assertSame(['reserve', 'losses', 0, 0.1], $this->edge($map, 'reserve', 'losses'));
        $this->assertSame(4.0, $this->card($map['produced'], 'losses')['value']);
        $this->assertEqualsWithDelta(0.06, $this->card($map['produced'], 'nim')['chips'][0]['figure'], 1e-9);
        $this->assertSame(0.14, $this->card($map['inside'], 'capital')['value']);
        $this->assertSame(['ops:net_interest_income', 'funding', 'ops:fee_income', 'reserve', 'capital'], array_column($map['inside'], 'id'), 'funding beside the book');
        $this->assertSame(['rev:net_interest_income', 'nim', 'paid', 'rev:fee_income', 'losses'], array_column($map['produced'], 'id'), 'each result beside what feeds it');
    }

    /** One event filed on every stream shows once: a lender's credit event on its loss reserve, anything else under the title. */
    public function testTheQuartersEventShowsOnce(): void
    {
        $report = $this->lenderReport();
        $report->setStreamDetails(array_map(static fn (array $detail): array => $detail + ['event' => 'Reserve Release'], $report->getStreamDetails() ?? []));
        $map = $this->builder->fromReports($this->stock('Banks - Diversified'), $report, null);
        $this->assertNotNull($map);

        $tagged = array_filter([...$map['inside'], ...$map['produced']], static fn (array $card): bool => in_array('Reserve release', array_column($card['chips'] ?? [], 'text'), true));
        $this->assertSame(['reserve'], array_column($tagged, 'id'));
        $this->assertArrayNotHasKey('event', $map);

        $factory = $this->report(1100.0, '0.2190', null);
        $factory->setStreamDetails(array_map(static fn (array $detail): array => $detail + ['event' => 'Product Recall'], $factory->getStreamDetails() ?? []));
        $map = $this->builder->fromReports($this->stock('Specialty Industrial Machinery'), $factory, null);
        $this->assertNotNull($map);
        $this->assertSame('Product recall', $map['event']['text']);
        $this->assertNotContains('Product recall', array_merge(...array_map(static fn (array $card): array => array_column($card['chips'] ?? [], 'text'), $map['inside'])));
    }

    /** Interest earned on cash gets its own card, so the shares add up to the revenue the hand-off names. */
    public function testTheSharesAddUpToTheHandOffTotal(): void
    {
        $report = $this->lenderReport();
        $report->setInterestIncome('25');
        $map = $this->builder->fromReports($this->stock('Banks - Diversified'), $report, null);
        $this->assertNotNull($map);

        $other = $this->card($map['produced'], 'other_interest');
        $this->assertSame(25.0, $other['value']);
        $this->assertSame(125.0, $map['handoff']['revenue']);
        $shares = array_sum(array_map(static fn (array $card): float => $card['share'] ?? 0.0, $map['produced']));
        $this->assertEqualsWithDelta(1.0, $shares, 1e-12);
        $this->assertEqualsWithDelta(0.56, $this->card($map['produced'], 'rev:net_interest_income')['share'], 1e-12);
    }

    /**
     * Outside cards sit beside the cards they move (the barycentre ordering), so a reading that moves only the second
     * stream comes below one that moves the first, whatever their strength.
     */
    public function testOutsideCardsLineUpWithWhatTheyMove(): void
    {
        $report = $this->lenderReport();
        $report->setStreamDetails([
            'net_interest_income' => ['drivers' => [$this->driver('housing_starts_index_ema', 'Housing starts', 'index', 116.2, 0.004)]],
            'fee_income' => ['drivers' => [$this->driver('output_gap_ema', 'Output gap', 'pct', 0.012, 0.03)]],
        ]);
        $map = $this->builder->fromReports($this->stock('Banks - Diversified'), $report, null);
        $this->assertNotNull($map);

        $this->assertSame(['macro:housing_starts_index_ema', 'macro:output_gap_ema'], array_values(array_filter(array_column($map['outside'], 'id'))), 'strongest first would put the output gap on top');
        $this->assertSame('index', $this->card($map['outside'], 'macro:housing_starts_index_ema')['unit']);
        $this->assertNull($this->card($map['outside'], 'macro:output_gap_ema')['unit']);
    }

    /** A net balance below zero reads by its other side: easing, not negative tightening. */
    public function testANegativeNetBalanceReadsAsEasing(): void
    {
        $report = $this->lenderReport();
        $report->setStreamDetails(['net_interest_income' => ['drivers' => [$this->driver('sloos_tightening_index_ema', 'Lending standards (net tightening)', 'pct', -0.144, 0.0267)]]]);
        $map = $this->builder->fromReports($this->stock('Banks - Diversified'), $report, null);
        $this->assertNotNull($map);

        $card = $this->card($map['outside'], 'macro:sloos_tightening_index_ema');
        $this->assertSame('Lending standards (net easing)', $card['label']);
        $this->assertSame(0.144, $card['value']);
    }

    /** An underwriter's capacity feeds its premiums; the combined ratio falls as the better quarter. */
    public function testAnInsurerShowsCapacityCycleAndFloat(): void
    {
        $stock = $this->stock('Insurance - Diversified');
        $stock->setEarningsMomentumZ([
            InsuranceBusinessModel::STATE_OBSERVED_CAPACITY => 1.08,
            InsuranceBusinessModel::STATE_WRITTEN_SHARE => 0.9,
            StreamContext::REGIME_STATE_PREFIX . InsuranceBusinessModel::REGIME_HARD_MARKET => 3.0,
        ]);
        $report = $this->insurerReport(0.60, 0.34);
        $map = $this->builder->fromReports($stock, $report, $this->insurerReport(0.62, 0.34));
        $this->assertNotNull($map);

        $this->assertSame(['capacity', 'rev:property_casualty_premiums', 0, 0.2], $this->edge($map, 'capacity', 'rev:property_casualty_premiums'));
        $this->assertStringContainsString('3 quarters in', $this->card($map['inside'], 'cycle')['note']);
        $this->assertSame(500.0, $this->card($map['inside'], 'float')['value']);
        $this->assertSame(12.0, $this->card($map['produced'], 'investment')['value']);
        $combined = $this->card($map['produced'], 'combined');
        $this->assertEqualsWithDelta(0.94, $combined['value'], 1e-12);
        $this->assertEqualsWithDelta(-2.0, $combined['chips'][0]['figure'], 1e-9);
        $this->assertSame('up', $combined['chips'][0]['tone'], 'a lower combined ratio is the better quarter');
        $this->assertNull($this->find($map['produced'], 'cost'), 'no production cost side for an underwriter');
    }

    public function testALandlordShowsItsLeaseLadder(): void
    {
        $report = $this->report(1000.0, '0.5000', ['walt_years' => 6.0, 'releasing_spread' => -0.0375, 'in_place_rent_index' => -0.0043]);
        $report->setRevenueStreams(['lease' => 900.0, 'hospitality' => 100.0]);
        $report->setStreamDetails(['lease' => ['qoq_delta' => 0.01, 'drivers' => []], 'hospitality' => ['qoq_delta' => 0.0, 'drivers' => []]]);

        $map = $this->builder->fromReports($this->stock('REIT - Industrial'), $report, null);
        $this->assertNotNull($map);

        $ladder = $this->card($map['inside'], 'ops:lease');
        $this->assertSame('Lease book', $ladder['label']);
        $this->assertSame(6.0, $ladder['value']);
        $this->assertStringContainsString('re-lets 3.8% below', $ladder['note']);
        $this->assertSame('down', $ladder['chips'][0]['tone']);
    }

    /** Medical costs above what the book was priced for push up the cost of each sale until premiums reprice. */
    public function testAHealthPlanShowsThePricingGap(): void
    {
        $stock = $this->stock('Healthcare Plans');
        $stock->setEarningsMomentumZ([HealthInsurerBusinessModel::STATE_PRICED_TREND => 0.07, HealthInsurerBusinessModel::STATE_LOSS_RATIO_GAP => 0.02]);
        $report = $this->report(1000.0, '0.0600', ['variable_cost_ratio' => 0.81, 'fixed_costs' => 50.0]);

        $map = $this->builder->fromReports($stock, $report, null);
        $this->assertNotNull($map);

        $this->assertSame(0.07, $this->card($map['inside'], 'pricing')['value']);
        $this->assertSame(-1, $this->edge($map, 'pricing', 'cost')[2]);
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

    private function lenderReport(): CorporateReport
    {
        $report = new CorporateReport();
        $report->setRevenue('100');
        $report->setOperatingMargin('0.5000');
        $report->setRevenueStreams(['net_interest_income' => 70.0, 'fee_income' => 30.0]);
        $report->setStreamDetails(['net_interest_income' => ['qoq_delta' => 0.03, 'drivers' => [
            $this->driver('policy_rate_ema', 'Policy Rate', 'pct', 0.021, -0.0128),
        ]], 'fee_income' => ['qoq_delta' => 0.05, 'drivers' => []]]);
        $report->setEarningAssets('1000');
        $report->setCustomerDeposits('900');
        $report->setDepositApy('0.0156');
        $report->setCreditLossAllowance('10');
        $report->setCreditLossProvision('4');
        $report->setNetChargeOffs('3');
        $report->setCet1Ratio('0.14');
        $report->setInterestExpense('20');
        $report->setNetInterestMargin('0.0276');

        return $report;
    }

    private function insurerReport(float $lossRatio, float $expenseRatio): CorporateReport
    {
        $report = new CorporateReport();
        $report->setRevenue('100');
        $report->setOperatingMargin('0.0500');
        $report->setRevenueStreams(['property_casualty_premiums' => 80.0, 'life_insurance_premiums' => 20.0]);
        $report->setStreamDetails([]);
        $report->setReportedKpis(['loss_ratio' => $lossRatio, 'expense_ratio' => $expenseRatio]);
        $report->setCustomerDeposits('500');
        $report->setInterestIncome('12');

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
