<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Entity\Etf;
use App\Service\Market\IndexFundAccountant;
use App\Service\Math\FinancialConstants;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * The books of an index fund, which are not the books of the index it tracks.
 *
 * Everything here rests on one identity — price = level x basket + accrued income — and on the two things
 * that move its right-hand side: a fee that is charged for holding the portfolio, and the dividends the
 * portfolio receives. A fund with neither is a price index wearing a fund's clothes, and it silently
 * shortchanges its holders by the whole dividend yield of the market every year.
 */
#[AllowMockObjectsWithoutExpectations]
class IndexFundAccountantTest extends TestCase
{
    private function fund(float $expenseRatio = 0.0050, float $price = 100.0): Etf
    {
        $etf = new Etf();
        $etf->setTicker('LBX');
        $etf->setName('Test Fund');
        $etf->setPrice((string) $price);
        $etf->setExpenseRatio($expenseRatio);

        return $etf;
    }

    private function accountant(?EntityManagerInterface $em = null): IndexFundAccountant
    {
        return new IndexFundAccountant($em ?? $this->createStub(EntityManagerInterface::class));
    }

    // --- The Fee ---

    /**
     * A fund lags its index by its expense ratio over a year. That is the tracking difference every real
     * factsheet reports, and it is the only reason to prefer a cheap fund to a dear one at the same index.
     */
    public function testAFundLagsItsIndexByItsFeeOverAYear(): void
    {
        $accountant = $this->accountant();
        $fund = $this->fund(expenseRatio: 0.0050);

        // A flat index and no dividends anywhere: the ONLY thing that can move the fund is its own fee.
        $ticks = 252;
        $dt = 1.0 / $ticks;
        for ($i = 0; $i < $ticks; $i++) {
            $accountant->accrue($fund, 100.0, 0.0, $dt);
        }

        $this->assertEqualsWithDelta(exp(-0.0050), $fund->getBasketPerShare(), 1e-6);

        // Stated the way a holder experiences it: half a percent of the index, given up over the year. Not
        // exactly half a percent, because a continuously accrued fee compounds — 1 - e^-r, not r.
        $this->assertEqualsWithDelta(0.0050, 1.0 - $fund->getBasketPerShare(), 2e-5);
    }

    /** The lag is a rate, not a per-tick slice: the same year costs the same at any tick rate. */
    public function testTheFeeIsTheSameAtAnyTickRate(): void
    {
        $accountant = $this->accountant();

        $baskets = [];
        foreach ([52, 252, 14400] as $ticks) {
            $fund = $this->fund(expenseRatio: 0.0050);
            for ($i = 0; $i < $ticks; $i++) {
                $accountant->accrue($fund, 100.0, 0.0, 1.0 / $ticks);
            }
            $baskets[] = $fund->getBasketPerShare();
        }

        $this->assertEqualsWithDelta($baskets[0], $baskets[1], 1e-9);
        $this->assertEqualsWithDelta($baskets[1], $baskets[2], 1e-9);
    }

    /**
     * The fee comes out of income where there is income, and only out of the basket where there is not.
     *
     * This is the ordering a real fund uses, and it is what makes a distribution NET of costs. Charged
     * against the basket regardless, the fund would pay out its gross income and sell holdings to cover its
     * own fee — which overstates the yield and understates the tracking, both at once.
     */
    public function testTheFeeIsMetFromIncomeBeforeTheBasketIsTouched(): void
    {
        $accountant = $this->accountant();
        $fund = $this->fund(expenseRatio: 0.0050);

        // A quarter's worth of income arrives first, then a quarter of fees is charged against it.
        $accountant->accrue($fund, 100.0, 1.0, 0.0);
        $this->assertEqualsWithDelta(1.0, $fund->getAccruedIncome(), 1e-9);

        $accountant->accrue($fund, 100.0, 0.0, 0.25);

        $this->assertSame(1.0, $fund->getBasketPerShare(), 'income covered the fee, so nothing was sold');
        $this->assertLessThan(1.0, $fund->getAccruedIncome());

        // What the holder will receive is the income the fund collected less what it cost to run.
        $expectedFee = (100.0 + 1.0) * (1.0 - exp(-0.0050 * 0.25));
        $this->assertEqualsWithDelta(1.0 - $expectedFee, $fund->getAccruedIncome(), 1e-9);
    }

