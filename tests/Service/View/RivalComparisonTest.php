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
 * A company's trailing margin against its peers' median, and the peers it is compared with.
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

    /** A thin industry is compared with its sector instead. */
    public function testAThinIndustryFallsBackToItsSector(): void
    {
        $own = $this->stock(1, 'Airlines', 'Industrials');
        $rival = $this->stock(2, 'Airlines', 'Industrials');
        $other = $this->stock(3, 'Railroads', 'Industrials');
        $elsewhere = $this->stock(4, 'Banks - Regional', 'Financial Services');

        $group = PeerGroup::of($own, [$own, $rival, $other, $elsewhere]);
        $this->assertSame('sector', $group['label']);
        $this->assertSame([2, 3], array_map(static fn(Stock $s): ?int => $s->getId(), $group['members']));

        $group = PeerGroup::of($own, [$own, $rival, $this->stock(5, 'Airlines', 'Industrials'), $other]);
        $this->assertSame('industry', $group['label']);
        $this->assertCount(2, $group['members']);
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
