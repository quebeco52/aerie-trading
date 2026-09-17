<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Entity\Stock;
use App\Service\Market\StockTracker;
use App\Service\Math\FinancialConstants;
use App\Tests\Support\StockBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The published sell-side target: sticky, optimistic, and revised in steps.
 *
 * Womack (1996) is the reason it is carried rather than recomputed. The LEVEL of a target is a number any
 * reader can derive for themselves; the REVISION is what moves a price. A target recomputed from live fair
 * value every tick is never revised, so it publishes no information at all — which is what this market had
 * before, under the same name.
 *
 * Brav & Lehavy (2003) is the reason it sits above fair value: targets are systematically optimistic.
 */
final class AnalystPriceTargetTest extends TestCase
{
    private StockTracker $tracker;
    private ReflectionMethod $revise;
    /** @var list<array{string, string}> */
    private array $published = [];

    protected function setUp(): void
    {
        $this->published = [];

        $publisher = $this->createStub(\App\Service\Event\MarketEventPublisher::class);
        $publisher->method('publish')->willReturnCallback(
            function (mixed $asset, string $type, string $description): array {
                $this->published[] = [$type, $description];

                return ['type' => $type, 'description' => $description];
            }
        );

        $this->tracker = (new ReflectionClass(StockTracker::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(StockTracker::class, 'eventService'))->setValue($this->tracker, $publisher);

        $this->revise = new ReflectionMethod(StockTracker::class, 'reviseAnalystTarget');
    }

    private function stock(float $price = 100.0): Stock
    {
        return StockBuilder::create('APEX')->withPrice($price)->build();
    }

    private function revise(Stock $stock, float $fairValue, float $splitRatio = 1.0): ?array
    {
        return $this->revise->invoke($this->tracker, $stock, $fairValue, $splitRatio);
    }

    /** Coverage has to start somewhere, and starting is not a revision of anything. */
    public function testInitiatingCoverageStrikesATargetAndPublishesNothing(): void
    {
        $stock = $this->stock();

        self::assertNull($this->revise($stock, 100.0));
        self::assertSame([], $this->published);
        self::assertNotNull($stock->getAnalystPriceTarget());
    }

    public function testTheTargetIsStruckAboveFairValue(): void
    {
        $stock = $this->stock();
        $this->revise($stock, 100.0);

        self::assertEqualsWithDelta(
            100.0 * (1.0 + FinancialConstants::ANALYST_TARGET_OPTIMISM),
            (float) $stock->getAnalystPriceTarget(),
            1e-6
        );
    }

    /**
     * The stickiness. Fair value wandering inside the band leaves the published figure alone, which is what
     * makes the figure a target rather than a second quote.
     */
    public function testSmallDriftInFairValueDoesNotMoveThePublishedTarget(): void
    {
        $stock = $this->stock();
        $this->revise($stock, 100.0);
        $struck = $stock->getAnalystPriceTarget();

        $inside = 1.0 + (FinancialConstants::ANALYST_TARGET_REVISION_THRESHOLD * 0.9);

        self::assertNull($this->revise($stock, 100.0 * $inside));
        self::assertSame($struck, $stock->getAnalystPriceTarget());
        self::assertSame([], $this->published);
    }

    public function testCrossingTheBandRestatesTheTargetAndPublishesTheRevision(): void
    {
        $stock = $this->stock();
        $this->revise($stock, 100.0);
        $struck = (float) $stock->getAnalystPriceTarget();

        $outside = 1.0 + (FinancialConstants::ANALYST_TARGET_REVISION_THRESHOLD * 2.0);
        $event = $this->revise($stock, 100.0 * $outside);

        self::assertNotNull($event);
        self::assertGreaterThan($struck, (float) $stock->getAnalystPriceTarget());
        self::assertCount(1, $this->published);
        self::assertSame('ANALYST', $this->published[0][0]);
        self::assertStringContainsString('raised', $this->published[0][1]);
    }

    public function testACutIsReportedAsACut(): void
    {
        $stock = $this->stock();
        $this->revise($stock, 100.0);

        $this->revise($stock, 100.0 * (1.0 - (FinancialConstants::ANALYST_TARGET_REVISION_THRESHOLD * 2.0)));

        self::assertCount(1, $this->published);
        self::assertStringContainsString('cut', $this->published[0][1]);
    }

    /**
     * A revision carries no shock of its own. What the name is worth has already reached the price through
     * the fair value the target was struck on; pricing it again here pays for the same information twice.
     */
    public function testARevisionIsNewsWithoutBeingAShock(): void
    {
        $captured = null;

        $publisher = $this->createStub(\App\Service\Event\MarketEventPublisher::class);
        $publisher->method('publish')->willReturnCallback(
            function (mixed $asset, string $type, string $description, float $changePercent) use (&$captured): array {
                $captured = $changePercent;

                return [];
            }
        );
        (new ReflectionProperty(StockTracker::class, 'eventService'))->setValue($this->tracker, $publisher);

        $stock = $this->stock();
        $this->revise($stock, 100.0);
        $this->revise($stock, 200.0);

        self::assertSame(0.0, $captured);
    }

    /** A four-for-one is not a seventy-five percent downgrade. */
    public function testASplitRestatesTheTargetSilently(): void
    {
        $stock = $this->stock();
        $this->revise($stock, 100.0);
        $struck = (float) $stock->getAnalystPriceTarget();

        // Fair value is restated by the caller before this runs, so both sides arrive in the new unit.
        $event = $this->revise($stock, 25.0, 4.0);

        self::assertNull($event, 'A split is not a revision.');
        self::assertEqualsWithDelta($struck / 4.0, (float) $stock->getAnalystPriceTarget(), 1e-6);
        self::assertSame([], $this->published);
    }

    public function testAWorthlessFairValueLeavesTheStandingTargetAlone(): void
    {
        $stock = $this->stock();
        $this->revise($stock, 100.0);
        $struck = $stock->getAnalystPriceTarget();

        self::assertNull($this->revise($stock, 0.0));
        self::assertSame($struck, $stock->getAnalystPriceTarget());
    }

    /**
     * The rating moves without anybody revising anything: a name that rallies into its target is downgraded
     * by the rally. That is how a sell-side rating actually behaves.
     */
    public function testTheRatingFollowsThePriceAgainstAStandingTarget(): void
    {
        $stock = $this->stock(100.0);
        $this->revise($stock, 100.0); // target struck at 125

        self::assertSame('Outperform', $stock->getAnalystRating());

        $stock->setPrice('124.00');
        self::assertSame('Neutral', $stock->getAnalystRating(), 'The rally ate the upside.');

        $stock->setPrice('140.00');
        self::assertSame('Underperform', $stock->getAnalystRating());
    }

    public function testAnUncoveredNameIsRatedNeutral(): void
    {
        self::assertSame('Neutral', $this->stock()->getAnalystRating());
    }

    /** The sell side downgrades late: the two bands are deliberately not symmetric about the price. */
    public function testTheRatingBandsAreAsymmetric(): void
    {
        self::assertGreaterThan(
            1.0 - FinancialConstants::ANALYST_RATING_UNDERPERFORM,
            FinancialConstants::ANALYST_RATING_OUTPERFORM - 1.0,
            'It takes more upside to say buy than downside to say sell.'
        );
    }
}
