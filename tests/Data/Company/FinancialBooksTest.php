<?php

declare(strict_types=1);

namespace App\Tests\Data\Company;

use App\Data\Company\FinancialBooks;
use App\Data\Company\IndustryMarkets;
use App\Data\Company\InitialMarket;
use App\Data\Company\Sectors;
use App\Entity\Stock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class FinancialBooksTest extends TestCase
{
    public function testEveryBookIsADistinctPricedIndustryWithRealFinancialStreams(): void
    {
        $industries = array_column(InitialMarket::STOCKS, 'industry', 'ticker');

        foreach (FinancialBooks::tickers() as $ticker) {
            $this->assertArrayHasKey($ticker, $industries, "{$ticker} is listed");
            $own = (string) $industries[$ticker];
            $strategy = Sectors::strategyFor($own);
            $this->assertTrue($strategy->isFinancial(), "{$ticker} is a financial firm");
            $source = (string) file_get_contents((string) (new \ReflectionClass($strategy))->getFileName());
            $plan = FinancialBooks::forTicker($ticker);
            $this->assertNotNull($plan);

            $seenIndustries = [$own];
            $seenStreams = [];
            foreach ($plan['books'] as $key => $book) {
                $this->assertNotSame(FinancialBooks::MAIN, $key);
                $this->assertArrayHasKey($book['industry'], Sectors::INDUSTRY_METRICS, "{$ticker} {$key}: a priced industry");
                $this->assertTrue(IndustryMarkets::hasIndustry($book['industry']), "{$ticker} {$key}: sized in IndustryMarkets");
                $this->assertNotContains($book['industry'], $seenIndustries, "{$ticker} {$key}: distinct from main and sibling books");
                $seenIndustries[] = $book['industry'];

                foreach ($book['streams'] as $stream) {
                    $this->assertStringContainsString("'{$stream}'", $source, "{$ticker} {$key}: {$stream} appears in its model");
                    $this->assertNotContains($stream, $seenStreams, "{$ticker}: {$stream} assigned once");
                    $this->assertSame($key, FinancialBooks::bookOf($ticker, $stream));
                    $seenStreams[] = $stream;
                }
            }
        }
    }

    public function testSharesFallBackToTuningAndShiftWithReportedStreams(): void
    {
        $single = (new Stock())->setTicker('WELL')->setIndustry('Insurance - Life');
        $this->assertFalse(FinancialBooks::hasBooks($single));
        $this->assertSame([], FinancialBooks::industries($single));
        $this->assertSame([FinancialBooks::MAIN => 1.0], FinancialBooks::shares($single));

        $dove = (new Stock())->setTicker('DOVE')->setIndustry('Insurance - Diversified');
        $this->assertTrue(FinancialBooks::hasBooks($dove));
        $this->assertSame([
            FinancialBooks::MAIN => 'Insurance - Diversified',
            'life' => 'Insurance - Life',
        ], FinancialBooks::industries($dove));
        $this->assertSame('Property and casualty', FinancialBooks::label($dove, FinancialBooks::MAIN));
        $this->assertSame('Life and annuities', FinancialBooks::label($dove, 'life'));

        $opening = FinancialBooks::shares($dove);
        $this->assertEqualsWithDelta(0.55, $opening[FinancialBooks::MAIN], 1e-12);
        $this->assertEqualsWithDelta(0.45, $opening['life'], 1e-12);
        $this->assertEqualsWithDelta(1.0, array_sum($opening), 1e-12);

        $fromStreams = FinancialBooks::shares($dove, ['property_casualty_premiums' => 600.0, 'life_insurance_premiums' => 400.0]);
        $this->assertEqualsWithDelta(0.60, $fromStreams[FinancialBooks::MAIN], 1e-12);
        $this->assertEqualsWithDelta(0.40, $fromStreams['life'], 1e-12);

        $dove->setEarningsMomentumZ(['share:property_casualty_premiums' => 0.70, 'share:life_insurance_premiums' => 0.30, 'share:x:Steel' => 0.50]);
        $fromMomentum = FinancialBooks::shares($dove);
        $this->assertEqualsWithDelta(0.70, $fromMomentum[FinancialBooks::MAIN], 1e-12);
        $this->assertEqualsWithDelta(0.30, $fromMomentum['life'], 1e-12);

        $this->assertSame([['ticker' => 'DOVE', 'book' => 'life']], FinancialBooks::sellersForIndustry('Insurance - Life'));
        $this->assertSame([], FinancialBooks::sellersForIndustry('Insurance - Diversified'));
    }
}
