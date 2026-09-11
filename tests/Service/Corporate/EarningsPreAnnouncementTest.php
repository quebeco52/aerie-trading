<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Entity\Stock;
use App\Service\Corporate\EarningsEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Math\FinancialConstants;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Negative earnings pre-announcements (Kasznik & Lev 1995, "To Warn or Not to Warn").
 *
 * The asymmetry is the finding: firms heading into a large negative surprise warn, firms heading into a
 * large positive one stay quiet. What management may honestly know before the close is what the firm is
 * already carrying — an accrual reversal owed back and cost that has reached the base but not yet the
 * price — both of which are persisted state, not a peek at draws that have not been made.
 *
 * The other half of the contract is that a warning is NEWS. A squeeze the firm has carried for a year was
 * disclosed a year ago; only the change since the last report is worth guiding on, and only the share of
 * it that analysts cannot already read off published input-price series.
 */
#[AllowMockObjectsWithoutExpectations]
final class EarningsPreAnnouncementTest extends TestCase
{
    private const TICKS_PER_YEAR = 252;

    /** Quarterly revenue is $1bn and the cost base is 60% of it, against $100m of quarterly earnings. */
    private const VARIABLE_COST_RATIO = 0.60;

    private EarningsEngine $engine;
    /** @var list<array{0: string, 1: float}> */
    private array $published = [];

    protected function setUp(): void
    {
        $this->published = [];

        $publisher = $this->createStub(MarketEventPublisher::class);
        $publisher->method('publish')->willReturnCallback(
            function (mixed $asset, string $type, string $description, float $changePercent): array {
                $this->published[] = [$type, $changePercent];

                // Mirrors the real publisher: one event shape, which the caller wraps into a list.
                return ['type' => $type, 'ticker' => 'ZZZZ', 'description' => $description, 'change_percent' => $changePercent];
            }
        );

        $this->engine = (new ReflectionClass(EarningsEngine::class))->newInstanceWithoutConstructor();
        $marketEvent = new ReflectionProperty(EarningsEngine::class, 'marketEvent');
        $marketEvent->setValue($this->engine, $publisher);
    }

    private function makeStock(
        float $accrualBank = 0.0,
        float $costLevel = 0.0,
        float $recovered = 0.0,
        float $priorUnrecovered = 0.0
    ): Stock {
        $stock = new Stock();
        $stock->setTicker('ZZZZ');
        $stock->setIndustry('Conglomerates');
        $stock->setPrice('100.00');
        $stock->setTotalRevenue('4000000000');   // $1bn a quarter
        $stock->setOperatingMargin('0.10');      // $100m of structural quarterly earnings power
        $stock->setTotalNetIncome('400000000');
        $stock->setStructuralVariableMargin(self::VARIABLE_COST_RATIO);
        $stock->setManagedAccrualBank($accrualBank);
        $stock->setEarningsMomentumZ([
            FinancialConstants::STATE_INPUT_COST_LEVEL => $costLevel,
            FinancialConstants::STATE_INPUT_COST_RECOVERY => $recovered,
            FinancialConstants::STATE_PRIOR_UNRECOVERED_COST => $priorUnrecovered,
        ]);

        return $stock;
    }

    private function warningTick(Stock $stock): int
    {
        return EarningsEngine::resolvePreAnnouncementTick($stock->getTicker(), self::TICKS_PER_YEAR);
    }

    /**
     * The shortfall is sized against structural earnings power, not the trailing print. Scaled by trailing
     * net income, a firm that had just broken even warned on a trivial reversal and took the full price
     * reaction, and a firm running a larger loss warned less because the absolute value grew.
     */
    public function testWarningIsScaledByStructuralEarningsPowerNotTheTrailingPrint(): void
    {
        // A $5m reversal due: 5% of the $100m the business structurally earns in a quarter, so no warning,
        // even though the trailing figure is a rounding error away from zero.
        $breakEven = $this->makeStock(accrualBank: 5_000_000.0 / FinancialConstants::EARNINGS_MANAGEMENT_REVERSAL_RATE);
        $breakEven->setTotalNetIncome('1000');

        $this->assertSame([], $this->engine->evaluatePreAnnouncement($breakEven, $this->warningTick($breakEven), self::TICKS_PER_YEAR));
        $this->assertSame(0.0, $breakEven->getPreAnnouncedShortfall());

        // The same $60m reversal is a 60% miss against the same earnings power whether the firm is deep in
        // loss or not: a bigger loss does not make the warning smaller.
        $shallowLoss = $this->makeStock(accrualBank: 60_000_000.0 / FinancialConstants::EARNINGS_MANAGEMENT_REVERSAL_RATE);
        $shallowLoss->setTotalNetIncome('-100000000');
        $deepLoss = $this->makeStock(accrualBank: 60_000_000.0 / FinancialConstants::EARNINGS_MANAGEMENT_REVERSAL_RATE);
        $deepLoss->setTotalNetIncome('-4000000000');

        $this->assertNotEmpty($this->engine->evaluatePreAnnouncement($shallowLoss, $this->warningTick($shallowLoss), self::TICKS_PER_YEAR));
        $this->assertNotEmpty($this->engine->evaluatePreAnnouncement($deepLoss, $this->warningTick($deepLoss), self::TICKS_PER_YEAR));
        $this->assertEqualsWithDelta($this->published[0][1], $this->published[1][1], 1e-9, 'identical shortfall, identical earnings power, identical reaction');
    }

