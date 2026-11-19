<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Service\View\PeerGroup;
use App\Service\View\RivalComparisonBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A company's trailing margin against its industry rivals' median or its own three-year average.
 */
#[Group('unit')]
final class RivalComparisonTest extends TestCase
{
    /** A trailing margin sums a year of operating profit over a year of sales, so a big quarter weighs more. */
    public function testTheTrailingMarginSumsFourQuarters(): void
    {
        $reports = [];
        foreach ([[100.0, 10.0], [100.0, 10.0], [100.0, 10.0], [300.0, 60.0]] as $q => [$revenue, $ebit]) {
            $reports[] = $this->report('OWN', $q, $revenue, $ebit);
        }

        $series = RivalComparisonBuilder::series($reports, 'OWN', false);

        $this->assertCount(1, $series['own'], 'Only a quarter with four behind it has a trailing figure.');
        $this->assertEqualsWithDelta(90.0 / 600.0, $series['own'][0]['value'], 1e-12);
    }

    /** The median is taken over the peers that reported that quarter, a rival that later failed among them. */
    public function testTheMedianIsOfThePeersReportingThatQuarter(): void
    {
        $reports = [];
        foreach (['OWN' => 0.10, 'AAA' => 0.05, 'BBB' => 0.08, 'CCC' => 0.20] as $ticker => $margin) {
            for ($q = 0; $q < 5; ++$q) {
                if ($ticker === 'CCC' && $q === 4) {
                    continue;
                }
                $reports[] = $this->report($ticker, $q, 100.0, 100.0 * $margin);
            }
        }

        $series = RivalComparisonBuilder::series($reports, 'OWN', false);

        $this->assertEqualsWithDelta(0.08, $series['median'][0]['value'], 1e-12, 'Three peers: the middle one.');
        $this->assertEqualsWithDelta((0.05 + 0.08) / 2.0, $series['median'][1]['value'], 1e-12, 'The failed rival has stopped reporting.');
    }

    /** Only same-industry rivals count; a thin industry is read against the company's own record, not its sector. */
    public function testAThinIndustryIsReadAgainstItsOwnRecord(): void
    {
        $own = $this->stock(1, 'Airlines', 'Industrials');
        $rival = $this->stock(2, 'Airlines', 'Industrials');
        $other = $this->stock(3, 'Railroads', 'Industrials');
        $elsewhere = $this->stock(4, 'Banks - Regional', 'Financial Services');

        $this->assertSame('own', RivalComparisonBuilder::modeFor(PeerGroup::of($own, [$own, $rival, $other, $elsewhere])));
        $this->assertSame('own', RivalComparisonBuilder::modeFor(PeerGroup::of($own, [$own])), 'Alone in its sector.');

        $group = PeerGroup::of($own, [$own, $rival, $this->stock(5, 'Airlines', 'Industrials'), $other]);
        $this->assertSame('rivals', RivalComparisonBuilder::modeFor($group));
        $this->assertSame([2, 5], array_map(static fn(Stock $s): ?int => $s->getId(), $group['members']));
    }

    /** The three-year average sums twelve quarters of profit over twelve of sales, and waits for all twelve. */
    public function testTheThreeYearAverageSumsTwelveQuarters(): void
    {
        $reports = [];
        for ($q = 0; $q < 13; ++$q) {
            $revenue = $q === 11 ? 300.0 : 100.0;
            $reports[] = $this->report('OWN', $q, $revenue, $q === 11 ? 90.0 : 10.0);
        }
        $reports[] = $this->report('PEER', 12, 100.0, 50.0);

        $series = RivalComparisonBuilder::ownSeries($reports, 'OWN', false);

        $this->assertCount(10, $series['own'], 'A trailing figure from the fourth quarter on.');
        $this->assertCount(10, $series['average']);
        $this->assertNull($series['average'][7]['value'], 'Eleven quarters are not three years.');
        $this->assertEqualsWithDelta((11 * 10.0 + 90.0) / (11 * 100.0 + 300.0), $series['average'][8]['value'], 1e-12);
        $this->assertEqualsWithDelta((11 * 10.0 + 90.0) / (11 * 100.0 + 300.0), $series['average'][9]['value'], 1e-12, 'Quarters 1-12, the big quarter still inside.');
        $this->assertEqualsWithDelta((3 * 10.0 + 90.0) / (3 * 100.0 + 300.0), $series['own'][8]['value'], 1e-12);
    }

    private function report(string $ticker, int $quarter, float $revenue, float $ebit): CorporateReport
    {
        return (new CorporateReport())
            ->setStock((new Stock())->setTicker($ticker))
            ->setTotalTime((string) (($quarter + 1) / 4.0 + 0.05))
            ->setRevenue((string) $revenue)
            ->setEbit((string) $ebit);
    }

    private function stock(int $id, string $industry, string $sector): Stock
    {
        $stock = (new Stock())->setTicker("S{$id}")->setIndustry($industry)->setSector($sector);
        (new \ReflectionProperty(Stock::class, 'id'))->setValue($stock, $id);

        return $stock;
    }
}
