<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate\Industry;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\Industry\IndustryOutlook;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Corporate\Industry\InMemoryIndustryShareStore;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\TimeSeries;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class IndustryOutlookTest extends TestCase
{
    private const DRIFT = 0.20;
    private const HALF_LIFE = 2.0;

    /**
     * An outlook state with a volatile industry of negligible size beside a settled one that holds nearly all the
     * spending, so the zero-sum rescaling barely touches the volatile one.
     *
     * @return array{salt: int, quarter: int, industries: array<string, array{x: float, m: float, v: float, f: float, w: float, prior_belief: float, revised_tick: int}>}
     */
    private static function state(int $salt, float $volatileWeight = 1e-6, float $fringe = 0.0): array
    {
        $variance = IndustryOutlook::steadyVariance(self::DRIFT, self::HALF_LIFE);

        return ['salt' => $salt, 'quarter' => 0, 'industries' => [
            'Volatile' => ['x' => 0.0, 'm' => -$variance / 2.0, 'v' => $variance, 'f' => $fringe, 'w' => $volatileWeight, 'prior_belief' => 0.0, 'revised_tick' => -1],
            'Settled' => ['x' => 0.0, 'm' => 0.0, 'v' => 0.0, 'f' => 0.0, 'w' => 1.0, 'prior_belief' => 0.0, 'revised_tick' => -1],
        ]];
    }

    /** @return \Closure(string): array{float, float} */
    private static function parameters(): \Closure
    {
        return static fn(string $industry): array => [$industry === 'Volatile' ? self::DRIFT : 0.0, self::HALF_LIFE];
    }

    /**
     * @param array{salt: int, quarter: int, industries: array<string, array{x: float, m: float, v: float, f: float, w: float, prior_belief: float, revised_tick: int}>} $state
     * @return array{salt: int, quarter: int, industries: array<string, array{x: float, m: float, v: float, f: float, w: float, prior_belief: float, revised_tick: int}>}
     */
    private static function stepQuarters(array $state, int $quarters, ?MacroStateDTO $macro = null): array
    {
        for ($quarter = 1; $quarter <= $quarters; ++$quarter) {
            $state = IndustryOutlook::step($state, $quarter, $macro ?? new MacroStateDTO(), $quarter * 90, self::parameters());
        }

        return $state;
    }

    public function testTheSettledFilterHoldsItsVarianceAndTakesInTheGainItsHalfLifeSets(): void
    {
        $stepVariance = (self::DRIFT ** 2) * FinancialConstants::QUARTER_YEARS;
        $gain = IndustryOutlook::steadyGain(self::HALF_LIFE);
        $readingVariance = TimeSeries::localLevelReadingVariance($stepVariance, $gain);
        $settled = TimeSeries::localLevelSteadyVariance($stepVariance, $gain);

        [$mean, $variance] = TimeSeries::localLevelStep(0.0, $settled, 1.0, $stepVariance, $readingVariance);

        $this->assertEqualsWithDelta($settled, $variance, 1e-12, 'a settled belief stays settled');
        $this->assertEqualsWithDelta($gain, $mean, 1e-12, 'it takes in K of a unit surprise');
        $this->assertEqualsWithDelta(0.5, (1.0 - $gain) ** (self::HALF_LIFE / FinancialConstants::QUARTER_YEARS), 1e-12, 'and halves a gap over the half-life');
        $this->assertSame([0.3, 0.0], TimeSeries::localLevelStep(0.3, 0.0, 1.0, 0.0, 0.1), 'no walk and no doubt: nothing to learn');
    }

    public function testWithNoUncertaintyAnywhereNothingMoves(): void
    {
        $state = self::state(7);
        $opening = $state['industries'];
        for ($quarter = 1; $quarter <= 40; ++$quarter) {
            $state = IndustryOutlook::step($state, $quarter, new MacroStateDTO(), $quarter * 90, static fn(string $industry): array => [0.0, self::HALF_LIFE]);
        }

        $this->assertSame($opening['Settled'], $state['industries']['Settled']);
        $this->assertSame($opening['Volatile']['x'], $state['industries']['Volatile']['x']);
        $this->assertSame(40, $state['quarter']);
    }

    /**
     * The truth is compensated so its level is a martingale: across many runs a volatile industry's level averages
     * one after ten years however far each run has wandered, so uncertainty hands no industry spending on average.
     */
    public function testTheTrueLevelIsMeanOne(): void
    {
        $levels = [];
        for ($salt = 1; $salt <= 400; ++$salt) {
            $levels[] = exp(self::stepQuarters(self::state($salt), 40)['industries']['Volatile']['x']);
        }

        $mean = array_sum($levels) / count($levels);
        $this->assertEqualsWithDelta(1.0, $mean, 0.12);
        $this->assertGreaterThan(0.25, sqrt(array_sum(array_map(static fn(float $level): float => ($level - $mean) ** 2, $levels)) / count($levels)), 'and it does wander');
    }

    public function testSpendingOnlyMovesBetweenIndustries(): void
    {
        $state = self::stepQuarters(self::state(11, 0.5), 40);

        $spending = 0.0;
        foreach ($state['industries'] as $record) {
            $spending += $record['w'] * exp($record['x']);
        }
        $this->assertEqualsWithDelta(1.5, $spending, 1e-9, 'the opening shares, at their new levels, still add up');
        $this->assertNotEqualsWithDelta(0.0, $state['industries']['Settled']['x'], 1e-6, 'so a settled industry gives up what a volatile one takes');
    }

    /**
     * The belief is calibrated: across runs its error against the truth has the variance the filter claims, so the
     * public is neither overconfident nor slow on average.
     */
    public function testTheBeliefsErrorHasTheVarianceTheFilterClaims(): void
    {
        $squaredErrors = [];
        $claimed = 0.0;
        for ($salt = 1; $salt <= 400; ++$salt) {
            $record = self::stepQuarters(self::state($salt), 40)['industries']['Volatile'];
            $squaredErrors[] = ($record['x'] - $record['m']) ** 2;
            $claimed = $record['v'];
        }

        $this->assertEqualsWithDelta($claimed, array_sum($squaredErrors) / count($squaredErrors), 0.25 * $claimed);
    }

    public function testTheSameSaltDrawsTheSameOutlookAndAnotherDoesNot(): void
    {
        $this->assertSame(self::stepQuarters(self::state(3), 12), self::stepQuarters(self::state(3), 12));
        $this->assertNotSame(self::stepQuarters(self::state(3), 12)['industries']['Volatile']['x'], self::stepQuarters(self::state(4), 12)['industries']['Volatile']['x']);
    }

    /**
     * The quarter's turn reports every industry it revised, with the level the public now expects and how far that
     * moved; a tick inside the quarter reports nothing, and an outlook never yet revised has moved by nothing.
     */
    public function testTheQuartersTurnReportsEachRevision(): void
    {
        $store = new InMemoryIndustryShareStore();
        $outlook = new IndustryOutlook($store, new CorporateMetrics());
        $variance = IndustryOutlook::steadyVariance(0.06, 1.0);
        $opening = ['x' => 0.0, 'm' => -$variance / 2.0, 'v' => $variance, 'f' => 0.0, 'w' => 1.0, 'prior_belief' => 0.0, 'revised_tick' => -1];
        $store->writeOutlooks(['salt' => 9, 'quarter' => 0, 'industries' => ['Steel' => $opening]]);
        $this->assertSame(0.0, IndustryOutlook::revision($opening));

        $this->assertSame([], $outlook->advance([], new MacroStateDTO(), 62, 252), 'still inside the first quarter');
        $revisions = $outlook->advance([], new MacroStateDTO(), 63, 252);

        $record = $store->readOutlook('Steel');
        $this->assertNotNull($record);
        $this->assertSame(['Steel'], array_keys($revisions));
        $this->assertEqualsWithDelta(exp(IndustryOutlook::belief($record)), $revisions['Steel']['level'], 1e-12);
        $this->assertEqualsWithDelta(($revisions['Steel']['level'] / exp($record['prior_belief'])) - 1.0, $revisions['Steel']['revision'], 1e-12);
        $this->assertNotEqualsWithDelta(0.0, $revisions['Steel']['revision'], 1e-9);
        $this->assertSame([], $outlook->advance([], new MacroStateDTO(), 64, 252));
    }

    /**
     * The unlisted rest of the industry builds toward the public's belief at the accelerator's speed and never sells
     * plant back: far above the belief it only stops building, so its plant falls behind trend at trend growth.
     */
    public function testTheFringeBuildsTowardTheBeliefAndNeverSellsPlantBack(): void
    {
        $macro = new MacroStateDTO(inflationEma: 0.02);
        $trendGrowth = MacroConstants::TREND_REAL_GROWTH + 0.02;
        $after = IndustryOutlook::step(self::state(5, fringe: 1.0), 1, $macro, 90, self::parameters())['industries']['Volatile']['f'];

        $this->assertEqualsWithDelta(1.0 - ($trendGrowth * FinancialConstants::QUARTER_YEARS), $after, 1e-12);

        $this->assertGreaterThan(-0.3, IndustryOutlook::step(self::state(5, fringe: -0.3), 1, $macro, 90, self::parameters())['industries']['Volatile']['f'], 'below the belief it builds');
    }

    /**
     * The ledger sells plant into true demand and plans it on the belief. A firm on its anchor in an industry whose
     * true demand fell 20% while the public still expects the opening level is 25% oversupplied against the truth;
     * once the public revises up by 10%, the firm's plan calls for 10% more plant while the believed balance still
     * reads as balanced.
     */
    public function testTheLedgerSellsIntoTheTruthAndPlansOnTheBelief(): void
    {
        $store = new InMemoryIndustryShareStore();
        $ledger = new IndustryShareLedger($store);
        $firm = new Stock();
        $firm->setTicker('OUTL');
        $firm->setIndustry('Steel');
        $firm->setTotalRevenue('1000');
        $macro = new MacroStateDTO(potentialGdpIndex: 1.0, gdpDeflator: 1.0);
        $outlook = static fn(float $truth, float $belief): array => ['salt' => 1, 'quarter' => 0, 'industries' => ['Steel' => ['x' => $truth, 'm' => $belief, 'v' => 0.0, 'f' => 0.0, 'w' => 1.0, 'prior_belief' => 0.0, 'revised_tick' => -1]]];

        $ledger->resolveIndustrySupply($firm, 1_000.0, 0.4, 1.0, 0.0, 0.0, 1, 252);
        $store->writeOutlooks($outlook(log(0.8), 0.0));
        $supply = $ledger->resolveIndustrySupply($firm, 1_000.0, 0.4, 1.0, 0.0, 0.0, 2, 252);
        $this->assertNotNull($supply);
        $this->assertEqualsWithDelta(1.25, $supply['roster'] + $supply['fringe'], 1e-9, 'plant built for the expected market, sold into a smaller one');
        $this->assertEqualsWithDelta(0.4 * 1.25, $supply['roster'], 1e-9);

        $store->writeOutlooks($outlook(0.1, 0.1));
        $this->assertEqualsWithDelta(0.1, $ledger->resolveTrendCapacityGap($firm, $macro, 0.0), 1e-9, 'the plan calls for the revised market');
        $this->assertEqualsWithDelta(1.0 - (0.4 * (1.0 - exp(-0.1))), $ledger->resolveIndustryCapacityRatio($firm, 1_000.0, 0.4, 1.0, 0.0, 0.0, 3, 252), 1e-9, 'the believed balance moves only by the plant not yet built');
    }
}
