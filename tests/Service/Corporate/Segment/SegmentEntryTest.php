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
        // Losses end once the gap falls below one: about four years next door, five on a stretch.
        $this->assertEqualsWithDelta(3.85, SegmentEntry::LEARNING_YEARS * log(SegmentEntry::learningGap(IndustryAdjacency::CLOSE)), 0.01);
        $this->assertEqualsWithDelta(5.17, SegmentEntry::LEARNING_YEARS * log(SegmentEntry::learningGap(IndustryAdjacency::DISTANT)), 0.01);
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
     * What a venture turns out to earn spreads wider the further it is from home, and falls short of what its company
     * counted on by more: next door ~4% on average, on a stretch ~18%; its middle draw a little lower still, by half
     * the variance.
     */
    public function testAVenturesOutcomeIsUncertainAndMoreSoFurtherFromHome(): void
    {
        $this->assertEqualsWithDelta(0.46, SegmentEntry::outcomeSpread(IndustryAdjacency::CLOSE), 1e-12);
        $this->assertEqualsWithDelta(0.90, SegmentEntry::outcomeSpread(IndustryAdjacency::DISTANT), 1e-12);
        $this->assertEqualsWithDelta(log(0.70 / 0.85) - (0.9 * 0.9 / 2.0), SegmentEntry::outcome(IndustryAdjacency::DISTANT, 0.5), 1e-9);
        $this->assertEqualsWithDelta(log(0.92 / 0.96) - (0.46 * 0.46 / 2.0), SegmentEntry::outcome(IndustryAdjacency::CLOSE, 0.5), 1e-9);
        $this->assertLessThan(SegmentEntry::outcome(IndustryAdjacency::DISTANT, 0.9), SegmentEntry::outcome(IndustryAdjacency::CLOSE, 0.9));
        $this->assertGreaterThan(SegmentEntry::outcome(IndustryAdjacency::DISTANT, 0.1), SegmentEntry::outcome(IndustryAdjacency::CLOSE, 0.1));
    }

    /**
     * A venture that pays grows toward its target share of its new market, most of the way within a decade where the
     * target is near, and no faster than a company can grow a business where it is far.
     */
    public function testAVentureGrowsTowardItsTargetShare(): void
    {
        $this->assertSame(0.0, SegmentEntry::pathLevel(1.0, 0.0));
        $this->assertEqualsWithDelta(1.0 - exp(-2.0), SegmentEntry::pathLevel(1.0, 10.0), 1e-12);
        $this->assertEqualsWithDelta(0.2, SegmentEntry::pathGrowth(1.0, 0.0), 1e-12);
        $this->assertEqualsWithDelta((SegmentEntry::pathLevel(1.0, 3.0 + 1e-6) - SegmentEntry::pathLevel(1.0, 3.0)) / 1e-6, SegmentEntry::pathGrowth(1.0, 3.0), 1e-6, 'the growth is the path\'s slope');
        // A target forty times its entry share is approached no faster than a company can grow a business.
        $this->assertEqualsWithDelta(SegmentEntry::MAX_VENTURE_GROWTH, SegmentEntry::pathGrowth(log(40.0), 0.0), 1e-12);
        $this->assertLessThan(log(40.0) / 2.0, SegmentEntry::pathLevel(log(40.0), 10.0), 'not halfway in a decade');
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