    /**
     * The fee is recorded whichever pocket it came out of.
     *
     * The basket records only what had to be SOLD, and in an ordinary market income covers the fee every
     * time — so a fund reporting its cost off the basket alone would report that it had been free, while
     * taking its fee out of every distribution its holders received.
     */
    public function testTheFeeIsRecordedEvenWhenIncomeAbsorbsIt(): void
    {
        $accountant = $this->accountant();
        $fund = $this->fund(expenseRatio: 0.0050);

        $accountant->accrue($fund, 100.0, 5.0, 0.0);
        $accountant->accrue($fund, 100.0, 0.0, 0.25);

        $expectedFee = (100.0 + 5.0) * (1.0 - exp(-0.0050 * 0.25));

        $this->assertSame(1.0, $fund->getBasketPerShare(), 'income covered it, so nothing was sold');
        $this->assertEqualsWithDelta($expectedFee, $fund->getCumulativeFeesPaid(), 1e-9);
        $this->assertGreaterThan(0.0, $fund->getCumulativeFeesPaid());
    }

    /** A fund that charges nothing tracks its index exactly. */
    public function testAFreeFundTracksPerfectly(): void
    {
        $fund = $this->fund(expenseRatio: 0.0);

        for ($i = 0; $i < 252; $i++) {
            $this->accountant()->accrue($fund, 100.0, 0.0, 1.0 / 252);
        }

        $this->assertSame(1.0, $fund->getBasketPerShare());
        $this->assertSame(0.0, $fund->getCumulativeFeesPaid());
    }

    // --- The Income ---

    /**
     * The fund collects what its own holdings earned, not what the whole index earned.
     *
     * A fund that has sold a tenth of its basket over the years to pay fees owns nine tenths of an index
     * unit and receives nine tenths of the index's dividend. Crediting the full index dividend would have
     * it paying out income on holdings it no longer has.
     */
    public function testIncomeIsCollectedOnWhatTheFundStillOwns(): void
    {
        $accountant = $this->accountant();
        $fund = $this->fund(expenseRatio: 0.0);
        $fund->setBasketPerShare(0.9);

        $accountant->accrue($fund, 100.0, 2.0, 0.0);

        $this->assertEqualsWithDelta(1.8, $fund->getAccruedIncome(), 1e-9);
    }

    // --- The Distribution ---

    public function testDistributingPaysOutTheAccrualAndEmptiesIt(): void
    {
        $conn = $this->createMock(Connection::class);
        // Two statements: the ledger rows, then the cash credited from them.
        $conn->expects($this->exactly(2))->method('executeStatement');

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $fund = $this->fund();
        $fund->setAccruedIncome(0.62);

        $paid = $this->accountant($em)->distribute($fund, new \DateTime('2026-09-15 12:00:00'));

        $this->assertEqualsWithDelta(0.62, $paid, 1e-9);
        $this->assertSame(0.0, $fund->getAccruedIncome());
        $this->assertSame([0.62], $fund->getRecentDistributions());
    }

    /**
     * A payment too small to write a ledger row for is carried into the next quarter, not forfeited.
     *
     * The distinction matters: the cash is the holders' either way, and dropping it would be a slow leak
     * that nothing in the accounts would ever show.
     */
    public function testAnUnpayablySmallDistributionIsCarriedRatherThanLost(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('getConnection');

        $fund = $this->fund();
        $tiny = FinancialConstants::FUND_MINIMUM_DISTRIBUTION / 2.0;
        $fund->setAccruedIncome($tiny);

        $paid = $this->accountant($em)->distribute($fund, new \DateTime());

        $this->assertSame(0.0, $paid);
        $this->assertEqualsWithDelta($tiny, $fund->getAccruedIncome(), 1e-12);
        $this->assertSame([], $fund->getRecentDistributions());
    }