    /** A cost squeeze that opened up THIS quarter is news, and gets warned. */
    public function testFreshCostSqueezeProducesAWarning(): void
    {
        // 20 points of unrecovered cost ratio appeared since the last report: 0.20 x 0.60 x $1bn x the
        // quarter of it analysts cannot see = $30m against $100m of quarterly earnings.
        $stock = $this->makeStock(costLevel: 0.20, recovered: 0.0, priorUnrecovered: 0.0);

        $events = $this->engine->evaluatePreAnnouncement($stock, $this->warningTick($stock), self::TICKS_PER_YEAR);

        $this->assertNotEmpty($events, 'A squeeze this size opening in one quarter is known before the close.');
        $this->assertSame('GUIDANCE', $this->published[0][0]);
        $this->assertLessThan(0.0, $this->published[0][1], 'A warning is bad news and must move the price down.');
    }

    /**
     * The regression that matters: a squeeze the firm was ALREADY carrying is not news.
     *
     * Warning on the standing level had a firm in a lasting cost shock cut guidance every single quarter
     * for as long as the shock lasted — measured across the sector models, 27 of 46 issued a guidance cut
     * in at least 20 of 24 consecutive quarters.
     */
    public function testStandingSqueezeAlreadyCarriedIsNotWarnedAgain(): void
    {
        $stock = $this->makeStock(costLevel: 0.20, recovered: 0.0, priorUnrecovered: 0.20);

        $this->assertSame(
            [],
            $this->engine->evaluatePreAnnouncement($stock, $this->warningTick($stock), self::TICKS_PER_YEAR),
            'The street has had this squeeze in its numbers since it was first disclosed.'
        );
        $this->assertSame(0.0, $stock->getPreAnnouncedShortfall());
    }

    /** A squeeze that is EASING is not a warning either, however large the level it is easing from. */
    public function testEasingSqueezeIsNotWarned(): void
    {
        $stock = $this->makeStock(costLevel: 0.20, recovered: 0.12, priorUnrecovered: 0.20);

        $this->assertSame([], $this->engine->evaluatePreAnnouncement($stock, $this->warningTick($stock), self::TICKS_PER_YEAR));
    }

    /** A small squeeze is absorbed silently rather than guided. */
    public function testImmaterialShortfallIsNotWarned(): void
    {
        $stock = $this->makeStock(costLevel: 0.01, recovered: 0.0);

        $this->assertSame([], $this->engine->evaluatePreAnnouncement($stock, $this->warningTick($stock), self::TICKS_PER_YEAR));
        $this->assertSame(0.0, $stock->getPreAnnouncedShortfall());
    }

    /** The asymmetry: good news is never pre-announced, only bad. */
    public function testFavourableConditionsAreNeverPreAnnounced(): void
    {
        // Selling prices have run well ahead of costs — a large positive surprise brewing.
        $stock = $this->makeStock(costLevel: 0.01, recovered: 0.10);

        $this->assertSame(
            [],
            $this->engine->evaluatePreAnnouncement($stock, $this->warningTick($stock), self::TICKS_PER_YEAR),
            'Kasznik & Lev: firms warn on bad news, not good.'
        );
    }

    /** Accrual reversals owed to earlier quarters are foreknowledge in their own right, and are firm-private. */
    public function testOwedAccrualReversalAloneCanTriggerAWarning(): void
    {
        // A bank whose quarterly reversal exceeds the warning threshold against $100m of earnings.
        $bank = 100_000_000.0 / FinancialConstants::EARNINGS_MANAGEMENT_REVERSAL_RATE * 0.5;
        $stock = $this->makeStock(accrualBank: $bank);

        $this->assertNotEmpty(
            $this->engine->evaluatePreAnnouncement($stock, $this->warningTick($stock), self::TICKS_PER_YEAR),
            'A firm that papered over earlier quarters knows the reversal is coming.'
        );
    }

    /**
     * The published percentage has to be the move the price actually took.
     *
     * Every surface that renders an event — the stock page feed, the district flare — reads change_percent
     * as a realized return, so a headline reaction the price never took was simply a false print.
     */
    public function testPublishedReactionIsTheRealizedPriceMove(): void
    {
        $stock = $this->makeStock(costLevel: 0.20);
        $priceBefore = (float) $stock->getPrice();

        $this->engine->evaluatePreAnnouncement($stock, $this->warningTick($stock), self::TICKS_PER_YEAR);

        $priceAfter = (float) $stock->getPrice();
        $this->assertLessThan($priceBefore, $priceAfter, 'A guidance cut has to actually reprice the stock.');
        $this->assertEqualsWithDelta(
            $this->published[0][1] / 100.0,
            ($priceAfter - $priceBefore) / $priceBefore,
            1e-6,
            'The published change_percent must equal the realized return.'
        );
    }

