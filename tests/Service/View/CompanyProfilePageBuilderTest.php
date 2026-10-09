<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Data\Company\AnchorHoldings;
use App\Data\Company\CompanyResearch;
use App\Data\District\Institutions;
use App\Data\Company\StrategicHoldings;
use App\Entity\Stock;
use App\Repository\StockEventRepository;
use App\Repository\StockRepository;
use App\Service\Market\Chart\PriceChangeFeed;
use App\Service\View\CompanyProfilePageBuilder;
use App\Service\View\PeerTableBuilder;
use App\Tests\Support\StockBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * The long-form profile page: the written article beside live facts, so the text is static and the margin
 * keeps up with play.
 */
#[AllowMockObjectsWithoutExpectations]
class CompanyProfilePageBuilderTest extends TestCase
{
    /** @param array<string, Stock> $board */
    private function builder(array $board): CompanyProfilePageBuilder
    {
        $stocks = $this->createMock(StockRepository::class);
        $stocks->method('findOneByTicker')->willReturnCallback(static fn (string $ticker): ?Stock => $board[$ticker] ?? null);

        $peers = $this->createMock(PeerTableBuilder::class);
        $peers->method('build')->willReturn(array_fill(0, 9, ['ticker' => 'PEER']));

        $feed = $this->createMock(PriceChangeFeed::class);
        $feed->method('changeForTicker')->willReturn(0.0125);

        return new CompanyProfilePageBuilder($stocks, $this->createMock(StockEventRepository::class), $peers, $feed);
    }

    public function testCompanyWithoutAProfileHasNoPage(): void
    {
        $this->assertArrayNotHasKey('HUMM', CompanyResearch::ARTICLES);
        $this->assertNull($this->builder([])->build(StockBuilder::create('HUMM')->build()));
    }

    public function testProfileCarriesTheArticleAndLiveFacts(): void
    {
        $lake = StockBuilder::create('LAKE', 'Lakebird Bank')->withPrice(80.0)->withSharesOutstanding(1_000_000_000)->withPublicFloatPercentage(0.5)->build();
        $page = $this->builder([])->build($lake);

        $this->assertNotNull($page);
        $this->assertSame(CompanyResearch::ARTICLES['LAKE'], $page['article']);
        $this->assertSame(Institutions::TICKBIRD_RESEARCH, $page['publisher']);
        $this->assertEqualsWithDelta(80_000_000_000.0, $page['marketCap'], 1.0);
        $this->assertSame(0.0125, $page['changePercent']);
        $this->assertSame(0.5, $page['freeFloat']);
        $this->assertCount(5, $page['peers'], 'The margin lists the five nearest peers, not the whole sector.');
    }

    /** A delisted company keeps its history, but the margin no longer quotes it. */
    public function testDelistedCompanyHasNoQuote(): void
    {
        $lake = StockBuilder::create('LAKE', 'Lakebird Bank')->withPrice(80.0)->build();
        $lake->setIsBankrupt(true);
        $page = $this->builder([])->build($lake);

        $this->assertNotNull($page);
        $this->assertNull($page['marketCap']);
        $this->assertNull($page['changePercent']);
    }

    /** Linked companies come off the live board: one that has gone is dropped, a delisted one is marked. */
    public function testRelatedCompaniesFollowTheBoard(): void
    {
        $swan = StockBuilder::create('SWAN', 'Black Swan Capital')->build();
        $plvr = StockBuilder::create('PLVR', 'Plover Savings Bank')->build();
        $plvr->setIsBankrupt(true);

        $page = $this->builder(['SWAN' => $swan, 'PLVR' => $plvr])->build(StockBuilder::create('LAKE')->build());
        $related = array_column($page['related'] ?? [], null, 'ticker');

        $this->assertSame(['SWAN', 'PLVR'], array_keys($related), 'Companies missing from the board are not linked.');
        $this->assertTrue($related['SWAN']['hasProfile']);
        $this->assertFalse($related['SWAN']['isDelisted']);
        $this->assertFalse($related['PLVR']['hasProfile']);
        $this->assertTrue($related['PLVR']['isDelisted']);
    }

    public function testOwnershipNamesAnchorHoldersAndTheFundsStake(): void
    {
        $builder = $this->builder(['BRKW' => StockBuilder::create('BRKW', 'Breakwater Trust')->build()]);

        $held = $builder->ownership('KSTL');
        $this->assertSame([['name' => 'Breakwater Trust', 'ticker' => 'BRKW', 'share' => AnchorHoldings::forHolder('BRKW')['KSTL']]], $held['holders']);

        $sphere = $builder->ownership('BRKW');
        $this->assertSame(array_keys(AnchorHoldings::forHolder('BRKW')), array_column($sphere['holdings'], 'ticker'));

        $clearing = $builder->ownership('ACC');
        $this->assertSame([['name' => Institutions::SOVEREIGN_RESERVE_FUND, 'ticker' => null, 'share' => StrategicHoldings::stake('ACC')]], $clearing['holders']);
    }
}
