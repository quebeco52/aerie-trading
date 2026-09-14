<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Entity\Stock;
use App\Repository\StockRepository;
use App\Service\View\PeerTableBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class PeerTableBuilderTest extends TestCase
{
    /**
     * @param list<Stock> $peers
     */
    private function builder(array $peers): PeerTableBuilder
    {
        $stocks = $this->createMock(StockRepository::class);
        $stocks->method('findPeersOf')->willReturn($peers);

        return new PeerTableBuilder($stocks);
    }

    private function peer(string $ticker, float $price, float $shares, float $eps, string $industry = 'General'): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setName($ticker . ' Co');
        $stock->setIndustry($industry);
        $stock->setPrice((string) $price);
        $stock->setSharesOutstanding((string) $shares);
        $stock->setEarningsPerShare((string) $eps);

        return $stock;
    }

    public function testPeersAreRankedLargestFirst(): void
    {
        $peers = $this->builder([
            $this->peer('SMALL', 10.0, 1_000.0, 1.0),
            $this->peer('BIG', 100.0, 10_000.0, 1.0),
            $this->peer('MID', 50.0, 2_000.0, 1.0),
        ])->build(new Stock());

        $this->assertSame(['BIG', 'MID', 'SMALL'], array_column($peers, 'ticker'));
    }

    public function testTheMultipleIsPricedOffEarningsAndOmittedWhenThereAreNone(): void
    {
        $peers = $this->builder([
            $this->peer('EARN', 120.0, 1_000.0, 10.0),
            $this->peer('LOSS', 40.0, 1_000.0, -2.0),
            $this->peer('FLAT', 40.0, 1_000.0, 0.0),
        ])->build(new Stock());

        $byTicker = array_column($peers, null, 'ticker');
        $this->assertEqualsWithDelta(12.0, $byTicker['EARN']['peRatio'], 1e-9);
        $this->assertNull($byTicker['LOSS']['peRatio'], 'A loss-making peer has no multiple, not a negative one.');
        $this->assertNull($byTicker['FLAT']['peRatio']);
    }

    /**
     * A delisted peer has no equity claim left, so it capitalises at nothing and sorts to the bottom
     * rather than carrying its last traded price into the ranking.
     */
    public function testADelistedPeerCapitalisesAtNothing(): void
    {
        $dead = $this->peer('DEAD', 80.0, 10_000.0, 5.0);
        $dead->setIsBankrupt(true);

        $peers = $this->builder([$dead, $this->peer('LIVE', 10.0, 1_000.0, 1.0)])->build(new Stock());

        $this->assertSame('LIVE', $peers[0]['ticker']);
        $this->assertSame(0.0, $peers[1]['marketCap']);
        $this->assertNull($peers[1]['peRatio']);
        $this->assertTrue($peers[1]['isBankrupt']);
    }

    /**
     * A lender funds its book with deposits and borrowing, so return on equity is the comparable
     * figure; ranking it on ROIC would put it beside industrials on a different measurement.
     */
    public function testALenderIsJudgedOnEquityReturnAndAnIndustrialOnCapitalReturn(): void
    {
        $bank = $this->peer('BANK', 50.0, 1_000.0, 5.0, 'Banks - Diversified');
        $bank->setCurrentRoe('0.1800');
        $bank->setCurrentRoic('0.0100');

        $mill = $this->peer('MILL', 50.0, 1_000.0, 5.0, 'Steel');
        $mill->setCurrentRoe('0.0100');
        $mill->setCurrentRoic('0.1400');

        $peers = array_column($this->builder([$bank, $mill])->build(new Stock()), null, 'ticker');

        $this->assertEqualsWithDelta(0.18, $peers['BANK']['roic'], 1e-9);
        $this->assertEqualsWithDelta(0.14, $peers['MILL']['roic'], 1e-9);
    }

    /**
     * Documents current behaviour, which is not the behaviour the `?:` was written for.
     *
     * currentRoic is a non-nullable decimal column, so an unreported company holds the string
     * "0.0000" — which is truthy in PHP. The baseline therefore never stands in here, and a company
     * that has not filed yet shows a flat zero. App\Controller\HomeController and
     * App\Controller\ScreenerController write the same intent as `(float) $a ?: (float) $b`, casting
     * before the test, so on those pages the same company shows its baseline instead. Correcting the
     * disagreement changes displayed returns and, through
     * App\Service\Model\Trait\FinancialPhysicsTrait, priced ones, so it is left as found here.
     */
    public function testAnUnreportedCompanyShowsAFlatZeroRatherThanItsBaseline(): void
    {
        $fresh = $this->peer('NEW', 20.0, 1_000.0, 1.0, 'Steel');
        $fresh->setCurrentRoic('0.0000');
        $fresh->setBaselineRoic('0.0900');

        $peers = $this->builder([$fresh])->build(new Stock());

        $this->assertSame(0.0, $peers[0]['roic']);
    }

    public function testACompanyWithNoPeersGetsAnEmptyTable(): void
    {
        $this->assertSame([], $this->builder([])->build(new Stock()));
    }
}