    /** No warning, no repricing. */
    public function testQuietQuarterLeavesThePriceAlone(): void
    {
        $stock = $this->makeStock(costLevel: 0.20, priorUnrecovered: 0.20);

        $this->engine->evaluatePreAnnouncement($stock, $this->warningTick($stock), self::TICKS_PER_YEAR);

        $this->assertSame(100.0, (float) $stock->getPrice());
    }

    /** Warnings only land in the pre-announcement window, not on arbitrary ticks. */
    public function testWarningOnlyFiresInItsWindow(): void
    {
        $stock = $this->makeStock(costLevel: 0.20);
        $offTick = ($this->warningTick($stock) + 5) % (int) (self::TICKS_PER_YEAR / 4);

        $this->assertSame([], $this->engine->evaluatePreAnnouncement($stock, $offTick, self::TICKS_PER_YEAR));
    }

    /** The warning lands before the report it refers to. */
    public function testWarningPrecedesTheReport(): void
    {
        $this->assertLessThan(
            EarningsEngine::resolveReportingTick('ZZZZ', self::TICKS_PER_YEAR),
            EarningsEngine::resolvePreAnnouncementTick('ZZZZ', self::TICKS_PER_YEAR),
            'A pre-announcement that arrives after the report is not a pre-announcement.'
        );
    }

    /** Analysts take the guided shortfall out of their estimate, which is the point of warning. */
    public function testWarningIsHandedToConsensus(): void
    {
        $stock = $this->makeStock(costLevel: 0.20);
        $this->engine->evaluatePreAnnouncement($stock, $this->warningTick($stock), self::TICKS_PER_YEAR);

        $this->assertGreaterThan(0.0, $stock->getPreAnnouncedShortfall(), 'The guided shortfall must reach the consensus estimate.');
    }

    /**
     * The guided figure is only the part consensus cannot already see. Analysts read published input-price
     * series, so guiding the whole squeeze would double-count what is in the estimate and turn the report
     * that follows into a large manufactured beat.
     */
    public function testGuidedShortfallExcludesWhatConsensusAlreadyPrices(): void
    {
        $stock = $this->makeStock(costLevel: 0.20);
        $this->engine->evaluatePreAnnouncement($stock, $this->warningTick($stock), self::TICKS_PER_YEAR);

        $wholeSqueeze = 0.20 * self::VARIABLE_COST_RATIO * 1_000_000_000.0;
        $expected = $wholeSqueeze
            * (1.0 - FinancialConstants::ANALYST_COST_BASE_VISIBILITY)
            * FinancialConstants::PREANNOUNCEMENT_CONSENSUS_ABSORPTION;

        $this->assertEqualsWithDelta($expected, $stock->getPreAnnouncedShortfall(), 1.0);
        $this->assertLessThan($wholeSqueeze, $stock->getPreAnnouncedShortfall());
    }

    /**
     * Before the first report opens the CIR margin state, the fallback has to be the VARIABLE cost ratio.
     *
     * The complement of the operating margin is the total cost ratio: it still carries fixed costs and
     * depreciation, neither of which scales with volume. Charging it against the quarter's revenue sized the
     * squeeze off the whole cost base and overstated the guided shortfall by 1/(1 - fixedCostRatio) — 0.85
     * against 0.5525 at the seeded defaults this asserts, a factor of 1.54.
     */
    public function testFallbackCostRatioIsVariableRatherThanTotal(): void
    {
        $stock = $this->makeStock(costLevel: 0.30);
        $stock->setStructuralVariableMargin(null);   // no report has opened the margin process yet
        $stock->setOperatingMargin('0.15');
        $stock->setFixedCostRatio(0.35);

        $this->engine->evaluatePreAnnouncement($stock, $this->warningTick($stock), self::TICKS_PER_YEAR);

        $variableCostRatio = (1.0 - 0.15) * (1.0 - 0.35);   // MarketResetCommand's construction
        $expected = 0.30 * $variableCostRatio * 1_000_000_000.0
            * (1.0 - FinancialConstants::ANALYST_COST_BASE_VISIBILITY)
            * FinancialConstants::PREANNOUNCEMENT_CONSENSUS_ABSORPTION;

        $this->assertEqualsWithDelta($expected, $stock->getPreAnnouncedShortfall(), 1.0);
        $this->assertLessThan(
            0.30 * (1.0 - 0.15) * 1_000_000_000.0
                * (1.0 - FinancialConstants::ANALYST_COST_BASE_VISIBILITY)
                * FinancialConstants::PREANNOUNCEMENT_CONSENSUS_ABSORPTION,
            $stock->getPreAnnouncedShortfall(),
            'Guiding off the total cost ratio charges fixed costs and depreciation to volume.'
        );
    }

    /** A bankrupt shell issues no guidance. */
    public function testBankruptFirmIssuesNoGuidance(): void
    {
        $stock = $this->makeStock(costLevel: 0.20);
        $stock->setIsBankrupt(true);

        $this->assertSame([], $this->engine->evaluatePreAnnouncement($stock, $this->warningTick($stock), self::TICKS_PER_YEAR));
    }
}
