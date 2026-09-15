<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Entity\Stock;
use App\Repository\StockRepository;
use App\Service\Market\IndexCommittee;
use App\Service\Market\Index\InMemoryIndexMembershipStore;
use App\Service\View\EtfCompositionBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class EtfCompositionBuilderTest extends TestCase
{
    /** @param list<Stock> $board */
    private function builder(array $board): EtfCompositionBuilder
    {
        $stocks = $this->createMock(StockRepository::class);
        $stocks->method('findAll')->willReturn($board);

        // No membership taken, which is the fund tracking the whole live board — the behaviour these cases
        // were written against, and still what a market does before its first reconstitution.
        return new EtfCompositionBuilder(
            $stocks,
            new IndexCommittee(
                new InMemoryIndexMembershipStore(),
                $this->createStub(\App\Service\Market\EtfTracker::class)
            )
        );
    }

    private function listing(string $ticker, float $price, float $shares, bool $bankrupt = false): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setName($ticker . ' Co');
        $stock->setSector('Industrials');
        $stock->setPrice((string) $price);
        $stock->setSharesOutstanding((string) $shares);
        $stock->setIsBankrupt($bankrupt);

        return $stock;
    }

    public function testWeightsAreCapitalisationSharesAndSumToTheWholeFund(): void
    {
        $composition = $this->builder([
            $this->listing('BIG', 100.0, 3_000.0),   // 300,000
            $this->listing('SMALL', 10.0, 1_000.0),  //  10,000
        ])->build();

        $weights = array_column($composition['components'], 'weight', 'ticker');

        $this->assertEqualsWithDelta(100.0, array_sum($weights), 1e-9);
        $this->assertEqualsWithDelta(300_000 / 310_000 * 100, $weights['BIG'], 1e-9);
    }

    public function testConstituentsAreListedHeaviestFirst(): void
    {
        $composition = $this->builder([
            $this->listing('SMALL', 10.0, 1_000.0),
            $this->listing('BIG', 100.0, 3_000.0),
            $this->listing('MID', 50.0, 1_000.0),
        ])->build();

        $this->assertSame(['BIG', 'MID', 'SMALL'], array_column($composition['components'], 'ticker'));
    }

    /**
     * A delisted shell is not a holding. Counting it would both list it as a constituent and dilute
     * every live weight by a claim that no longer exists.
     */
    public function testADelistedShellIsNotAHolding(): void
    {
        $composition = $this->builder([
            $this->listing('LIVE', 100.0, 1_000.0),
            $this->listing('DEAD', 80.0, 1_000.0, bankrupt: true),
        ])->build();

        $this->assertSame(['LIVE'], array_column($composition['components'], 'ticker'));
        $this->assertSame(['LIVE'], $composition['pieLabels']);
        $this->assertArrayNotHasKey('DEAD', $composition['sharesMap']);
        $this->assertEqualsWithDelta(100.0, $composition['components'][0]['weight'], 1e-9);
    }

    public function testTheSeriesStayAlignedWithTheConstituentList(): void
    {
        $composition = $this->builder([
            $this->listing('AAA', 20.0, 1_000.0),
            $this->listing('BBB', 30.0, 1_000.0),
        ])->build();

        $this->assertSame(['AAA', 'BBB'], $composition['pieLabels']);
        $this->assertSame([20_000.0, 30_000.0], $composition['pieData']);
        $this->assertSame(['AAA' => 1_000.0, 'BBB' => 1_000.0], $composition['sharesMap']);
    }

    /**
     * A board on which nothing is still listed must not divide by a zero total.
     */
    public function testAnEntirelyDelistedBoardYieldsNoWeightsRatherThanDividingByZero(): void
    {
        $composition = $this->builder([
            $this->listing('DEAD', 80.0, 1_000.0, bankrupt: true),
        ])->build();

        $this->assertSame([], $composition['components']);
        $this->assertSame([], $composition['pieData']);
    }

    public function testWeightsAreStruckOnTheFloatNotTheWholeCompany(): void
    {
        // What a passive fund can hold is the part of the company that trades. Two firms of identical
        // market capitalisation are not identical positions if one of them is mostly closely held.
        $open = $this->listing('OPEN', 100.0, 1_000_000);
        $closed = $this->listing('CLOSED', 100.0, 1_000_000);
        $closed->setPublicFloatPercentage('0.2500');

        $components = $this->builder([$open, $closed])->build()['components'];
        $byTicker = array_column($components, null, 'ticker');

        $this->assertGreaterThan($byTicker['CLOSED']['weight'], $byTicker['OPEN']['weight']);
        $this->assertEqualsWithDelta(80.0, $byTicker['OPEN']['weight'], 1e-6);
        $this->assertEqualsWithDelta(20.0, $byTicker['CLOSED']['weight'], 1e-6);
    }

    public function testOnlyTheIndexMembersAreHoldings(): void
    {
        $inside = $this->listing('IN', 100.0, 1_000_000);
        $outside = $this->listing('OUT', 100.0, 1_000_000);

        $store = new InMemoryIndexMembershipStore();
        $store->store(0, ['IN']);

        $stocks = $this->createStub(StockRepository::class);
        $stocks->method('findAll')->willReturn([$inside, $outside]);

        $builder = new EtfCompositionBuilder(
            $stocks,
            new IndexCommittee($store, $this->createStub(\App\Service\Market\EtfTracker::class))
        );

        $tickers = array_column($builder->build()['components'], 'ticker');

        $this->assertSame(['IN'], $tickers);
    }
}