    /** A trailing yield is an annual figure, so it is the trailing year of payments and no more. */
    public function testOnlyTheTrailingYearOfDistributionsIsKept(): void
    {
        $fund = $this->fund();

        foreach ([0.10, 0.20, 0.30, 0.40, 0.50] as $payment) {
            $fund->recordDistribution($payment, new \DateTime());
        }

        $this->assertCount(FinancialConstants::FUND_DISTRIBUTIONS_PER_YEAR, $fund->getRecentDistributions());
        $this->assertSame([0.50, 0.40, 0.30, 0.20], $fund->getRecentDistributions());
        $this->assertEqualsWithDelta(1.40, $fund->trailingDistribution(), 1e-9);
    }

    // --- The Identity ---

    /**
     * The level can always be recovered from the price, whatever the fund's books have done to it.
     *
     * Everything that reads a level off a fund depends on this: the committee restates its divisor against
     * the level, and reading the price instead would fold the fund's own fee and its undistributed income
     * into the index itself.
     */
    public function testTheIndexLevelIsRecoverableFromTheFundPrice(): void
    {
        $fund = $this->fund();
        $fund->setBasketPerShare(0.97);
        $fund->setAccruedIncome(0.85);
        $fund->setPrice((string) ((137.5 * 0.97) + 0.85));

        $this->assertEqualsWithDelta(137.5, $fund->getIndexLevel(), 1e-9);
    }

    /**
     * A holder loses nothing when the fund pays out: the price falls by exactly what left, and the cash is
     * in their hand instead. Wealth is conserved across the ex-date, which is why the drop is not a return.
     */
    public function testADistributionMovesCashWithoutMovingWealth(): void
    {
        $conn = $this->createStub(Connection::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $level = 120.0;
        $fund = $this->fund();
        $fund->setBasketPerShare(0.98);
        $fund->setAccruedIncome(1.40);
        $fund->setPrice((string) (($level * 0.98) + 1.40));

        $wealthBefore = (float) $fund->getPrice();

        $paid = $this->accountant($em)->distribute($fund, new \DateTime());
        $priceAfter = ($level * $fund->getBasketPerShare()) + $fund->getAccruedIncome();

        $this->assertEqualsWithDelta($wealthBefore, $priceAfter + $paid, 1e-9);
        $this->assertEqualsWithDelta($paid, $wealthBefore - $priceAfter, 1e-9);
    }

    /**
     * The whole point, in one measurement: a holder who reinvests nothing but keeps the cash ends the year
     * with the index's price return plus its dividends, less the fee. A fund that ignored income ended it
     * with the price return alone.
     */
    public function testAHoldersTotalReturnIsThePriceReturnPlusIncomeLessTheFee(): void
    {
        $conn = $this->createStub(Connection::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $accountant = $this->accountant($em);
        $fund = $this->fund(expenseRatio: 0.0050);

        $ticks = 252;
        $dt = 1.0 / $ticks;
        // A flat index paying a 2% yield, spread evenly across the year.
        $level = 100.0;
        $incomePerTick = ($level * 0.02) / $ticks;

        $collected = 0.0;
        for ($tick = 1; $tick <= $ticks; $tick++) {
            $accountant->accrue($fund, $level, $incomePerTick, $dt);

            if ($tick % ($ticks / FinancialConstants::FUND_DISTRIBUTIONS_PER_YEAR) === 0) {
                $collected += $accountant->distribute($fund, new \DateTime('2026-01-01 00:00:00 +' . $tick . ' days'));
            }
        }

        $priceAtEnd = ($level * $fund->getBasketPerShare()) + $fund->getAccruedIncome();
        $totalReturn = (($priceAtEnd + $collected) / $level) - 1.0;

        // Index return is zero, so the whole of it is the 2% yield less the 50bp of fee.
        $this->assertEqualsWithDelta(0.0150, $totalReturn, 2e-4);

        // And the price alone — what the old fund published — shows almost none of it.
        $this->assertLessThan(0.002, abs(($priceAtEnd / $level) - 1.0));
    }
}
