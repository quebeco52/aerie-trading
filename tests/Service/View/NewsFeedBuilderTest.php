<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Entity\DistrictNews;
use App\Entity\Etf;
use App\Entity\EtfEvent;
use App\Entity\Stock;
use App\Entity\StockEvent;
use App\Repository\DistrictNewsRepository;
use App\Repository\EtfEventRepository;
use App\Repository\StockEventRepository;
use App\Service\Event\ShockEvent;
use App\Service\View\NewsFeedBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * The newswire merges three tables into one feed and reads only the tables a section draws on.
 */
#[AllowMockObjectsWithoutExpectations]
class NewsFeedBuilderTest extends TestCase
{
    public function testAllNewsMergesTheThreeSourcesNewestFirst(): void
    {
        $feed = $this->builder(
            district: [$this->district('2026-10-01 12:00')],
            stock: [$this->stockEvent('SPLIT', 0.0, '2026-10-01 12:02'), $this->stockEvent('EARNINGS', 1.0, '2026-10-01 11:58')],
            etf: [$this->etfEvent('2026-10-01 12:01')],
        )->build('all');

        $this->assertSame(['company', 'fund', 'district', 'company'], array_column($feed['items'], 'scope'));
        $this->assertSame(['WEAV', 'LBI', null, 'WEAV'], array_column($feed['items'], 'ticker'));
        $this->assertSame('all', $feed['section']);
    }

    /** A company section is read by type from the company table alone. */
    public function testACompanySectionReadsOnlyItsTypesFromTheCompanyTable(): void
    {
        $districtNews = $this->createMock(DistrictNewsRepository::class);
        $districtNews->expects($this->never())->method('findLatest');
        $stockEvents = $this->createMock(StockEventRepository::class);
        $stockEvents->expects($this->once())->method('findLatest')
            ->with(NewsFeedBuilder::FEED_ROWS, $this->callback(fn (?array $types): bool => $types !== null && in_array('EARNINGS', $types, true) && !in_array('SPLIT', $types, true)))
            ->willReturn([]);

        $feed = (new NewsFeedBuilder($districtNews, $stockEvents, $this->createStub(EtfEventRepository::class)))->build('earnings');

        $this->assertSame('earnings', $feed['section']);
    }

    /** The strip shows the newest story that is a headline, passing over newer routine notices. */
    public function testTheLatestHeadlineSkipsRoutineStories(): void
    {
        $builder = $this->builder(
            district: [$this->district('2026-10-01 10:00')],
            stock: [
                $this->stockEvent('EARNINGS', 0.3, '2026-10-01 12:00'),
                $this->stockEvent('EARNINGS', -9.0, '2026-10-01 11:00'),
            ],
            etf: [],
        );

        $headline = $builder->latestHeadline();

        $this->assertNotNull($headline);
        $this->assertSame('WEAV', $headline['ticker']);
        $this->assertSame(-9.0, $headline['card']['changePercent']);
    }

    /** The desk's verdict at publication stands; only a row from before verdicts were kept is judged on the rule. */
    public function testTheStoredVerdictStandsOverTheRule(): void
    {
        $quietButRan = $this->stockEvent('EARNINGS', 0.1, '2026-10-01 12:00')->setHeadline(true);
        $loudButDidNot = $this->stockEvent('EARNINGS', -9.0, '2026-10-01 11:00')->setHeadline(false);
        $legacy = $this->stockEvent('EARNINGS', -9.0, '2026-10-01 10:00');

        $items = $this->builder([], [$quietButRan, $loudButDidNot, $legacy], [])->build('companies')['items'];

        $this->assertSame([true, false, true], array_column($items, 'headline'));
    }

    public function testThereIsNoHeadlineBeforeTheFirstStory(): void
    {
        $this->assertNull($this->builder([], [], [])->latestHeadline());
    }

    /**
     * @param list<DistrictNews> $district
     * @param list<StockEvent>   $stock
     * @param list<EtfEvent>     $etf
     */
    private function builder(array $district, array $stock, array $etf): NewsFeedBuilder
    {
        $districtNews = $this->createStub(DistrictNewsRepository::class);
        $districtNews->method('findLatest')->willReturn($district);
        $stockEvents = $this->createStub(StockEventRepository::class);
        $stockEvents->method('findLatest')->willReturn($stock);
        $etfEvents = $this->createStub(EtfEventRepository::class);
        $etfEvents->method('findLatest')->willReturn($etf);

        return new NewsFeedBuilder($districtNews, $stockEvents, $etfEvents);
    }

    private function district(string $at): DistrictNews
    {
        return (new DistrictNews())->setDesk(DistrictNews::DESK_GOVERNMENT)->setTopic(ShockEvent::BUDGET_ENACTED)
            ->setDescription('The Diet passed the budget.')->setRecordedAt(new \DateTime($at));
    }

    private function stockEvent(string $type, float $changePercent, string $at): StockEvent
    {
        return (new StockEvent())->setStock((new Stock())->setTicker('WEAV')->setName('Weave Holdings')->setVolatility('0.25'))
            ->setEventType($type)->setDescription('x')->setChangePercent((string) $changePercent)->setRecordedAt(new \DateTime($at));
    }

    private function etfEvent(string $at): EtfEvent
    {
        return (new EtfEvent())->setEtf((new Etf())->setTicker('LBI')->setName('Skein Lakebird 30'))
            ->setEventType('INDEX')->setDescription('Admitted: WEAV.')->setRecordedAt(new \DateTime($at));
    }
}
