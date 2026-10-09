<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\DTO\MacroStateDTO;
use App\DTO\SecuritiesMarkDTO;
use App\Service\Corporate\SecuritiesBookService;
use App\Service\Market\Bond\BondPricingEngine;
use App\Service\Math\FinancialConstants;
use PHPUnit\Framework\TestCase;

/**
 * The mark on a financial's investment securities book.
 *
 * Every model in the market already held long-duration paper and earned its coupon; none of them owned its
 * price. These are the properties that had to hold before the curve could sink a lender: the mark has the
 * right size and sign, it is convex, it heals at the book's own pace, and the held-to-maturity half stays
 * out of reported equity until something forces a sale.
 */
class SecuritiesBookServiceTest extends TestCase
{
    private const BOOK = 100_000_000_000.0;

    private SecuritiesBookService $service;
    private BondPricingEngine $pricing;

    protected function setUp(): void
    {
        $this->pricing = new BondPricingEngine();
        $this->service = new SecuritiesBookService($this->pricing);
    }

    /** A fitted curve at a policy rate and a long-run level, the two factors the term structure is built on. */
    private function macro(float $policyRate, float $level, float $baseTermPremium = 0.0125, float $sovereignSpread = 0.0): MacroStateDTO
    {
        return new MacroStateDTO(
            policyRate: $policyRate,
            policyRateEma: $policyRate,
            yield10y: $level,
            yield10yEma: $level,
            nsLevel: $level,
            nsBeta1: $policyRate - $level,
            nsBaseTermPremium: $baseTermPremium,
            sovereignRiskSpread: $sovereignSpread,
            sovereignRiskSpreadEma: $sovereignSpread,
        );
    }

    private function base(): MacroStateDTO
    {
        return $this->macro(0.03, 0.0425);
    }

    /** The same curve three hundred basis points higher at both ends. */
    private function hiked(): MacroStateDTO
    {
        return $this->macro(0.06, 0.0725);
    }

    private function roll(
        MacroStateDTO $macro,
        float $duration,
        float $floatingShare = 0.0,
        float $openingMark = 0.0,
        ?float $carryingYield = null,
    ): SecuritiesMarkDTO {
        return $this->service->roll(
            bookValue: self::BOOK,
            openingMark: $openingMark,
            carryingYield: $carryingYield,
            curve: $macro->sovereignCurve(),
            duration: $duration,
            floatingShare: $floatingShare,
            fallbackYield: $macro->yield10yEma,
        );
    }

    public function testABookOpensMarkedFlat(): void
    {
        $mark = $this->roll($this->base(), 7.0);

        self::assertSame(0.0, $mark->totalMark, 'Paper bought at the prevailing curve is worth what was paid for it.');
        self::assertGreaterThan(0.0, $mark->carryingYield, 'The carrying yield is struck at the curve, not left null.');
    }

    /**
     * A seven-year book against a three-hundred point selloff is worth roughly a fifth less. This is the
     * number the whole phase exists to produce: it is the difference between a rate cycle that is merely
     * profitable for a lender and one that can destroy it.
     */
    public function testAThreeHundredPointSelloffMarksALongBookDownAboutTwentyPercent(): void
    {
        $opened = $this->roll($this->base(), 7.0);
        $mark = $this->roll($this->hiked(), 7.0, carryingYield: $opened->carryingYield);

        $ratio = $mark->totalMark / self::BOOK;

        self::assertLessThan(-0.15, $ratio);
        self::assertGreaterThan(-0.25, $ratio);
    }

    /** The mark is a valuation and it is signed: a rally is a gain, not a smaller loss. */
    public function testARallyMarksTheBookUp(): void
    {
        $opened = $this->roll($this->base(), 7.0);
        $mark = $this->roll($this->macro(0.01, 0.0225), 7.0, carryingYield: $opened->carryingYield);

        self::assertGreaterThan(0.0, $mark->totalMark);
    }

    /**
     * Convexity, at symmetric moves in yield. Without the second-order term a rally and a selloff of the
     * same size are worth exactly the same in opposite directions, and the asymmetry that makes a bond a
     * bond is gone. Asserted against the curve rather than the formula, so a sign error in either fails.
     */
    public function testTheGainOnARallyExceedsTheLossOnAnEqualSelloff(): void
    {
        $carry = $this->pricing->zeroYield($this->base()->sovereignCurve(), 7.0);

        $up = $this->macro(0.05, 0.0625);
        $down = $this->macro(0.01, 0.0225);

        // Only meaningful if the two shocks really are the same size; the curve floors at the lower bound.
        $dyUp = $this->pricing->zeroYield($up->sovereignCurve(), 7.0) - $carry;
        $dyDown = $carry - $this->pricing->zeroYield($down->sovereignCurve(), 7.0);
        self::assertEqualsWithDelta($dyUp, $dyDown, 1e-6, 'The two shocks must be symmetric for this to test convexity.');

        $loss = $this->roll($up, 7.0, carryingYield: $carry)->totalMark;
        $gain = $this->roll($down, 7.0, carryingYield: $carry)->totalMark;

        self::assertLessThan(0.0, $loss);
        self::assertGreaterThan(0.0, $gain);
        self::assertGreaterThan(abs($loss), $gain, 'A convex book gains more on a rally than it loses on the same selloff.');
    }

