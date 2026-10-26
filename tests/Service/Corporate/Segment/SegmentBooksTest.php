<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate\Segment;

use App\Data\Company\ManagementStyle;
use App\Data\Company\Sectors;
use App\Data\Company\Segments;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\Segment\SegmentBooks;
use App\Service\Corporate\Segment\SegmentEntry;
use App\Service\Corporate\Segment\SegmentOutlook;
use App\Service\Math\MathUtility;
use App\Service\Model\StreamContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class SegmentBooksTest extends TestCase
{
    /**
     * The books open with each segment holding its revenue share of the plant and the company's share of its own
     * market, so the company's market is the sum of its segments' and nothing about its reach moves the day they open.
     */
    public function testTheBooksOpenAtTheCompanysShareOfEachMarket(): void
    {
        $stock = $this->tern();
        $momentum = [SegmentBooks::STATE_OUTLOOK . Segments::MAIN => 0.05, SegmentBooks::STATE_OUTLOOK . 'air_cargo' => -0.20];
        $stock->setEarningsMomentumZ($momentum);
        $years = 12.0;
        $firmGrowth = CorporateMetrics::secularMarketGrowth($stock, $years);
        $stock->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.9, 'air_cargo' => 0.1], $firmGrowth, SegmentBooks::marketGrowths($stock, $years)) + $momentum);

        $this->assertSame([Segments::MAIN => 0.9, 'air_cargo' => 0.1], SegmentBooks::plant($stock->getEarningsMomentumZ() ?? []));
        $this->assertEqualsWithDelta($firmGrowth, SegmentBooks::firmMarketGrowth($stock, $years), 1e-12);
        $this->assertEqualsWithDelta($firmGrowth, CorporateMetrics::secularMarketGrowth($stock, $years), 1e-12, 'the market the engine sizes on');
        $shares = SegmentBooks::addressableShares($stock, 0.3, $years);
        $this->assertEqualsWithDelta(0.3 / $firmGrowth, $shares[Segments::MAIN], 1e-12);
        $this->assertEqualsWithDelta(0.3 / $firmGrowth, $shares['air_cargo'], 1e-12);
    }

    /** Each segment's market then grows with its own industry's outlook: cargo's falls and the company's with it. */
    public function testEachSegmentsMarketGrowsWithItsOwnOutlook(): void
    {
        $stock = $this->tern();
        $momentum = SegmentBooks::open([Segments::MAIN => 0.9, 'air_cargo' => 0.1], 1.0, [Segments::MAIN => 1.0, 'air_cargo' => 1.0]);
        $stock->setEarningsMomentumZ($momentum);
        $before = SegmentBooks::firmMarketGrowth($stock, 0.0);
        $cargoBefore = SegmentBooks::addressableShares($stock, 0.3, 0.0)['air_cargo'];

        $stock->setEarningsMomentumZ([SegmentBooks::STATE_OUTLOOK . 'air_cargo' => log(0.5)] + $momentum);

        $this->assertEqualsWithDelta($before - 0.05, SegmentBooks::firmMarketGrowth($stock, 0.0), 1e-12, 'cargo was a tenth of the market and halved');
        $this->assertEqualsWithDelta($before - 0.05, CorporateMetrics::secularMarketGrowth($stock, 0.0), 1e-12, 'the market the engine sizes on');
        $this->assertEqualsWithDelta(2.0 * $cargoBefore, SegmentBooks::addressableShares($stock, 0.3, 0.0)['air_cargo'], 1e-12);
    }

    /**
     * The company's load is its segments' over the plant, its price is theirs over the output that runs, and each
     * segment's share of the revenue is its running plant at its price.
     */
    public function testTheCompanysQuarterIsItsSegmentsOverThePlant(): void
    {
        $company = SegmentBooks::aggregate([Segments::MAIN => 0.8, 'air_cargo' => 0.2], [Segments::MAIN => 1.05, 'air_cargo' => 0.9], [Segments::MAIN => 1.0, 'air_cargo' => 0.5]);

        $this->assertEqualsWithDelta(0.9, $company['load'], 1e-12);
        $this->assertEqualsWithDelta(((0.8 * 1.05) + (0.1 * 0.9)) / 0.9, $company['price'], 1e-12);
        $this->assertEqualsWithDelta(0.09 / 0.93, $company['targets']['air_cargo'], 1e-12);
        $this->assertEqualsWithDelta(1.0, array_sum($company['targets']), 1e-12);
    }

    /**
     * What a segment's own industry's rivals took from it reaches its lines alone: its share of the quarter falls,
     * while the company's price and load, struck on the plant, do not move. A by-product's plant runs with the plant it
     * rides whether or not its own industry buys all it makes.
     */
    public function testARivalsGainReachesOnlyTheSegmentItCameFrom(): void
    {
        $plant = [Segments::MAIN => 0.8, 'air_cargo' => 0.2];
        $prices = [Segments::MAIN => 1.0, 'air_cargo' => 1.0];
        $loads = [Segments::MAIN => 1.0, 'air_cargo' => 0.5];
        $plain = SegmentBooks::aggregate($plant, $prices, $loads);
        $drained = SegmentBooks::aggregate($plant, $prices, $loads, ['air_cargo' => -0.10]);

        $this->assertEqualsWithDelta(0.09 / (0.8 + 0.09), $drained['targets']['air_cargo'], 1e-12);
        $this->assertSame($plain['price'], $drained['price']);
        $this->assertSame($plain['load'], $drained['load']);

        $flying = SegmentBooks::aggregate($plant, $prices, $loads, [], ['air_cargo' => 1.0]);
        $this->assertEqualsWithDelta(1.0, $flying['load'], 1e-12, 'the hold flies full or empty');
        $this->assertEqualsWithDelta(0.9, $flying['price'], 1e-12, 'and the space left unsold earns nothing');
        $this->assertSame($plain['targets'], $flying['targets']);
    }

    /**
     * A segment entering an industry whose firms already sell into a known market holds its share of that market; the
     * main segment keeps the company's share of its own.
     */
    public function testASegmentOpensAtItsShareOfItsIndustrysMarket(): void
    {
        $stock = $this->tern();
        $stock->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.9, 'air_cargo' => 0.1], 1.0, [], 0.37, ['air_cargo' => 0.03]));

        $shares = SegmentBooks::addressableShares($stock, 0.37, 0.0);
        $this->assertEqualsWithDelta(0.37, $shares[Segments::MAIN], 1e-12);
        $this->assertEqualsWithDelta(0.03, $shares['air_cargo'], 1e-12);
        $this->assertEqualsWithDelta(0.37 / (0.9 + (0.1 * 0.37 / 0.03)), 0.37 / (SegmentBooks::firmMarketGrowth($stock, 0.0) ?? 1.0), 1e-12, 'the company now sells into both');
    }

    /**
     * A small arm of a large company enters a large industry as the small seller it is; an arm that outsells every
     * listed firm in its industry sells into more than their market, and holds no more of its own than its company
     * does of its.
     */
    public function testASegmentEntersAtItsShareOfTheLargerMarket(): void
    {
        $this->assertEqualsWithDelta(5.0 / 200.0, SegmentBooks::entryMarketShare(5.0, 200.0, 0.37), 1e-12, 'cargo in freight');
        $this->assertEqualsWithDelta(0.148, SegmentBooks::entryMarketShare(45.5, 30.0, 0.148), 1e-12, 'terminals larger than the ports market');
        $this->assertEqualsWithDelta(0.3, SegmentBooks::entryMarketShare(0.0, 0.0, 0.3), 1e-12, 'nothing to measure');
    }

    /**
     * The plant opens at the mix the plan was steering its lines to, summed by segment, not at one quarter's draw; a
     * plan that keeps no mix opens at the last report's revenue.
     */
    public function testThePlantOpensAtThePlansMix(): void
    {
        $momentum = ['weight:carrier_networks' => 0.75, 'weight:consumer_terminals' => 0.25, SegmentOutlook::STATE_SHARE . Segments::MAIN => 0.7, SegmentOutlook::STATE_SHARE . 'consumer_devices' => 0.3];

        $this->assertEqualsWithDelta([Segments::MAIN => 0.75, 'consumer_devices' => 0.25], SegmentBooks::openingPlantShares('ERNE', $momentum), 1e-12);
        $this->assertEqualsWithDelta([Segments::MAIN => 0.7, 'consumer_devices' => 0.3], SegmentBooks::openingPlantShares('ERNE', array_slice($momentum, 2, null, true)), 1e-12);
    }

    /**
     * Each segment pays the size penalty on its own market, on its share of the plant: a cargo arm that is most of its
     * market costs the company what that costs it there, not what the company's share of all its markets would.
     */
    public function testEachSegmentPaysTheSizePenaltyOnItsOwnMarket(): void
    {
        $metrics = new CorporateMetrics();
        $macro = new MacroStateDTO(nominalGdpIndex: 1.0, totalTime: 0.0);
        $penaltyAt = static fn(float $share): float => $metrics->calculateMarketSaturationPenalty(
            (new Stock())->setTicker('NONE')->setIndustry('Airlines')->setSamRatio('1.00'),
            $share * CorporateMetrics::BASELINE_SECTOR_TAM,
            $macro,
        );
        $stock = $this->tern()->setSamRatio('1.00');
        $stock->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.9, 'air_cargo' => 0.1], 1.0, [], 0.6, ['air_cargo' => 0.9]));

        $this->assertGreaterThan(0.0, $penaltyAt(0.9));
        $this->assertEqualsWithDelta(
            (0.9 * $penaltyAt(0.6)) + (0.1 * $penaltyAt(0.9)),
            $metrics->calculateMarketSaturationPenalty($stock, 0.6 * CorporateMetrics::BASELINE_SECTOR_TAM, $macro),
            1e-12,
        );
    }

    /** An airline's cargo rides its passenger aircraft; an online retailer's cloud is plant of its own. */
    public function testWhichSegmentsRideTheMainPlant(): void
    {
        $this->assertSame(['air_cargo'], SegmentBooks::jointSegments($this->tern()));
        $this->assertSame([], SegmentBooks::jointSegments((new Stock())->setTicker('WEAV')->setIndustry('Internet Retail')));
    }

    /**
     * A segment's share of the plant grows with the growth capex it was allotted and shrinks with the wear on its idle
     * plant; plant that runs has its wear replaced and keeps its share.
     */
    public function testPlantFollowsTheBuildAndTheIdleWear(): void
    {
        $plant = [Segments::MAIN => 0.9, 'air_cargo' => 0.1];

        $built = SegmentBooks::rollPlant($plant, 1000.0, ['air_cargo' => 10.0], [], 25.0);
        $this->assertEqualsWithDelta(110.0 / 1010.0, $built['air_cargo'], 1e-12);

        $idle = SegmentBooks::rollPlant($plant, 1000.0, [], [Segments::MAIN => 0.5], 25.0);
        $this->assertEqualsWithDelta(100.0 / (900.0 - 11.25 + 100.0), $idle['air_cargo'], 1e-12);
        $this->assertEqualsWithDelta(1.0, array_sum($idle), 1e-12);

        $this->assertSame($plant, SegmentBooks::rollPlant($plant, 1000.0, [], [], 25.0), 'all plant running and nothing built');
    }

    /**
     * A segment's lines take its share of the quarter and split it as the model splits them; with no shares filed the
     * company keeps its weights.
     */
    public function testEachSegmentsLinesSplitItsShareOfTheQuarter(): void
    {
        $momentum = [SegmentBooks::STATE_TARGET . Segments::MAIN => 0.8, SegmentBooks::STATE_TARGET . 'air_cargo' => 0.2];
        $weights = ['leisure_passengers' => 0.45, 'business_passengers' => 0.32, 'air_cargo' => 0.10, 'loyalty_miles' => 0.13];

        $split = SegmentBooks::splitToTargets('TERN', $momentum, $weights) ?? [];

        $this->assertEqualsWithDelta(0.2, $split['air_cargo'], 1e-12);
        $this->assertEqualsWithDelta(0.8 * 0.45 / 0.90, $split['leisure_passengers'], 1e-12);
        $this->assertEqualsWithDelta(1.0, array_sum($split), 1e-12);
        $this->assertNull(SegmentBooks::splitToTargets('TERN', [], $weights));
        $this->assertNull(SegmentBooks::splitToTargets(null, $momentum, $weights));

        $streams = (new StreamContext($momentum, new MathUtility(), ticker: 'TERN'))->resolveActiveStreamWeights($weights);
        $this->assertEqualsWithDelta(0.2, $streams['air_cargo'], 1e-12, 'the models\' stream mix reads the same split');

        // A mix still drifting from an old one moves within each segment, but each segment sells its share now.
        $drifting = $momentum;
        foreach (['leisure_passengers' => 0.40, 'business_passengers' => 0.40, 'air_cargo' => 0.05, 'loyalty_miles' => 0.15] as $line => $old) {
            $drifting["weight:{$line}"] = $old;
            $drifting["share:{$line}"] = $old;
        }
        $streams = (new StreamContext($drifting, new MathUtility(), ticker: 'TERN'))->resolveActiveStreamWeights($weights);
        $this->assertEqualsWithDelta(0.2, $streams['air_cargo'], 1e-12);
        $this->assertNotEqualsWithDelta($split['leisure_passengers'], $streams['leisure_passengers'], 1e-6, 'within the segment the mix still drifts');
    }

    /**
     * A takeover adds its plant to the segment it buys into, the others keep theirs at a smaller share; an airline
     * bought comes with its holds, so belly cargo keeps its share of the plant it rides.
     */
    public function testATakeoverAddsToTheSegmentItBuysInto(): void
    {
        $ads = SegmentBooks::acquireInto([Segments::MAIN => 0.8, 'ads_and_cloud' => 0.2], 'ads_and_cloud', 1000.0, 250.0);
        $this->assertEqualsWithDelta(450.0 / 1250.0, $ads['ads_and_cloud'], 1e-12);
        $this->assertEqualsWithDelta(1.0, array_sum($ads), 1e-12);

        $airline = SegmentBooks::acquireInto([Segments::MAIN => 0.9, 'air_cargo' => 0.1], Segments::MAIN, 1000.0, 250.0, ['air_cargo']);
        $this->assertEqualsWithDelta(0.1, $airline['air_cargo'], 1e-12);
        $this->assertEqualsWithDelta(1.0, array_sum($airline), 1e-12);
    }

    /** A segment sold whole holds no plant; the rest is the others' in the proportions they held it. */
    public function testASegmentSoldWholeLeavesTheRestItsPlant(): void
    {
        $plant = SegmentBooks::sellSegment([Segments::MAIN => 0.5, 'industrial' => 0.3, 'consumer' => 0.2], 'industrial');

        $this->assertSame(0.0, $plant['industrial']);
        $this->assertEqualsWithDelta(0.5 / 0.7, $plant[Segments::MAIN], 1e-12);
        $this->assertEqualsWithDelta(1.0, array_sum($plant), 1e-12);
    }

    /**
     * The business a company would sell whole is the one whose plant earns least; never its main business, never a
     * by-product of its main plant, never one it no longer holds.
     */
    public function testTheWeakestSegmentIsTheOneWhosePlantEarnsLeast(): void
    {
        $triv = (new Stock())->setTicker('TRIV')->setIndustry('Conglomerates');
        $triv->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.4, 'industrial' => 0.3, 'consumer' => 0.3], 1.0, []) + [
            SegmentBooks::STATE_TARGET . Segments::MAIN => 0.2,
            SegmentBooks::STATE_TARGET . 'industrial' => 0.33,
            SegmentBooks::STATE_TARGET . 'consumer' => 0.47,
        ]);
        $this->assertSame('industrial', SegmentBooks::weakestSegment($triv), 'the main business earns least but is not for sale');

        $tern = $this->tern();
        $tern->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.9, 'air_cargo' => 0.1], 1.0, []));
        $this->assertNull(SegmentBooks::weakestSegment($tern));
        $this->assertNull(SegmentBooks::weakestSegment((new Stock())->setTicker('LOON')->setIndustry('Telecom Services')));
    }

    /**
     * A business entered in play is not sold as the weakest before it has shown what it earns: not while its plant is
     * being built, while it still loses money learning the trade, or while one bought is being integrated. Once it has
     * learned enough to earn, its income share counts only what it earns so far, and once sold, what it still had to
     * build, learn, show or be sold for goes with it, its place kept at no plant so it is not entered again.
     */
    public function testABusinessEnteredInPlayIsSoldOnlyOnceItHasShownWhatItEarns(): void
    {
        $segment = Segments::enteredKey('Farm & Heavy Construction Machinery');
        $stock = (new Stock())->setTicker('ZZZZ')->setIndustry('Auto Manufacturers');
        $books = SegmentBooks::open([Segments::MAIN => 0.9, $segment => 0.1], 1.0, []) + [
            SegmentBooks::STATE_TARGET . Segments::MAIN => 0.9,
            SegmentBooks::STATE_TARGET . $segment => 0.1,
            SegmentBooks::STATE_MARGIN . Segments::MAIN => 0.10,
            SegmentBooks::STATE_MARGIN . $segment => 0.10,
        ];
        $this->assertSame($segment, SegmentBooks::weakestSegment($stock->setEarningsMomentumZ($books)));
        $this->assertNull(SegmentBooks::weakestSegment($stock->setEarningsMomentumZ([SegmentBooks::STATE_PENDING . $segment => 1_000.0] + $books)), 'still being built');
        $this->assertNull(SegmentBooks::weakestSegment($stock->setEarningsMomentumZ([SegmentBooks::STATE_LEARNING . $segment => 1.5] + $books)), 'still losing money');
        $this->assertNull(SegmentBooks::weakestSegment($stock->setEarningsMomentumZ([SegmentBooks::STATE_INTEGRATING . $segment => 0.5] + $books)), 'still being integrated');

        $learning = [SegmentBooks::STATE_LEARNING . $segment => 0.5] + $books;
        $this->assertSame($segment, SegmentBooks::weakestSegment($stock->setEarningsMomentumZ($learning)));
        $this->assertEqualsWithDelta(0.1 * 0.5 / ((0.9 * 1.0) + (0.1 * 0.5)), SegmentBooks::earningShares($learning, $segment)['income'], 1e-12, 'half its learned margin so far');

        $sold = SegmentBooks::withoutEntryState([
            SegmentBooks::STATE_PLANT . $segment => 0.0,
            SegmentBooks::STATE_PENDING . $segment => 10.0,
            SegmentBooks::STATE_LEARNING . $segment => 0.2,
            SegmentBooks::STATE_LEARNING_START . $segment => 3.0,
            SegmentBooks::STATE_VENTURE_OUTCOME . $segment => -0.1,
            SegmentBooks::STATE_VENTURE_TARGET . $segment => 2.0,
            SegmentBooks::STATE_INTEGRATING . $segment => 0.0,
            SegmentBooks::STATE_FOR_SALE . $segment => 30.0,
            SegmentBooks::STATE_GOODWILL . $segment => 0.0,
        ] + $books, $segment);
        $this->assertSame(0.0, $sold[SegmentBooks::STATE_PLANT . $segment] ?? null);
        foreach ([SegmentBooks::STATE_PENDING, SegmentBooks::STATE_LEARNING, SegmentBooks::STATE_LEARNING_START, SegmentBooks::STATE_VENTURE_OUTCOME, SegmentBooks::STATE_VENTURE_TARGET, SegmentBooks::STATE_INTEGRATING, SegmentBooks::STATE_FOR_SALE, SegmentBooks::STATE_GOODWILL] as $prefix) {
            $this->assertArrayNotHasKey($prefix . $segment, $sold);
        }
        $this->assertArrayHasKey($segment, SegmentBooks::industries($stock->setEarningsMomentumZ($sold)), 'its place is kept');
    }

    /**
     * A deal buys into a business its company has put up for sale no more; a company whose lines sell into several
     * markets enters a new one only once its own books are open.
     */
    public function testNothingIsBoughtIntoABusinessForSaleAndBooksOpenBeforeAnEntry(): void
    {
        $segment = Segments::enteredKey('Farm & Heavy Construction Machinery');
        $stock = (new Stock())->setTicker('ZZZZ')->setIndustry('Auto Manufacturers');
        $stock->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.8, $segment => 0.2], 1.0, []) + [SegmentBooks::STATE_FOR_SALE . $segment => 10.0]);
        $this->assertSame([Segments::MAIN], array_keys(SegmentBooks::dealSegments($stock)));
        $this->assertSame(Segments::MAIN, SegmentBooks::acquiringSegment($stock, 0.95));

        $this->assertTrue(SegmentBooks::canEnter((new Stock())->setTicker('ZZZZ')->setIndustry('Auto Manufacturers')), 'one plant opens its books as it enters');
        $weav = (new Stock())->setTicker('WEAV')->setIndustry('Internet Retail');
        $this->assertFalse(SegmentBooks::canEnter($weav), 'its own segments\' books are not open yet');
        $this->assertTrue(SegmentBooks::canEnter($weav->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.8, 'ads_and_cloud' => 0.2], 1.0, []))));
    }

    /** A deal buys into a segment in proportion to its plant; a by-product is bought only with the main plant. */
    public function testADealBuysIntoASegmentInProportionToItsPlant(): void
    {
        $weav = (new Stock())->setTicker('WEAV')->setIndustry('Internet Retail');
        $weav->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.8, 'ads_and_cloud' => 0.2], 1.0, []));
        $this->assertSame(Segments::MAIN, SegmentBooks::acquiringSegment($weav, 0.79));
        $this->assertSame('ads_and_cloud', SegmentBooks::acquiringSegment($weav, 0.81));

        $tern = $this->tern();
        $tern->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.9, 'air_cargo' => 0.1], 1.0, []));
        $this->assertSame(Segments::MAIN, SegmentBooks::acquiringSegment($tern, 0.95));

        // Nothing is bought into a segment that rides its model's own path, and it is not sold whole.
        $this->assertSame(Segments::MAIN, SegmentBooks::acquiringSegment($this->skylarkAt(12.0, 0.6), 0.95));
        $this->assertNull(SegmentBooks::weakestSegment($this->skylarkAt(12.0, 0.6)));
        $this->assertTrue(SegmentBooks::holdsPlant($this->skylarkAt(12.0, 0.6), 'satellite_internet'));
        $this->assertFalse(SegmentBooks::holdsPlant($this->skylarkAt(12.0, 1.0), 'satellite_internet'), 'one with no plant');
    }

    /**
     * Funds that cover every plan fund every plan; short funds fill the plan that earns most first, spread pro rata as
     * far as management supports its weak segments.
     */
    public function testScarceFundsFillTheBestPlanFirst(): void
    {
        $plans = ['a' => 60.0, 'b' => 40.0];
        $returns = ['a' => 0.08, 'b' => 0.20];

        $this->assertSame($plans, SegmentBooks::allotByReturn($plans, $returns, 100.0, 0.0));
        $this->assertEqualsWithDelta(['a' => 10.0, 'b' => 40.0], SegmentBooks::allotByReturn($plans, $returns, 50.0, 0.0), 1e-12);
        $this->assertEqualsWithDelta(['a' => 30.0, 'b' => 20.0], SegmentBooks::allotByReturn($plans, $returns, 50.0, 1.0), 1e-12);
        $this->assertEqualsWithDelta(['a' => 20.0, 'b' => 30.0], SegmentBooks::allotByReturn($plans, $returns, 50.0, 0.5), 1e-12);
    }

    /**
     * A deal does not buy into a segment whose new plant did not clear its hurdle at the last report (a glut), unless
     * management supports its weak segments; where every segment is in a glut it is drawn by plant alone.
     */
    public function testADealAvoidsASegmentInAGlut(): void
    {
        $weav = (new Stock())->setTicker('WEAV')->setIndustry('Internet Retail');
        $weav->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.8, 'ads_and_cloud' => 0.2], 1.0, []) + [SegmentBooks::STATE_BUILDS . 'ads_and_cloud' => 0.0]);
        $this->assertSame(Segments::MAIN, SegmentBooks::acquiringSegment($weav, 0.99));

        $weav->setManagementStyle(ManagementStyle::EmpireBuilder);
        $this->assertSame('ads_and_cloud', SegmentBooks::acquiringSegment($weav, 0.99), 'an empire builder buys into it anyway');

        $weav->setManagementStyle(null);
        $weav->setEarningsMomentumZ([SegmentBooks::STATE_BUILDS . Segments::MAIN => 0.0] + ($weav->getEarningsMomentumZ() ?? []));
        $this->assertSame(Segments::MAIN, SegmentBooks::acquiringSegment($weav, 0.79));
        $this->assertSame('ads_and_cloud', SegmentBooks::acquiringSegment($weav, 0.81));
    }

    /**
     * A company looks outside its businesses for a deal only when none it can buy into earns its cost of capital on its
     * plant: one not yet filed counts as earning it, a by-product follows the plant it rides, and a venture given up on
     * counts for nothing. It takes on one new industry at a time.
     */
    public function testACompanyLooksOutsideItsBusinessesOnlyWhenNonePays(): void
    {
        $shrk = (new Stock())->setTicker('SHRK')->setIndustry('Auto Manufacturers');
        $this->assertTrue(SegmentBooks::paysAnywhere($shrk), 'nothing filed yet');
        $shrk->setEarningsMomentumZ([SegmentBooks::STATE_PAYS . Segments::MAIN => 0.0]);
        $this->assertFalse(SegmentBooks::paysAnywhere($shrk), 'one plant, earning less than its cost');
        $this->assertFalse(SegmentBooks::isDiversifying($shrk));

        $weav = (new Stock())->setTicker('WEAV')->setIndustry('Internet Retail');
        $books = SegmentBooks::open([Segments::MAIN => 0.8, 'ads_and_cloud' => 0.2], 1.0, []);
        $weav->setEarningsMomentumZ($books + [SegmentBooks::STATE_PAYS . Segments::MAIN => 0.0]);
        $this->assertTrue(SegmentBooks::paysAnywhere($weav), 'its ads arm still pays');
        $weav->setEarningsMomentumZ($books + [SegmentBooks::STATE_PAYS . Segments::MAIN => 0.0, SegmentBooks::STATE_PAYS . 'ads_and_cloud' => 0.0]);
        $this->assertFalse(SegmentBooks::paysAnywhere($weav), 'no business pays');
        $this->assertFalse(SegmentBooks::isDiversifying($weav), 'seeded segments are not entries');

        $tern = $this->tern();
        $tern->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.9, 'air_cargo' => 0.1], 1.0, []) + [SegmentBooks::STATE_PAYS . Segments::MAIN => 0.0, SegmentBooks::STATE_PAYS . 'air_cargo' => 1.0]);
        $this->assertSame([Segments::MAIN], array_keys(SegmentBooks::dealSegments($tern)), 'the holds are bought with the planes');
        $this->assertFalse(SegmentBooks::paysAnywhere($tern));

        $venture = Segments::enteredKey('Steel');
        $entered = SegmentBooks::open([Segments::MAIN => 0.97, $venture => 0.03], 1.0, []) + [
            SegmentBooks::STATE_PAYS . Segments::MAIN => 0.0,
            SegmentBooks::STATE_PAYS . $venture => 1.0,
        ];
        $shrk->setEarningsMomentumZ($entered);
        $this->assertTrue(SegmentBooks::paysAnywhere($shrk));
        $this->assertTrue(SegmentBooks::isDiversifying($shrk), 'it runs a business it entered');
        $shrk->setEarningsMomentumZ($entered + [SegmentBooks::STATE_VENTURE_ABANDONED . $venture => 3.0]);
        $this->assertFalse(SegmentBooks::paysAnywhere($shrk), 'a venture given up on');
        $this->assertFalse(SegmentBooks::isDiversifying($shrk));
        $shrk->setEarningsMomentumZ([SegmentBooks::STATE_PENDING . $venture => 1.0e8, SegmentBooks::STATE_PAYS . Segments::MAIN => 0.0]);
        $this->assertTrue(SegmentBooks::isDiversifying($shrk), 'still building one');
    }

    /**
     * A business bought between reports takes its share of the sales as its revenue target at once, the others the rest
     * in the proportions they had; a company whose books just opened splits the sales between its plant.
     */
    public function testABusinessBoughtBetweenReportsTakesItsShareOfTheRevenue(): void
    {
        $venture = Segments::enteredKey('Steel');
        $momentum = SegmentBooks::open([Segments::MAIN => 0.6, 'ads_and_cloud' => 0.2, $venture => 0.2], 1.0, []) + [
            SegmentBooks::STATE_TURNOVER . Segments::MAIN => 1.0,
            SegmentBooks::STATE_TURNOVER . 'ads_and_cloud' => 1.0,
            SegmentBooks::STATE_TURNOVER . $venture => 2.0,
            SegmentBooks::STATE_TARGET . Segments::MAIN => 0.6,
            SegmentBooks::STATE_TARGET . 'ads_and_cloud' => 0.2,
        ];
        $targets = SegmentBooks::boughtTargets($momentum, $venture);
        // Sales: 0.6, 0.2 and 0.4 of 1.2.
        $this->assertEqualsWithDelta([
            SegmentBooks::STATE_TARGET . Segments::MAIN => 0.75 * (2.0 / 3.0),
            SegmentBooks::STATE_TARGET . 'ads_and_cloud' => 0.25 * (2.0 / 3.0),
            SegmentBooks::STATE_TARGET . $venture => 1.0 / 3.0,
        ], $targets, 1e-12);
        $this->assertEqualsWithDelta(1.0, array_sum($targets), 1e-12);

        $unfiled = array_filter($momentum, static fn(string $key): bool => !str_starts_with($key, SegmentBooks::STATE_TARGET), ARRAY_FILTER_USE_KEY);
        $this->assertEqualsWithDelta(0.5, SegmentBooks::boughtTargets($unfiled, Segments::MAIN)[SegmentBooks::STATE_TARGET . Segments::MAIN] ?? null, 1e-12);
        $this->assertEqualsWithDelta(1.0, array_sum(SegmentBooks::boughtTargets($unfiled, $venture)), 1e-12);
    }

    /**
     * A business bought into a new industry shows what it earns under its new owner as it is integrated, and once
     * integrated is judged; the goodwill paid for it leaves with it if it is sold, and every business's share of an
     * impairment or a sale of a slice of the company is its kept share.
     */
    public function testABoughtBusinessShowsWhatItEarnsAndTakesItsGoodwillWithIt(): void
    {
        $machinery = Segments::enteredKey('Farm & Heavy Construction Machinery');
        $momentum = SegmentBooks::open([Segments::MAIN => 0.7, 'industrial' => 0.26, $machinery => 0.04], 1.0, [])
            + [SegmentBooks::STATE_MARGIN . $machinery => 0.20]
            + SegmentBooks::buyIn($machinery, -0.5, 2.0e9);
        $this->assertFalse(SegmentBooks::isIntegrated($momentum, $machinery));

        $momentum = SegmentBooks::learn($momentum, SegmentEntry::INTEGRATION_YEARS / 2.0) + $momentum;
        $this->assertEqualsWithDelta(0.20 * exp(-0.25), $momentum[SegmentBooks::STATE_MARGIN . $machinery], 1e-12);
        $momentum = SegmentBooks::learn($momentum, SegmentEntry::INTEGRATION_YEARS) + $momentum;
        $this->assertEqualsWithDelta(0.20 * exp(-0.5), $momentum[SegmentBooks::STATE_MARGIN . $machinery], 1e-12, 'all of it, once');
        $this->assertTrue(SegmentBooks::isIntegrated($momentum, $machinery));
        $this->assertTrue(SegmentBooks::isIntegrated($momentum, 'industrial'), 'nothing to integrate');

        // 5bn of goodwill: 2bn paid for the machinery business, 3bn from the company's own past deals.
        $this->assertSame(2.0e9, SegmentBooks::goodwillSold($momentum, $machinery, 0.04, 5.0e9));
        $this->assertEqualsWithDelta(0.26 * 3.0e9, SegmentBooks::goodwillSold($momentum, 'industrial', 0.26, 5.0e9), 1.0);
        $this->assertEqualsWithDelta(0.1 * 5.0e9, SegmentBooks::goodwillSold($momentum, null, 0.1, 5.0e9), 1.0);
        $this->assertSame(1.5e9, SegmentBooks::goodwillSold($momentum, $machinery, 0.04, 1.5e9), 'no more than the company carries');
        $this->assertSame([SegmentBooks::STATE_GOODWILL . $machinery => 0.0], SegmentBooks::keepGoodwill($momentum, $machinery, 0.0));
        $this->assertSame([], SegmentBooks::keepGoodwill($momentum, 'industrial', 0.74));
        $this->assertEqualsWithDelta([SegmentBooks::STATE_GOODWILL . $machinery => 1.8e9], SegmentBooks::keepGoodwill($momentum, null, 0.9), 1.0);

        $entr = (new Stock())->setTicker('ENTR')->setIndustry('Auto Manufacturers');
        $entr->setEarningsMomentumZ($momentum);
        $this->assertNull(SegmentBooks::forSale($entr));
        $entr->setEarningsMomentumZ([SegmentBooks::STATE_FOR_SALE . $machinery => 4.0] + $momentum);
        $this->assertSame($machinery, SegmentBooks::forSale($entr));
        $entr->setEarningsMomentumZ([SegmentBooks::STATE_PLANT . $machinery => 0.0] + ($entr->getEarningsMomentumZ() ?? []));
        $this->assertNull(SegmentBooks::forSale($entr), 'sold');
    }

    /** One segment set to a share, the others scaled to the rest in the proportions they held. */
    public function testReshare(): void
    {
        $shares = SegmentBooks::reshare([Segments::MAIN => 0.6, 'a' => 0.3, 'b' => 0.1], 'b', 0.2);

        $this->assertEqualsWithDelta([Segments::MAIN => 0.6 * 0.8 / 0.9, 'a' => 0.3 * 0.8 / 0.9, 'b' => 0.2], $shares, 1e-12);
    }

    /**
     * A segment on its model's own path grows at its industry's rate plus the path's, and the company at its segments'
     * on its plant.
     */
    public function testASegmentOnItsOwnPathGrowsAlongIt(): void
    {
        $skyl = $this->skylarkAt(12.0, 0.6);
        $space = Sectors::strategyFor('Space Systems');
        $path = $space->resolveSegmentPath($skyl, 'satellite_internet');
        $this->assertNotNull($path);
        $internet = Sectors::strategyFor('Telecom Services')->getFadedSecularGrowthRate($skyl, 5.0) + $path['growth'];

        $this->assertEqualsWithDelta($internet, SegmentBooks::segmentGrowth($space, $skyl, 'satellite_internet', 5.0), 1e-12);
        $this->assertEqualsWithDelta(
            (0.6 * $space->getFadedSecularGrowthRate($skyl, 5.0)) + (0.4 * $internet),
            SegmentBooks::fadedSecularGrowth($space, $skyl, 5.0),
            1e-12,
        );
    }

    /**
     * The books open without a segment that sells nothing yet; it enters later with the plant its share of the sales
     * calls for at its own turnover, taken from the main segment, a market in which it holds its share of its
     * industry's, and the main segment's margin and turnover scaled as its lore sets them.
     */
    public function testASegmentEntersOnceItSells(): void
    {
        $opened = SegmentBooks::open([Segments::MAIN => 1.0, 'consumer_devices' => 0.0], 1.0, []);
        $this->assertSame([Segments::MAIN => 1.0], SegmentBooks::plant($opened));

        $momentum = $opened + [
            SegmentBooks::STATE_MARGIN . Segments::MAIN => 0.10,
            SegmentBooks::STATE_TURNOVER . Segments::MAIN => 0.8,
        ];
        $this->assertSame([], SegmentBooks::enter('ERNE', $momentum, 'consumer_devices', 0.0, 1.2, 1.1, 0.05, 0.001));

        $entered = SegmentBooks::enter('ERNE', $momentum, 'consumer_devices', 0.03, 1.2, 1.1, 0.05, 0.001) + $momentum;
        $plant = SegmentBooks::plant($entered) ?? [];
        // Devices turn their capital over 1.5 times as fast as the networks: 3% of the sales on 2% of the plant.
        $this->assertEqualsWithDelta(0.02, $plant['consumer_devices'], 1e-12);
        $this->assertEqualsWithDelta(0.98, $plant[Segments::MAIN], 1e-12);
        $this->assertEqualsWithDelta(0.02 * 1.2 / 1.1 * 0.05 / 0.001, $entered[SegmentBooks::STATE_MARKET . 'consumer_devices'], 1e-12);
        $this->assertEqualsWithDelta(0.04, $entered[SegmentBooks::STATE_MARGIN . 'consumer_devices'], 1e-12);
        $this->assertEqualsWithDelta(1.2, $entered[SegmentBooks::STATE_TURNOVER . 'consumer_devices'], 1e-12);
        $this->assertEqualsWithDelta(0.03, SegmentBooks::companyEconomics($entered)['sales']['consumer_devices'] ?? null, 0.001);
    }

    /** The company grows at its segments' industries' rates on its plant; before the books open, at its model's. */
    public function testTheCompanyGrowsAtItsSegmentsRatesOnItsPlant(): void
    {
        $stock = $this->tern();
        $airline = Sectors::strategyFor('Airlines');
        $freight = Sectors::strategyFor('Integrated Freight & Logistics');

        $this->assertSame($airline->getFadedSecularGrowthRate($stock, 5.0), SegmentBooks::fadedSecularGrowth($airline, $stock, 5.0));

        $stock->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.75, 'air_cargo' => 0.25], 1.0, []));
        $this->assertEqualsWithDelta(
            (0.75 * $airline->getFadedSecularGrowthRate($stock, 5.0)) + (0.25 * $freight->getFadedSecularGrowthRate($stock, 5.0)),
            SegmentBooks::fadedSecularGrowth($airline, $stock, 5.0),
            1e-12,
        );
    }

    /**
     * A segment's market grows at its industry's rate whichever company it belongs to: the rate must not read the
     * company's own model parameters, whose keys mean other things in other models.
     */
    public function testASegmentsIndustryRateIsTheIndustrysNotTheCompanys(): void
    {
        foreach (Segments::tickers() as $ticker) {
            foreach (Segments::forTicker($ticker)['segments'] ?? [] as $key => $segment) {
                $strategy = Sectors::strategyFor($segment['industry']);
                $this->assertSame(
                    $strategy->getSecularGrowthRate((new Stock())->setTicker('NONE')->setIndustry($segment['industry'])),
                    $strategy->getSecularGrowthRate((new Stock())->setTicker($ticker)->setIndustry($segment['industry'])),
                    "{$ticker} {$key}",
                );
            }
        }
    }

    /** Every company in several markets keeps plant by segment, one whose model plans a segment's path included. */
    public function testWhoKeepsBooks(): void
    {
        $this->assertTrue(SegmentBooks::keepsBooks($this->tern()));
        $this->assertTrue(SegmentBooks::keepsBooks((new Stock())->setTicker('SKYL')->setIndustry('Space Systems')));
        $this->assertFalse(SegmentBooks::keepsBooks((new Stock())->setTicker('LOON')->setIndustry('Telecom Services')));
        $this->assertSame('TERN', SegmentBooks::ownerOf(SegmentBooks::recordKey('TERN', 'air_cargo')));
        $this->assertSame('TERN', SegmentBooks::recordKey('TERN', Segments::MAIN));
    }

    /**
     * Each segment gets its own margin and turnover, struck so the company's own stay what they were and each segment
     * keeps its share of the revenue: the ads arm earns six times retail's margin on half its turnover, so it holds
     * twice the plant per dollar of sales, and its market, measured in plant, with it.
     */
    public function testSegmentEconomicsOpenAtTheCompanysOwn(): void
    {
        $weav = (new Stock())->setTicker('WEAV')->setIndustry('Internet Retail');
        $momentum = SegmentBooks::open([Segments::MAIN => 0.8, 'ads_and_cloud' => 0.2], 1.0, []);
        $shareBefore = SegmentBooks::addressableShares($weav->setEarningsMomentumZ($momentum), 0.3, 0.0);

        $weav->setEarningsMomentumZ(SegmentBooks::openEconomics('WEAV', $momentum, 2.0, 0.06) + $momentum);
        $economics = SegmentBooks::companyEconomics($weav->getEarningsMomentumZ() ?? []);

        $this->assertNotNull($economics);
        $this->assertEqualsWithDelta(2.0, $economics['turnover'], 1e-12);
        $this->assertEqualsWithDelta(0.06, $economics['margin'], 1e-12);
        $this->assertEqualsWithDelta(0.2, $economics['sales']['ads_and_cloud'], 1e-12, 'its share of the revenue');
        $this->assertEqualsWithDelta(0.4 / 1.2, SegmentBooks::plant($weav->getEarningsMomentumZ() ?? [])['ads_and_cloud'] ?? 0.0, 1e-12);
        $this->assertEqualsWithDelta(6.0, $economics['margins']['ads_and_cloud'] / $economics['margins'][Segments::MAIN], 1e-12);
        $this->assertEqualsWithDelta(0.5, $economics['turnovers']['ads_and_cloud'] / $economics['turnovers'][Segments::MAIN], 1e-12);
        $this->assertEqualsWithDelta($shareBefore['ads_and_cloud'], SegmentBooks::addressableShares($weav, 0.3, 0.0)['ads_and_cloud'], 1e-12, 'nor its share of its market');

        $this->assertSame(0.0, SegmentBooks::avoidableCostRatio($this->tern(), 'air_cargo', 0.7), 'a by-product sells at any price');
        $this->assertSame(0.7, SegmentBooks::avoidableCostRatio($weav, 'ads_and_cloud', 0.7));
    }

    /**
     * What a takeover buys joins its segment's turnover and margin; the company's own are their blend again. A sold
     * segment's market is no longer the company's.
     */
    public function testATakeoverBlendsIntoItsSegmentsEconomics(): void
    {
        $momentum = SegmentBooks::open([Segments::MAIN => 0.8, 'ads_and_cloud' => 0.2], 1.0, []);
        $momentum = SegmentBooks::openEconomics('WEAV', $momentum, 2.0, 0.06) + $momentum;
        $before = SegmentBooks::companyEconomics($momentum);
        $this->assertNotNull($before);
        $adsCapital = (SegmentBooks::plant($momentum)['ads_and_cloud'] ?? 0.0) * 1_000.0;

        $after = SegmentBooks::acquireIntoBooks($momentum, 'ads_and_cloud', 1_000.0, 100.0, 300.0, 30.0) + $momentum;
        $economics = SegmentBooks::companyEconomics($after);
        $this->assertNotNull($economics);

        $adsRevenue = $adsCapital * $before['turnovers']['ads_and_cloud'];
        $this->assertEqualsWithDelta(($adsRevenue + 300.0) / ($adsCapital + 100.0), $economics['turnovers']['ads_and_cloud'], 1e-9);
        $this->assertEqualsWithDelta((($adsRevenue * $before['margins']['ads_and_cloud']) + 30.0) / ($adsRevenue + 300.0), $economics['margins']['ads_and_cloud'], 1e-9);
        $this->assertSame($before['margins'][Segments::MAIN], $economics['margins'][Segments::MAIN]);

        $stock = (new Stock())->setTicker('WEAV')->setIndustry('Internet Retail');
        $stock->setEarningsMomentumZ([SegmentBooks::STATE_PLANT . 'ads_and_cloud' => 0.0, SegmentBooks::STATE_PLANT . Segments::MAIN => 1.0] + $momentum);
        $this->assertEqualsWithDelta((float) $momentum[SegmentBooks::STATE_MARKET . Segments::MAIN], SegmentBooks::firmMarketGrowth($stock, 0.0), 1e-12, 'only the market it still sells into');
    }

    /**
     * A segment sold whole gives up its share of the sales and of the income it earns at its own margin; the weakest is
     * the one whose plant earns least, not the one that turns it slowest.
     */
    public function testASaleGivesUpWhatTheSegmentEarns(): void
    {
        // The consumer arm sells least per unit of plant, but at four times the margin it earns more on it.
        $momentum = SegmentBooks::open([Segments::MAIN => 0.5, 'industrial' => 0.3, 'consumer' => 0.2], 1.0, []) + [
            SegmentBooks::STATE_TARGET . Segments::MAIN => 0.55,
            SegmentBooks::STATE_TARGET . 'industrial' => 0.3,
            SegmentBooks::STATE_TARGET . 'consumer' => 0.15,
            SegmentBooks::STATE_MARGIN . Segments::MAIN => 0.10,
            SegmentBooks::STATE_MARGIN . 'industrial' => 0.05,
            SegmentBooks::STATE_MARGIN . 'consumer' => 0.20,
        ];

        $shares = SegmentBooks::earningShares($momentum, 'industrial');
        $this->assertSame(0.3, $shares['revenue']);
        $this->assertEqualsWithDelta(0.015 / (0.055 + 0.015 + 0.03), $shares['income'], 1e-12);
        $triv = (new Stock())->setTicker('TRIV')->setIndustry('Conglomerates');
        $this->assertSame('industrial', SegmentBooks::weakestSegment($triv->setEarningsMomentumZ($momentum)));
    }

    /**
     * A company in one market that starts building into another keeps books from then on: the new segment is known by
     * its industry while its plant is still under construction, holds no plant share until some is in service, carries
     * the economics it will earn once learned and how far short of them it starts, and its one revenue line is its own.
     */
    public function testACompanyEnteringAnIndustryKeepsItByName(): void
    {
        $segment = Segments::enteredKey('Computer Hardware');
        $loon = (new Stock())->setTicker('LOON')->setIndustry('Telecom Services');
        $this->assertFalse(SegmentBooks::keepsBooks($loon));
        $loon->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 1.0], 1.0, [])
            + SegmentBooks::startVenture($segment, 300.0, 0.05, 1.0, 1.0, 0.1, 0.0, ['margin' => 0.08, 'turnover' => 1.5, 'learning' => 2.5, 'outcome' => 0.0, 'target' => 0.0, 'start' => 1.0]));

        $momentum = $loon->getEarningsMomentumZ() ?? [];
        $this->assertTrue(SegmentBooks::keepsBooks($loon));
        $this->assertSame([Segments::MAIN => 'Telecom Services', $segment => 'Computer Hardware'], SegmentBooks::industries($loon));
        $this->assertSame([Segments::MAIN => 1.0], SegmentBooks::plant($momentum), 'no plant until some is in service');
        $this->assertSame([$segment => 300.0], SegmentBooks::pending($momentum));
        $this->assertSame(0.08, $momentum[SegmentBooks::STATE_MARGIN . $segment] ?? null);
        $this->assertSame(2.5, $momentum[SegmentBooks::STATE_LEARNING . $segment] ?? null);
        $this->assertEqualsWithDelta(0.05, (float) ($momentum[SegmentBooks::STATE_MARKET . $segment] ?? 0.0), 1e-12, 'the company\'s share of its new market');
        $this->assertSame('Computer hardware', SegmentBooks::label($loon, $segment));
        $this->assertSame($segment, Segments::segmentOf('LOON', $segment));
        $this->assertSame('Computer hardware', \App\Data\Company\RevenueStreams::label($segment));

        // A line it has no model for is never a by-product of the plant it does have.
        $tern = $this->tern()->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.9, 'air_cargo' => 0.1], 1.0, []) + [SegmentBooks::STATE_PENDING . $segment => 300.0]);
        $this->assertSame(['air_cargo'], SegmentBooks::jointSegments($tern));
    }

    /**
     * The model earned the whole quarter on lines it knows; a segment entered in play takes its share of that revenue
     * as a line of its own and the model's lines give it up in proportion, so the quarter adds up to what was earned. A
     * seeded segment's share is its lines' own, and nothing is carved for it.
     */
    public function testAnEnteredSegmentIsCarvedFromTheQuarterOnce(): void
    {
        $segment = Segments::enteredKey('Computer Hardware');
        $momentum = [SegmentBooks::STATE_TARGET . Segments::MAIN => 0.9, SegmentBooks::STATE_TARGET . $segment => 0.1];

        $lines = SegmentBooks::carveEnteredLines($momentum, ['core_business' => 900.0, 'services' => 100.0]);

        $this->assertEqualsWithDelta(['core_business' => 810.0, 'services' => 90.0, $segment => 100.0], $lines, 1e-9);
        $this->assertEqualsWithDelta(1_000.0, array_sum($lines), 1e-9);
        $this->assertEqualsWithDelta([Segments::MAIN => 0.9, $segment => 0.1], SegmentOutlook::shares('LOON', $lines, true), 1e-12);
        $this->assertSame([], SegmentOutlook::shares('LOON', $lines), 'no books, no segments');
        $seeded = [SegmentBooks::STATE_TARGET . Segments::MAIN => 0.9, SegmentBooks::STATE_TARGET . 'air_cargo' => 0.1];
        $this->assertSame(['passenger' => 900.0, 'air_cargo' => 100.0], SegmentBooks::carveEnteredLines($seeded, ['passenger' => 900.0, 'air_cargo' => 100.0]));
    }

    /**
     * A venture's plant comes on line as the company's construction does, a quarter of it when a quarter of the queue
     * is placed in service, and joins its plant share; what is left below what the queue empties whole is placed with
     * it.
     */
    public function testAVenturesPlantComesOnLineWithTheQueue(): void
    {
        $segment = Segments::enteredKey('Computer Hardware');
        $momentum = SegmentBooks::open([Segments::MAIN => 1.0], 1.0, []) + [SegmentBooks::STATE_PENDING . $segment => 40_000.0];

        // The company's capital with the quarter's 10,000 placed in it.
        $state = SegmentBooks::completePending($momentum, 0.25, 110_000.0);

        $this->assertEqualsWithDelta(30_000.0, $state[SegmentBooks::STATE_PENDING . $segment] ?? null, 1e-9);
        $plant = SegmentBooks::plant($state + $momentum) ?? [];
        $this->assertEqualsWithDelta(10_000.0 / 110_000.0, $plant[$segment] ?? null, 1e-12);
        $this->assertEqualsWithDelta(1.0, array_sum($plant), 1e-12);

        $tail = SegmentBooks::completePending([SegmentBooks::STATE_PENDING . $segment => 1_500.0] + $momentum, 0.5, 1_000.0);
        $this->assertSame(0.0, $tail[SegmentBooks::STATE_PENDING . $segment] ?? null, 'the tail is placed whole');
        $this->assertSame([], SegmentBooks::completePending(SegmentBooks::open([Segments::MAIN => 1.0], 1.0, []), 0.5, 1_000.0));
    }

    /**
     * As a venture learns its trade it shows what it will earn: the margin it books moves from what was expected toward
     * what it turns out to earn, all the way once it has learned.
     */
    public function testAVentureShowsWhatItEarnsAsItLearns(): void
    {
        $segment = Segments::enteredKey('Computer Hardware');
        $momentum = [
            SegmentBooks::STATE_LEARNING . $segment => 3.0,
            SegmentBooks::STATE_LEARNING_START . $segment => 3.0,
            SegmentBooks::STATE_VENTURE_OUTCOME . $segment => -0.5,
            SegmentBooks::STATE_MARGIN . $segment => 0.10,
        ];

        $halfway = SegmentBooks::learn($momentum, SegmentEntry::LEARNING_YEARS * log(2.0)) + $momentum;
        $this->assertEqualsWithDelta(0.10 * exp(-0.25), $halfway[SegmentBooks::STATE_MARGIN . $segment], 1e-12, 'half learned, half shown');
        $learned = SegmentBooks::learn($halfway, 1_000.0) + $halfway;
        $this->assertEqualsWithDelta(0.10 * exp(-0.5), $learned[SegmentBooks::STATE_MARGIN . $segment], 1e-9);
    }

    /**
     * A venture follows a path of its own toward the share of its new market it means to reach; one given up on keeps a
     * claim to almost none, so its plant withers; any other segment follows its model's path or none.
     */
    public function testAVentureFollowsAPathOfItsOwn(): void
    {
        $segment = Segments::enteredKey('Computer Hardware');
        $momentum = [SegmentBooks::STATE_VENTURE_TARGET . $segment => 1.0, SegmentBooks::STATE_VENTURE_START . $segment => 2.0];

        $this->assertEqualsWithDelta(['level' => SegmentEntry::pathLevel(1.0, 5.0), 'growth' => SegmentEntry::pathGrowth(1.0, 5.0)], SegmentBooks::venturePath($momentum, $segment, 7.0), 1e-12);
        $abandoned = [SegmentBooks::STATE_VENTURE_ABANDONED . $segment => 6.0] + $momentum;
        $this->assertTrue(SegmentBooks::isAbandoned($abandoned, $segment));
        $this->assertEqualsWithDelta(log(SegmentEntry::ABANDONED_TREND_SHARE), SegmentBooks::venturePath($abandoned, $segment, 7.0)['level'] ?? null, 1e-12);
        $this->assertNull(SegmentBooks::venturePath($momentum, 'air_cargo', 7.0));
        $loon = (new Stock())->setTicker('LOON')->setIndustry('Telecom Services')->setEarningsMomentumZ([SegmentBooks::STATE_PENDING . $segment => 1.0] + $momentum);
        $this->assertSame(SegmentBooks::venturePath($momentum, $segment, 7.0), SegmentBooks::segmentPath(Sectors::strategyFor('Telecom Services'), $loon, $segment, 7.0));
        $this->assertEqualsWithDelta(SegmentEntry::pathGrowth(1.0, 5.0), SegmentBooks::segmentGrowth(Sectors::strategyFor('Telecom Services'), $loon, $segment, 7.0) - Sectors::strategyFor('Computer Hardware')->getFadedSecularGrowthRate($loon, 7.0), 1e-12);
    }

    /**
     * A sale of part of the company takes that share of its construction, a venture's plant still being built with it.
     */
    public function testASaleTakesItsShareOfAVenturesConstruction(): void
    {
        $segment = Segments::enteredKey('Computer Hardware');
        $momentum = [SegmentBooks::STATE_PENDING . $segment => 40_000.0, SegmentBooks::STATE_PLANT . Segments::MAIN => 1.0];

        $this->assertEqualsWithDelta([$segment => 30_000.0], SegmentBooks::pending(SegmentBooks::scalePending($momentum, 0.75)), 1e-9);
        $this->assertSame(1.0, SegmentBooks::scalePending($momentum, 0.75)[SegmentBooks::STATE_PLANT . Segments::MAIN]);
    }

    /**
     * A venture learns its trade: its shortfall from the margin it will earn fades by e over the learning time, and
     * while it lasts it costs the company the venture's sales times that margin times the shortfall.
     */
    public function testAVentureLearnsItsTrade(): void
    {
        $segment = Segments::enteredKey('Computer Hardware');
        $momentum = [SegmentBooks::STATE_LEARNING . $segment => 2.0, SegmentBooks::STATE_MARGIN . $segment => 0.10, SegmentBooks::STATE_MARGIN . Segments::MAIN => 0.20];

        $this->assertEqualsWithDelta(2.0 / M_E, SegmentBooks::learn($momentum, SegmentEntry::LEARNING_YEARS)[SegmentBooks::STATE_LEARNING . $segment] ?? null, 1e-12);
        $this->assertEqualsWithDelta(0.05 * 0.10 * 2.0, SegmentBooks::learningDrag($momentum, [Segments::MAIN => 0.95, $segment => 0.05]), 1e-12);
        $this->assertSame(0.0, SegmentBooks::learningDrag([SegmentBooks::STATE_MARGIN . Segments::MAIN => 0.2], [Segments::MAIN => 1.0]));
    }

    private function skylarkAt(float $years, float $mainPlant): Stock
    {
        $skyl = (new Stock())->setTicker('SKYL')->setIndustry('Space Systems');
        $skyl->setEarningsMomentumZ([
            \App\Service\Model\Sector\SpaceSystemsBusinessModel::STATE_YEARS_LISTED => $years,
        ] + SegmentBooks::open([Segments::MAIN => $mainPlant, 'satellite_internet' => 1.0 - $mainPlant], 1.0, []));

        return $skyl;
    }

    private function tern(): Stock
    {
        return (new Stock())->setTicker('TERN')->setIndustry('Airlines');
    }
}
