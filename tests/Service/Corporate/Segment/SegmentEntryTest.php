<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate\Segment;

use App\Data\Company\IndustryAdjacency;
use App\Data\Company\Sectors;
use App\Service\Corporate\Segment\SegmentEntry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class SegmentEntryTest extends TestCase
{
    /**
     * The further an industry is from what a company knows, the less of its rivals' margin it ever earns there and the
     * further short of it it starts: next door it keeps 96% and starts three times short, on a stretch 85% and ~4.4.
     */
    public function testDistanceCostsMarginAndLengthensTheLearning(): void
    {
        $this->assertEqualsWithDelta(0.096, SegmentEntry::attainedMargin(0.10, IndustryAdjacency::CLOSE), 1e-12);
        $this->assertEqualsWithDelta(0.085, SegmentEntry::attainedMargin(0.10, IndustryAdjacency::DISTANT), 1e-12);
        $this->assertEqualsWithDelta(3.0, SegmentEntry::learningGap(IndustryAdjacency::CLOSE), 1e-12);
        $this->assertEqualsWithDelta(4.375, SegmentEntry::learningGap(IndustryAdjacency::DISTANT), 1e-12);
        $this->assertEqualsWithDelta(3.0 / M_E, SegmentEntry::learningLeft(3.0, SegmentEntry::LEARNING_YEARS), 1e-12);
        // Losses end once the gap falls below one: three and a third years next door, four and a half on a stretch; 90%
        // of the margin is learned in ten to eleven.
        $this->assertEqualsWithDelta(3.30, SegmentEntry::LEARNING_YEARS * log(SegmentEntry::learningGap(IndustryAdjacency::CLOSE)), 0.01);
        $this->assertEqualsWithDelta(4.43, SegmentEntry::LEARNING_YEARS * log(SegmentEntry::learningGap(IndustryAdjacency::DISTANT)), 0.01);
        $this->assertEqualsWithDelta(10.20, SegmentEntry::LEARNING_YEARS * log(10.0 * SegmentEntry::learningGap(IndustryAdjacency::CLOSE)), 0.01);
        $this->assertEqualsWithDelta(11.33, SegmentEntry::LEARNING_YEARS * log(10.0 * SegmentEntry::learningGap(IndustryAdjacency::DISTANT)), 0.01);
    }

    /**
     * A venture is built at its share of the company's plant, or for a small market at the plant that serves its share
     * of it, whichever is less.
     */
    public function testAVentureIsSizedForTheParentOrItsNewMarketWhicheverIsLess(): void
    {
        $this->assertEqualsWithDelta(SegmentEntry::VENTURE_PLANT_SHARE * 100_000.0, SegmentEntry::ventureConstruction(100_000.0, 1_000_000.0, 1.0), 1e-9);
        $this->assertEqualsWithDelta(SegmentEntry::MAX_VENTURE_MARKET_SHARE * 20_000.0 / 2.0, SegmentEntry::ventureConstruction(100_000.0, 20_000.0, 2.0), 1e-9);
        $this->assertSame(0.0, SegmentEntry::ventureConstruction(100_000.0, 20_000.0, 0.0));
    }

    /**
     * What a venture turns out to earn spreads as margins do among one industry's firms, at any distance, and falls
     * short of what its company counted on by more the further it is from home: next door ~8% on average, on a stretch
     * ~35%; its middle draw a little lower still, by half the variance. Even a lucky one keeps no margin far above what
     * it counted on.
     */
    public function testAVenturesOutcomeIsUncertainAndDisappointsMoreFurtherFromHome(): void
    {
        $this->assertEqualsWithDelta(log(0.55 / 0.85) - (0.3 * 0.3 / 2.0), SegmentEntry::outcome(IndustryAdjacency::DISTANT, 0.5), 1e-9);
        $this->assertEqualsWithDelta(log(0.88 / 0.96) - (0.3 * 0.3 / 2.0), SegmentEntry::outcome(IndustryAdjacency::CLOSE, 0.5), 1e-9);
        foreach ([0.01, 0.1, 0.9, 0.99] as $uniform) {
            $this->assertLessThan(SegmentEntry::outcome(IndustryAdjacency::CLOSE, $uniform), SegmentEntry::outcome(IndustryAdjacency::DISTANT, $uniform), "at {$uniform}");
        }
        $this->assertLessThan(log(2.0), SegmentEntry::outcome(IndustryAdjacency::CLOSE, 0.99), 'one in a hundred earns less than twice what it counted on');
    }

    /**
     * A business bought into a new industry turns out to earn, on average, the share of what its buyer counted on that
     * an owner this far from home keeps of its rivals' margin, less the further it is from home; and it shows that over
     * its integration, linearly.
     */
    public function testABoughtBusinessDisappointsMoreTheFurtherFromHome(): void
    {
        $mean = static function (float $closeness): float {
            $sum = 0.0;
            for ($i = 0; $i < 20_000; $i++) {
                $sum += exp(SegmentEntry::boughtOutcome($closeness, ($i + 0.5) / 20_000));
            }

            return $sum / 20_000;
        };
        foreach ([IndustryAdjacency::CLOSE, IndustryAdjacency::RELATED, IndustryAdjacency::DISTANT] as $closeness) {
            $this->assertEqualsWithDelta(1.0 - (SegmentEntry::REALISED_DISTANCE_PENALTY * (1.0 - $closeness)), $mean($closeness), 2e-3, "closeness {$closeness}");
        }

        $this->assertSame(0.5, SegmentEntry::integrationLeft(1.0, SegmentEntry::INTEGRATION_YEARS / 2.0));
        $this->assertSame(0.0, SegmentEntry::integrationLeft(0.25, SegmentEntry::INTEGRATION_YEARS));
    }

    /**
     * A venture means to reach the share a typical incumbent holds in its new market, within the room the fringe leaves
     * and no further than e times the share it entered at, the most it can grow at the fastest a company grows a
     * business; it grows toward that most of the way within a decade.
     */
    public function testAVentureGrowsTowardATypicalIncumbentsShare(): void
    {
        $this->assertEqualsWithDelta(log(2.0), SegmentEntry::ventureTarget(0.02, 0.04, 0.5), 1e-12);
        $this->assertEqualsWithDelta(log(1.5), SegmentEntry::ventureTarget(0.02, 0.04, 0.03), 1e-12, 'within the room');
        $this->assertEqualsWithDelta(SegmentEntry::MAX_VENTURE_GROWTH * SegmentEntry::VENTURE_GROWTH_YEARS, SegmentEntry::ventureTarget(0.01, 0.30, 0.9), 1e-12, 'no further than it can grow');
        $this->assertSame(0.0, SegmentEntry::ventureTarget(0.05, 0.04, 0.5), 'already as large as a typical incumbent');
        $this->assertSame(0.0, SegmentEntry::ventureTarget(0.0, 0.04, 0.5));

        $this->assertSame(0.0, SegmentEntry::pathLevel(1.0, 0.0));
        $this->assertEqualsWithDelta(1.0 - exp(-2.0), SegmentEntry::pathLevel(1.0, 10.0), 1e-12);
        $this->assertEqualsWithDelta(SegmentEntry::MAX_VENTURE_GROWTH, SegmentEntry::pathGrowth(1.0, 0.0), 1e-12, 'its largest target grows at the fastest a company can');
        $this->assertEqualsWithDelta((SegmentEntry::pathLevel(1.0, 3.0 + 1e-6) - SegmentEntry::pathLevel(1.0, 3.0)) / 1e-6, SegmentEntry::pathGrowth(1.0, 3.0), 1e-6, 'the growth is the path\'s slope');
        // A target set before targets were bounded is still approached no faster than a company can grow a business.
        $this->assertEqualsWithDelta(SegmentEntry::MAX_VENTURE_GROWTH, SegmentEntry::pathGrowth(log(40.0), 0.0), 1e-12);
    }

    /** Entry arrives at a constant rate, scaled by the manager's appetite for deals: Poisson over the quarter. */
    public function testEntryIsARareEventAtTheManagersAppetite(): void
    {
        $this->assertEqualsWithDelta(1.0 - exp(-SegmentEntry::ENTRY_RATE * 0.25), SegmentEntry::entryChance(1.0, 0.25), 1e-15);
        $this->assertEqualsWithDelta(1.0 - exp(-SegmentEntry::ENTRY_RATE * 2.5 * 0.25), SegmentEntry::entryChance(2.5, 0.25), 1e-15);
        $this->assertSame(0.0, SegmentEntry::entryChance(0.0, 0.25));
        // Over twenty years an operator with a market worth entering goes about once.
        $this->assertEqualsWithDelta(SegmentEntry::ENTRY_RATE * 20.0, -log(1.0 - SegmentEntry::entryChance(1.0, 20.0)), 1e-12);
    }

    /** The market is drawn in proportion to what each is worth, so a better one is likelier and not certain. */
    public function testTheMarketIsDrawnInProportionToItsWorth(): void
    {
        $values = ['Steel' => 1.0, 'Tools & Accessories' => 3.0, 'Chemicals' => -2.0];

        $this->assertSame('Steel', SegmentEntry::choose($values, 0.10));
        $this->assertSame('Tools & Accessories', SegmentEntry::choose($values, 0.30));
        $this->assertSame('Tools & Accessories', SegmentEntry::choose($values, 0.999999));
        $this->assertNull(SegmentEntry::choose(['Chemicals' => -2.0], 0.5), 'nothing worth entering');
    }

    /**
     * A business is built only where plant earns at its market's price: not in a financial book or a commodity mine.
     */
    public function testOnlyAnIndustryRunOnPricedPlantCanBeEntered(): void
    {
        $this->assertTrue(SegmentEntry::isEnterable('Specialty Industrial Machinery', Sectors::strategyFor('Specialty Industrial Machinery')));
        $this->assertFalse(SegmentEntry::isEnterable('Banks - Regional', Sectors::strategyFor('Banks - Regional')));
        $this->assertFalse(SegmentEntry::isEnterable('Copper', Sectors::strategyFor('Copper')));
        $this->assertFalse(SegmentEntry::isEnterable('Conglomerates', Sectors::strategyFor('Conglomerates')));
    }
}
