<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate\Segment;

use App\Data\Company\Sectors;
use App\Data\Company\Segments;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\Industry\IndustryOutlook;
use App\Service\Corporate\Segment\SegmentBookOpener;
use App\Service\Corporate\Segment\SegmentBooks;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class SegmentBookOpenerTest extends TestCase
{
    /**
     * A company in one market whose industry's outlook is gloomy or bright opens its books to enter another: the market
     * it sells into is the one it sold into before, the day the books open and after the next report files its main
     * segment's outlook, so the outlook is counted once.
     */
    public function testOpeningBooksToEnterKeepsTheMarketTheOutlookAlreadyGrew(): void
    {
        $totalTime = 5.0;
        $stock = new Stock();
        $stock->setTicker('ZZZZ');
        $stock->setIndustry('Packaged Foods');
        $stock->setTotalEquity('1000000000');
        $stock->setTotalRevenue('800000000');
        $stock->setAssetTurnover('0.25');
        $stock->setOperatingMargin('0.12');
        $stock->setEarningsMomentumZ([IndustryOutlook::STATE_BELIEF => 0.2]);
        $before = CorporateMetrics::secularMarketGrowth($stock, $totalTime);

        (new SegmentBookOpener(new CorporateMetrics()))->openForEntry($stock, Sectors::strategyFor('Packaged Foods'), new MacroStateDTO(totalTime: $totalTime), 1, 252);
        $atOpening = CorporateMetrics::secularMarketGrowth($stock, $totalTime);
        // The next report files the main segment's outlook, its industry's belief, unchanged.
        $stock->setEarningsMomentumZ([SegmentBooks::STATE_OUTLOOK . Segments::MAIN => 0.2] + ($stock->getEarningsMomentumZ() ?? []));
        $nextReport = CorporateMetrics::secularMarketGrowth($stock, $totalTime);

        $this->assertNotNull(SegmentBooks::plant($stock->getEarningsMomentumZ() ?? []), 'the books open');
        $this->assertEqualsWithDelta($before, $atOpening, 1e-12 * $before);
        $this->assertEqualsWithDelta($before, $nextReport, 1e-12 * $before);
        $this->assertEqualsWithDelta(1.0, ($stock->getEarningsMomentumZ() ?? [])[SegmentBooks::STATE_MARKET . Segments::MAIN] ?? null, 1e-12, 'the main segment sells into the company\'s whole market');
    }
}