    /**
     * An unrealized loss heals if the firm is never forced to sell: maturities come back at par and are
     * reinvested at the higher curve. That recovery is the asymmetry the forced-sale path destroys.
     */
    public function testAMarkHealsAsTheBookRollsDownWhenRatesStayPut(): void
    {
        $opened = $this->roll($this->base(), 7.0);
        $carry = $opened->carryingYield;
        $mark = 0.0;
        $path = [];

        for ($quarter = 0; $quarter < 12; $quarter++) {
            $rolled = $this->roll($this->hiked(), 7.0, openingMark: $mark, carryingYield: $carry);
            $carry = $rolled->carryingYield;
            $mark = $rolled->totalMark;
            $path[] = $mark;
        }

        self::assertLessThan(0.0, $path[0]);

        for ($i = 1; $i < count($path); $i++) {
            self::assertGreaterThan($path[$i - 1], $path[$i], 'Every quarter held is a quarter of the loss recovered.');
        }
    }

    /**
     * Duration dispersion is the point of the phase. A long book marks down further AND stays underwater
     * longer, which is what decides who dies in a hiking cycle and who buys the corpse.
     */
    public function testALongerBookMarksDownFurtherAndHealsSlower(): void
    {
        $short = $this->markAfter(2.5, 12);
        $long = $this->markAfter(8.0, 12);

        self::assertLessThan($short['initial'], $long['initial'], 'The longer book takes the bigger hit.');
        self::assertLessThan($short['final'], $long['final'], 'And is still carrying it three years later.');
    }

    /** @return array{initial: float, final: float} */
    private function markAfter(float $duration, int $quarters): array
    {
        $carry = $this->roll($this->base(), $duration)->carryingYield;
        $mark = 0.0;
        $initial = null;

        for ($quarter = 0; $quarter < $quarters; $quarter++) {
            $rolled = $this->roll($this->hiked(), $duration, openingMark: $mark, carryingYield: $carry);
            $carry = $rolled->carryingYield;
            $mark = $rolled->totalMark;
            $initial ??= $mark;
        }

        return ['initial' => (float) $initial, 'final' => $mark];
    }

    /** Floating-rate paper reprices to the curve and owns none of its moves. */
    public function testTheFloatingShareCarriesNoDuration(): void
    {
        $unhedged = $this->roll($this->hiked(), 4.5, carryingYield: 0.0425);
        $hedged = $this->roll($this->hiked(), 4.5, floatingShare: 0.40, carryingYield: 0.0425);

        self::assertEqualsWithDelta(4.5, $unhedged->effectiveDuration, 1e-9);
        self::assertEqualsWithDelta(2.7, $hedged->effectiveDuration, 1e-9);
        self::assertGreaterThan($unhedged->totalMark, $hedged->totalMark, 'Less duration, less loss.');
    }

    public function testAnEntirelyFloatingBookIsNotMarked(): void
    {
        $mark = $this->roll($this->hiked(), 7.0, floatingShare: 1.0, carryingYield: 0.0425);

        self::assertSame(0.0, $mark->totalMark);
        self::assertSame(0.0, $mark->effectiveDuration);
    }

    /**
     * An unfitted curve evaluates near zero. Marking a book against nothing would print an enormous phantom
     * gain on every financial in the market, from a harness or a test that zeroed the curve factors.
     */
    public function testAnUnfittedCurveFallsBackToThePublishedYieldInsteadOfPricingAgainstZero(): void
    {
        $bare = new MacroStateDTO(nsLevel: 0.0, nsBeta1: 0.0, nsCurvature: 0.0, nsCurvature2: 0.0);
        self::assertSame(0.0, $bare->sovereignCurve()->level, 'Guard assumes an unfitted curve has a zero level.');

        $mark = $this->service->roll(
            bookValue: self::BOOK,
            openingMark: 0.0,
            carryingYield: 0.0425,
            curve: $bare->sovereignCurve(),
            duration: 7.0,
            floatingShare: 0.0,
            fallbackYield: $bare->yield10yEma,
        );

        self::assertLessThan(
            SecuritiesBookService::MAX_SECURITIES_MARK_RATIO * self::BOOK,
            abs($mark->totalMark),
            'A book discounted at a yield of nothing would pin straight to the clamp.'
        );
    }

