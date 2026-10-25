<?php

declare(strict_types=1);

namespace App\Tests\Data\Company;

use App\Data\Company\InitialMarket;
use App\Data\Company\Sectors;
use App\Data\Company\Segments;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class SegmentsTest extends TestCase
{
    /**
     * A segment is a market of its own: an industry the game prices, not the company's own industry and not another
     * segment's, built from lines its business model really reports, and no line counted in two segments.
     */
    public function testEverySegmentIsADistinctPricedIndustryBuiltFromRealLines(): void
    {
        $industries = array_column(InitialMarket::STOCKS, 'industry', 'ticker');

        foreach (Segments::tickers() as $ticker) {
            $this->assertArrayHasKey($ticker, $industries, "{$ticker} is listed");
            $own = (string) $industries[$ticker];
            $source = (string) file_get_contents((string) (new \ReflectionClass(Sectors::strategyFor($own)))->getFileName());
            $plan = Segments::forTicker($ticker);
            $this->assertNotNull($plan);

            $seenIndustries = [$own];
            $seenStreams = [];
            foreach ($plan['segments'] as $key => $segment) {
                $this->assertNotSame(Segments::MAIN, $key);
                $this->assertArrayHasKey($segment['industry'], Sectors::INDUSTRY_METRICS, "{$ticker} {$key}: a priced industry");
                $this->assertNotContains($segment['industry'], $seenIndustries, "{$ticker} {$key}: a market of its own");
                $this->assertFalse(Sectors::strategyFor($segment['industry'])->isFinancial(), "{$ticker} {$key}: financial books stay in the main segment");
                $seenIndustries[] = $segment['industry'];
                foreach ($segment['streams'] as $stream) {
                    $this->assertStringContainsString("'{$stream}'", $source, "{$ticker} {$key}: {$stream} is a line its model reports");
                    $this->assertNotContains($stream, $seenStreams, "{$ticker}: {$stream} in one segment only");
                    $this->assertSame($key, Segments::segmentOf($ticker, $stream));
                    $seenStreams[] = $stream;
                }
            }
        }
    }

    public function testALineNotListedStaysInTheMainSegment(): void
    {
        $this->assertSame(Segments::MAIN, Segments::segmentOf('SKYL', 'launch_services'));
        $this->assertSame('satellite_internet', Segments::segmentOf('SKYL', 'satellite_broadband'));
        $this->assertSame(Segments::MAIN, Segments::segmentOf('LOON', 'wireless_subscriptions'), 'a company with one market');
        $this->assertNull(Segments::forTicker('LOON'));
    }
}