    /** The clamp exists because past it the duration expansion is extrapolation rather than a price. */
    public function testTheMarkIsClampedAtTheRatioPastWhichDurationIsExtrapolation(): void
    {
        $mark = $this->roll($this->macro(0.30, 0.32), 30.0, carryingYield: 0.001);

        self::assertEqualsWithDelta(
            -SecuritiesBookService::MAX_SECURITIES_MARK_RATIO * self::BOOK,
            $mark->totalMark,
            1.0
        );
    }

    /**
     * The split is the mechanism. Available-for-sale reaches equity; held-to-maturity is disclosed and
     * nothing else, which is how an institution reports intact capital and is hollow at the same time.
     */
    public function testTheTrancheSplitSumsToTheWholeMark(): void
    {
        $mark = $this->roll($this->hiked(), 7.0, carryingYield: 0.0425);

        self::assertEqualsWithDelta($mark->totalMark, $mark->afsMark + $mark->htmMark, 1e-6);
        self::assertEqualsWithDelta(
            $mark->totalMark * FinancialConstants::DEFAULT_HTM_BOOK_SHARE,
            $mark->htmMark,
            1e-6
        );
    }

    /**
     * Equity carries the level of the mark, so what belongs on it each quarter is the move. Booking the
     * level again every quarter would charge the same loss over and over until the firm was wiped out.
     */
    public function testEquityTakesTheChangeInTheMarkAndNotItsLevel(): void
    {
        $first = $this->roll($this->hiked(), 7.0, carryingYield: 0.0425);
        self::assertEqualsWithDelta($first->afsMark, $first->afsEquityDelta, 1e-6);

        $second = $this->roll($this->hiked(), 7.0, openingMark: $first->totalMark, carryingYield: $first->carryingYield);
        self::assertEqualsWithDelta(
            $second->afsMark - $first->afsMark,
            $second->afsEquityDelta,
            1e-6,
            'The second quarter books only what moved since the first.'
        );
    }

    public function testSellingPartOfTheBookRealizesThatPartOfTheMark(): void
    {
        $realized = $this->service->realizeOnSale(-20_000_000_000.0, self::BOOK, self::BOOK * 0.25);

        self::assertEqualsWithDelta(-5_000_000_000.0, $realized, 1e-6);
    }

    /** A liquidation larger than the marked book realizes all of it and no more. */
    public function testASaleLargerThanTheBookCannotRealizeMoreThanTheWholeMark(): void
    {
        $realized = $this->service->realizeOnSale(-20_000_000_000.0, self::BOOK, self::BOOK * 4.0);

        self::assertEqualsWithDelta(-20_000_000_000.0, $realized, 1e-6);
    }

    public function testThereIsNothingToRealizeOnAnEmptyBook(): void
    {
        self::assertSame(0.0, $this->service->realizeOnSale(-20_000_000_000.0, 0.0, 1_000.0));
        self::assertSame(0.0, $this->service->realizeOnSale(-20_000_000_000.0, self::BOOK, 0.0));
    }

    /**
     * A 100 bps sovereign risk spread widening lifts the sovereign term premium and marks
     * the available-for-sale (AFS) bond portfolio down by its duration-scaled magnitude.
     */
    public function testSovereignRiskSpreadProducesExpectedAfsMark(): void
    {
        $duration = 5.0;
        $baselineMacro = $this->macro(0.03, 0.0425, 0.0125);
        $opened = $this->roll($baselineMacro, $duration);

        // 100 bps sovereign risk premium widening on the curve
        $stressedMacro = $this->macro(0.03, 0.0425, 0.0125 + 0.0100, 0.0100);

        $mark = $this->roll($stressedMacro, $duration, carryingYield: $opened->carryingYield);

        // For a 5y duration book, a ~100bps term premium widening generates a ~-3% markdown after NS duration weighting and quarterly rolldown
        $ratio = $mark->totalMark / self::BOOK;
        self::assertLessThan(0.0, $ratio, 'Sovereign spread widening must mark the sovereign securities book down.');
        self::assertLessThan(-0.02, $ratio, 'A 100bps widening on a 5y duration book must mark down more than 2%.');
        self::assertGreaterThan(-0.05, $ratio, 'A 100bps widening on a 5y duration book cannot exceed roughly 5% markdown.');
        self::assertLessThan(0.0, $mark->afsMark, 'AFS mark must be negative when sovereign spread widens.');
        self::assertEqualsWithDelta(
            $mark->totalMark * (1.0 - FinancialConstants::DEFAULT_HTM_BOOK_SHARE),
            $mark->afsMark,
            1e-6,
            'AFS mark reflects the non-HTM tranche share of the markdown.'
        );
    }
}
