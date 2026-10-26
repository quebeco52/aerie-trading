<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\Data\Company\Segments;
use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\Segment\SegmentBooks;
use App\Service\Corporate\Segment\SegmentOutlook;
use App\Service\Event\ShockEvent;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\ResponseCurves;
use App\Service\Math\TimeSeries;
use App\Service\Model\Sector\SpaceSystemsBusinessModel as Space;
use App\Service\Model\StreamContext;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class SpaceSystemsBusinessModelTest extends TestCase
{
    private const EXPECTED_REVENUE = 100_000_000.0;
    private const VARIABLE_COST_RATIO = 0.50;

    private Space $model;

    protected function setUp(): void
    {
        $this->model = new Space();
    }

    /**
     * One quarter with every normal at zero; checkProbability answers the launch-failure onset, false unless scripted.
     *
     * @param array<string, float> $momentum
     * @param list<bool>           $probabilities
     */
    private function runQuarter(array $momentum = [], array $probabilities = []): ActualFinancialsDTO
    {
        return $this->model->computeActualFinancials(
            self::stock($momentum),
            self::EXPECTED_REVENUE,
            self::VARIABLE_COST_RATIO,
            10_000_000.0,
            0.0,
            new MacroStateDTO(),
            new ScriptedDraws(generatePersistentZ: fn(float $prev): float => 0.0, checkProbability: $probabilities),
        );
    }

    /** @param array<string, float> $momentum */
    private static function stock(array $momentum = [], string $ticker = 'SPACE_TEST'): Stock
    {
        $stock = (new Stock())->setTicker($ticker)->setBeta('1.00');
        $stock->setEarningsMomentumZ($momentum);

        return $stock;
    }

    /**
     * The three lines' expected real levels, worked out here from the published curves.
     *
     * @return array{launch: float, government: float, broadband: float}
     */
    private static function levels(float $years): array
    {
        return [
            'launch'     => Space::LAUNCH_SERVICES_WEIGHT * exp(TimeSeries::fadedExcessIntegral(Space::LAUNCH_EXCESS_GROWTH, 0.0, $years, Space::LAUNCH_EXCESS_HALF_LIFE_YEARS)),
            'government' => Space::GOVERNMENT_MISSIONS_WEIGHT,
            'broadband'  => Space::BROADBAND_MATURE_SIZE * ResponseCurves::bassAdoption($years - Space::BROADBAND_SERVICE_START_YEARS, Space::BROADBAND_INNOVATION, Space::BROADBAND_IMITATION),
        ];
    }

    public function testAtTheListingTheFirmIsRocketsAndMissions(): void
    {
        $result = $this->runQuarter();

        $this->assertEqualsWithDelta(70_000_000.0, $result->streamRevenue['launch_services'], 1.0);
        $this->assertEqualsWithDelta(30_000_000.0, $result->streamRevenue['government_missions'], 1.0);
        $this->assertSame(0.0, $result->streamRevenue['satellite_broadband']);
        // Nothing learned yet: every line costs the average.
        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE * self::VARIABLE_COST_RATIO, $result->actualVariableCosts, 1.0);
        $this->assertEqualsWithDelta(FinancialConstants::QUARTER_YEARS, $result->streamZ[Space::STATE_YEARS_LISTED], 1e-12);
        $this->assertEqualsWithDelta(Space::INITIAL_LAUNCH_EXPERIENCE_YEARS + FinancialConstants::QUARTER_YEARS, $result->streamZ[Space::STATE_LAUNCH_EXPERIENCE], 1e-12);
    }

    public function testSubscribersSignUpAlongTheCurveAndTakeOverTheMix(): void
    {
        $years = 12.0;
        $levels = self::levels($years);
        // The true market as estimated, so the line bills its planned share.
        $result = $this->runQuarter([Space::STATE_YEARS_LISTED => $years, Space::STATE_LAUNCH_EXPERIENCE => Space::INITIAL_LAUNCH_EXPERIENCE_YEARS, Space::STATE_MARKET_SIZE => Space::BROADBAND_MATURE_SIZE]);

        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE * $levels['broadband'] / array_sum($levels), $result->streamRevenue['satellite_broadband'], 1.0);
        $this->assertGreaterThan($result->streamRevenue['launch_services'], $result->streamRevenue['satellite_broadband']);
        $this->assertEqualsWithDelta(ResponseCurves::bassAdoption($years - Space::BROADBAND_SERVICE_START_YEARS, Space::BROADBAND_INNOVATION, Space::BROADBAND_IMITATION), $result->kpis['broadband_penetration'], 1e-12);
        // A subscriber costs less to serve than a launch costs to fly.
        $this->assertLessThan(self::EXPECTED_REVENUE * self::VARIABLE_COST_RATIO, $result->actualVariableCosts);
    }

    /**
     * Satellite internet is a telecom market: with telecom's outlook ahead of the blend the line takes more of the
     * plan, and the plan still splits to the whole expected revenue.
     */
    public function testTheInternetLineLeansByTelecomsOutlook(): void
    {
        $years = 12.0;
        $levels = self::levels($years);
        $momentum = [Space::STATE_YEARS_LISTED => $years, Space::STATE_LAUNCH_EXPERIENCE => Space::INITIAL_LAUNCH_EXPERIENCE_YEARS, Space::STATE_MARKET_SIZE => Space::BROADBAND_MATURE_SIZE];
        $leaning = $this->runQuarter($momentum + [SegmentOutlook::STATE_TILT . 'satellite_broadband' => 1.2, SegmentOutlook::STATE_TILT . Segments::MAIN => 0.9]);

        $weighted = ($levels['broadband'] * 1.2) + (($levels['launch'] + $levels['government']) * 0.9);
        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE * $levels['broadband'] * 1.2 / $weighted, $leaning->streamRevenue['satellite_broadband'], 1.0);
        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE, array_sum($leaning->streamRevenue), 1.0);
    }

    public function testTakeUpReportsTheSignUpsAheadOfTheCurveOrBehindIt(): void
    {
        $years = 8.0;
        $expected = ResponseCurves::bassAdoption($years - Space::BROADBAND_SERVICE_START_YEARS, Space::BROADBAND_INNOVATION, Space::BROADBAND_IMITATION);
        $momentum = [Space::STATE_YEARS_LISTED => $years, Space::STATE_LAUNCH_EXPERIENCE => Space::INITIAL_LAUNCH_EXPERIENCE_YEARS];
        $quarter = fn(float $broadbandZ): ActualFinancialsDTO => $this->model->computeActualFinancials(
            self::stock($momentum),
            self::EXPECTED_REVENUE,
            self::VARIABLE_COST_RATIO,
            10_000_000.0,
            0.45,
            new MacroStateDTO(),
            // Launch, government, then satellite internet draw their persistent Z in that order.
            new ScriptedDraws(generatePersistentZ: [0.0, 0.0, $broadbandZ]),
        );
        $onCurve = $quarter(0.0);
        $ahead = $quarter(1.5);
        $behind = $quarter(-1.5);

        $this->assertEqualsWithDelta($expected, $onCurve->kpis['broadband_penetration'], 1e-12);
        $this->assertGreaterThan($expected, $ahead->kpis['broadband_penetration']);
        $this->assertLessThan($expected, $behind->kpis['broadband_penetration']);
        // The take-up moves with the revenue: sign-ups ahead by a share s lift the odds of having signed up by s.
        $signUps = $ahead->streamRevenue['satellite_broadband'] / $onCurve->streamRevenue['satellite_broadband'];
        $odds = static fn(float $share): float => $share / (1.0 - $share);
        $this->assertEqualsWithDelta($signUps * $odds($expected), $odds($ahead->kpis['broadband_penetration']), 1e-9);
    }

    public function testTheTrueMarketIsDrawnAtTheListingAroundTheEstimate(): void
    {
        $result = $this->model->computeActualFinancials(
            self::stock(),
            self::EXPECTED_REVENUE,
            self::VARIABLE_COST_RATIO,
            10_000_000.0,
            0.0,
            new MacroStateDTO(),
            new ScriptedDraws(generatePersistentZ: fn(float $prev): float => 0.0, generateStandardNormal: 1.0),
        );
        $sigma = Space::MARKET_SIZE_UNCERTAINTY;

        // The estimate is the prior's mean, and a one-sigma draw lands above it by exp(sigma - sigma^2 / 2).
        $this->assertEqualsWithDelta(Space::BROADBAND_MATURE_SIZE, Space::believedMarketSize([]), 1e-12);
        $this->assertEqualsWithDelta(Space::BROADBAND_MATURE_SIZE * exp($sigma - ($sigma * $sigma / 2.0)), $result->streamZ[Space::STATE_MARKET_SIZE], 1e-9);
        // Before the service opens nothing is learned, and the estimate stays where it was.
        $this->assertEqualsWithDelta(1.0, $result->kpis['broadband_market_estimate'], 1e-12);
    }

    public function testSubscribersSignUpToTheTrueMarketAndRevealIt(): void
    {
        $years = 8.0;
        $trueMarket = 3.0;
        $momentum = [Space::STATE_YEARS_LISTED => $years, Space::STATE_LAUNCH_EXPERIENCE => Space::INITIAL_LAUNCH_EXPERIENCE_YEARS];
        $levels = self::levels($years);
        $onEstimate = $this->runQuarter([...$momentum, Space::STATE_MARKET_SIZE => Space::BROADBAND_MATURE_SIZE]);
        $bigger = $this->runQuarter([...$momentum, Space::STATE_MARKET_SIZE => $trueMarket]);

        // The plan splits on the estimate; the subscribers come from the market there is.
        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE * $levels['broadband'] / array_sum($levels), $onEstimate->streamRevenue['satellite_broadband'], 1.0);
        $this->assertEqualsWithDelta($trueMarket / Space::BROADBAND_MATURE_SIZE, $bigger->streamRevenue['satellite_broadband'] / $onEstimate->streamRevenue['satellite_broadband'], 1e-9);
        $this->assertEqualsWithDelta($onEstimate->streamRevenue['launch_services'], $bigger->streamRevenue['launch_services'], 1.0);

        // Once the estimate has caught up, the plan gives the internet the larger share the market as believed calls
        // for.
        $variance = 0.01;
        $believed = $this->runQuarter([...$momentum, Space::STATE_MARKET_SIZE => $trueMarket, Space::STATE_MARKET_LOG_MEAN => log($trueMarket) - ($variance / 2.0), Space::STATE_MARKET_LOG_VARIANCE => $variance]);
        $believedLevels = [...$levels, 'broadband' => $levels['broadband'] * $trueMarket / Space::BROADBAND_MATURE_SIZE];
        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE * $believedLevels['broadband'] / array_sum($believedLevels), $believed->streamRevenue['satellite_broadband'], 1.0);

        // The sign-ups revise the estimate toward the market they came from, and the plan's growth with it.
        $this->assertGreaterThan(1.0, $bigger->kpis['broadband_market_estimate']);
        $this->assertLessThan($trueMarket / Space::BROADBAND_MATURE_SIZE, $bigger->kpis['broadband_market_estimate']);
        $this->assertGreaterThan(
            $this->model->getFadedSecularGrowthRate(self::stock($onEstimate->streamZ), 0.0),
            $this->model->getFadedSecularGrowthRate(self::stock($bigger->streamZ), 0.0),
        );
    }

    public function testTheEstimateLearnsTheMarketAsTakeUpGrows(): void
    {
        $priorVariance = Space::MARKET_SIZE_UNCERTAINTY ** 2;
        $mean = log(Space::BROADBAND_MATURE_SIZE) - ($priorVariance / 2.0);
        $variance = $priorVariance;

        // No take-up, no reading.
        $this->assertSame([$mean, $variance], Space::updateMarketBelief($mean, $variance, log(3.0), 0.0));

        // One reading: precisions add and the mean moves by the reading's share of them.
        $precision = 0.5 / (Space::SIGN_UP_SIGNAL_NOISE ** 2);
        [$oneMean, $oneVariance] = Space::updateMarketBelief($mean, $variance, log(3.0), 0.5);
        $this->assertEqualsWithDelta(1.0 / ((1.0 / $variance) + $precision), $oneVariance, 1e-12);
        $this->assertEqualsWithDelta($mean + ($precision * $oneVariance * (log(3.0) - $mean)), $oneMean, 1e-12);

        // Ten years of readings at high take-up close on the market they read; the prior keeps a few per cent of the
        // weight.
        for ($quarter = 0; $quarter < 40; $quarter++) {
            [$mean, $variance] = Space::updateMarketBelief($mean, $variance, log(3.0), 0.9);
        }
        $this->assertEqualsWithDelta(3.0, Space::believedMarketSize([Space::STATE_MARKET_LOG_MEAN => $mean, Space::STATE_MARKET_LOG_VARIANCE => $variance]), 0.10);
        $this->assertLessThan(0.15, sqrt($variance));
    }

    public function testTakeUpStaysAShareOfTheMarketWhateverTheRevenueDoes(): void
    {
        // Early, take-up is the curve scaled by the sign-ups; near saturation it closes on the whole market.
        $this->assertEqualsWithDelta(0.02 * 1.3, Space::reportedTakeUp(0.02, 1.3), 0.001);
        $this->assertLessThan(1.0, Space::reportedTakeUp(0.98, 1.5));
        $this->assertGreaterThan(0.98, Space::reportedTakeUp(0.98, 1.5));
        $this->assertSame(0.0, Space::reportedTakeUp(0.0, 1.5));
        $this->assertEqualsWithDelta(0.40, Space::reportedTakeUp(0.40, 1.0), 1e-12);
    }

    public function testThePlantAndTheMarketReadTheCurvesGrowthAndTheLedgerItsLevel(): void
    {
        foreach ([0.0, 3.0, 8.0, 12.0, 18.0] as $years) {
            $stock = self::stock([Space::STATE_YEARS_LISTED => $years]);

            // The growth the plant and the multiple read is the slope of the lines' log level.
            $step = 1e-5;
            $slope = (log(array_sum(self::levels($years + $step))) - log(array_sum(self::levels(max(0.0, $years - $step))))) / ($years > 0.0 ? 2.0 * $step : $step);
            $this->assertEqualsWithDelta(MacroConstants::TREND_REAL_GROWTH + $slope, $this->model->getFadedSecularGrowthRate($stock, 0.0), 1e-4, "growth at {$years} years");

            // The ledger fades the secular rate from the open, and that fade lands on the level the curves reach.
            if ($years > 0.0) {
                $excess = $this->model->getSecularGrowthRate($stock) - MacroConstants::TREND_REAL_GROWTH;
                $faded = TimeSeries::fadedExcessIntegral($excess, 0.0, $years, FinancialConstants::SECULAR_EXCESS_HALF_LIFE_YEARS);
                $this->assertEqualsWithDelta(log(array_sum(self::levels($years)) / array_sum(self::levels(0.0))), $faded, 1e-9, "market at {$years} years");
            }
        }

        // Growth peaks while the sign-ups do and fades as the service saturates.
        $growth = fn(float $years): float => $this->model->getFadedSecularGrowthRate(self::stock([Space::STATE_YEARS_LISTED => $years]), 0.0);
        $this->assertGreaterThan($growth(2.0), $growth(10.0));
        $this->assertGreaterThan($growth(20.0), $growth(10.0));
    }

    /**
     * The internet is a segment whose share of telecom's market rides the adoption curve: its level, its expected
     * growth and its share of the three lines' sales. Nothing before the service opens, and the main business has no
     * path.
     */
    public function testTheInternetSegmentRidesTheAdoptionCurve(): void
    {
        $years = 12.0;
        $levels = self::levels($years);
        $path = $this->model->resolveSegmentPath(self::stock([Space::STATE_YEARS_LISTED => $years], 'SKYL'), 'satellite_internet');

        $this->assertNotNull($path);
        $this->assertEqualsWithDelta(log($levels['broadband']), $path['level'], 1e-12);
        $step = 1e-5;
        $this->assertEqualsWithDelta((log(self::levels($years + $step)['broadband']) - log(self::levels($years - $step)['broadband'])) / (2.0 * $step), $path['growth'], 1e-6);
        $this->assertEqualsWithDelta($levels['broadband'] / array_sum($levels), $path['share'], 1e-12);
        $this->assertNull($this->model->resolveSegmentPath(self::stock([Space::STATE_YEARS_LISTED => 3.0], 'SKYL'), 'satellite_internet'), 'before the service opens');
        $this->assertNull($this->model->resolveSegmentPath(self::stock([Space::STATE_YEARS_LISTED => $years], 'SKYL'), Segments::MAIN));
    }

    /**
     * With plant by segment the model's own growth is the launches' and missions': the internet's is its segment's
     * path, so the main market and the plant it builds follow those two lines alone.
     */
    public function testWithPlantBySegmentTheModelGrowsItsOwnLines(): void
    {
        $years = 12.0;
        $stock = self::stock([Space::STATE_YEARS_LISTED => $years, SegmentBooks::STATE_PLANT . Segments::MAIN => 0.6, SegmentBooks::STATE_PLANT . 'satellite_internet' => 0.4], 'SKYL');
        $main = static fn(float $at): float => self::levels($at)['launch'] + self::levels($at)['government'];

        $step = 1e-5;
        $slope = (log($main($years + $step)) - log($main($years - $step))) / (2.0 * $step);
        $this->assertEqualsWithDelta(MacroConstants::TREND_REAL_GROWTH + $slope, $this->model->getFadedSecularGrowthRate($stock, 0.0), 1e-6);
        $excess = $this->model->getSecularGrowthRate($stock) - MacroConstants::TREND_REAL_GROWTH;
        $this->assertEqualsWithDelta(log($main($years) / $main(0.0)), TimeSeries::fadedExcessIntegral($excess, 0.0, $years, FinancialConstants::SECULAR_EXCESS_HALF_LIFE_YEARS), 1e-9);
    }

    /** With plant by segment each segment's lines share that segment's part of the plan. */
    public function testEachSegmentsLinesSplitItsShareOfThePlan(): void
    {
        $years = 12.0;
        $levels = self::levels($years);
        $result = $this->model->computeActualFinancials(
            self::stock([
                Space::STATE_YEARS_LISTED => $years,
                Space::STATE_LAUNCH_EXPERIENCE => Space::INITIAL_LAUNCH_EXPERIENCE_YEARS,
                Space::STATE_MARKET_SIZE => Space::BROADBAND_MATURE_SIZE,
                SegmentBooks::STATE_PLANT . Segments::MAIN => 0.7,
                SegmentBooks::STATE_PLANT . 'satellite_internet' => 0.3,
                SegmentBooks::STATE_TARGET . Segments::MAIN => 0.7,
                SegmentBooks::STATE_TARGET . 'satellite_internet' => 0.3,
            ], 'SKYL'),
            self::EXPECTED_REVENUE,
            self::VARIABLE_COST_RATIO,
            10_000_000.0,
            0.0,
            new MacroStateDTO(),
            new ScriptedDraws(generatePersistentZ: fn(float $prev): float => 0.0),
        );

        $this->assertEqualsWithDelta(0.3 * self::EXPECTED_REVENUE, $result->streamRevenue['satellite_broadband'], 1.0);
        $this->assertEqualsWithDelta(0.7 * self::EXPECTED_REVENUE * $levels['launch'] / ($levels['launch'] + $levels['government']), $result->streamRevenue['launch_services'], 1.0);
        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE, array_sum($result->streamRevenue), 1.0);
    }

    public function testEachLaunchFlownMakesTheNextCheaperAndHalfTheSavingIsPassedOn(): void
    {
        $initial = Space::INITIAL_LAUNCH_EXPERIENCE_YEARS;

        $this->assertEqualsWithDelta(1.0, Space::learnedLaunchCost($initial), 1e-12);
        $this->assertEqualsWithDelta(Space::LAUNCH_LEARNING_RATE, Space::learnedLaunchCost(2.0 * $initial), 1e-12);
        $this->assertEqualsWithDelta(Space::LAUNCH_COST_FLOOR, Space::learnedLaunchCost(1000.0 * $initial), 1e-12);
        // Cost 0.85 against a price that fell half as far, 0.925.
        $this->assertEqualsWithDelta(0.85 / 0.925, Space::launchCostRatio(2.0 * $initial), 1e-12);

        $novice = $this->runQuarter();
        $veteran = $this->runQuarter([Space::STATE_LAUNCH_EXPERIENCE => 4.0 * $initial]);
        $launchSaving = self::VARIABLE_COST_RATIO * (1.0 - Space::launchCostRatio(4.0 * $initial)) * $veteran->streamRevenue['launch_services'];
        $this->assertEqualsWithDelta($launchSaving, $novice->actualVariableCosts - $veteran->actualVariableCosts, 1.0);
    }

    public function testAFailedLaunchGroundsTheFleetAndTheManifestFliesLater(): void
    {
        $grounded = $this->runQuarter(probabilities: [true]);
        $steady = (1.0 - Space::LAUNCH_BURN_RATE) / Space::LAUNCH_BURN_RATE;
        $flown = Space::LAUNCH_BURN_RATE * ($steady + 1.0) * (1.0 - Space::GROUNDED_LAUNCH_SHARE);

        $this->assertSame(ShockEvent::LAUNCH_FAILURE, $grounded->eventType);
        // Even in the first quarter on record the manifest is seeded at its flying depth, not its grounded one.
        $this->assertEqualsWithDelta(70_000_000.0 * $flown, $grounded->streamRevenue['launch_services'], 1.0);
        $this->assertEqualsWithDelta(($steady + 1.0) - $flown, $grounded->streamZ[StreamContext::BACKLOG_STATE_PREFIX . 'launch_services'], 1e-9);
        // Back in the air, the waiting manifest flies off above the run rate.
        $returned = $this->runQuarter([...$grounded->streamZ, StreamContext::REGIME_STATE_PREFIX . Space::REGIME_GROUNDED => 0.0]);
        $this->assertGreaterThan(70_000_000.0, $returned->streamRevenue['launch_services']);
    }

    public function testAYoungRocketFailsMoreOftenThanAProvenOne(): void
    {
        $initial = Space::INITIAL_LAUNCH_EXPERIENCE_YEARS;
        $hazards = [];
        $draws = new ScriptedDraws(generatePersistentZ: fn(float $prev): float => 0.0, checkProbability: function (float $probability) use (&$hazards): bool {
            $hazards[] = $probability;

            return false;
        });
        $this->model->computeActualFinancials(self::stock([Space::STATE_LAUNCH_EXPERIENCE => 4.0 * $initial]), self::EXPECTED_REVENUE, self::VARIABLE_COST_RATIO, 10_000_000.0, 0.0, new MacroStateDTO(), $draws);

        $this->assertEqualsWithDelta(Space::LAUNCH_FAILURE_HAZARD, Space::launchFailureHazard($initial), 1e-12);
        // Two doublings of the launches flown: the failure rate at the learning rate squared.
        $this->assertEqualsWithDelta(Space::LAUNCH_FAILURE_HAZARD * Space::RELIABILITY_LEARNING_RATE ** 2, $hazards[0], 1e-12);
    }

    public function testItPaysOutWhatTheGrowthStillAheadCannotUse(): void
    {
        $macro = new MacroStateDTO(inflationEma: 0.02);
        $share = fn(float $years): float => $this->model->getUndeployableCapitalShare(self::stock([Space::STATE_YEARS_LISTED => $years])->setBaselineRoic('0.12'), $macro, new ScriptedDraws());

        // The constellation still to come needs every dollar it earns, and more.
        $this->assertSame(0.0, $share(0.0));
        $this->assertSame(0.0, $share(6.0));

        // Past the sign-up peak, growth is falling: the fastest still ahead is today's, and g = reinvestment x return.
        $years = 20.0;
        $step = 1e-5;
        $growth = MacroConstants::TREND_REAL_GROWTH + ((log(array_sum(self::levels($years + $step))) - log(array_sum(self::levels($years - $step)))) / (2.0 * $step));
        $this->assertEqualsWithDelta(1.0 - (($growth + 0.02) / 0.12), $share($years), 1e-4);
        $this->assertGreaterThan(0.3, $share($years));
    }

    public function testTheFirmIsStaffedToTheLaunchesAndMissionsInHand(): void
    {
        $steadyLaunch = (1.0 - Space::LAUNCH_BURN_RATE) / Space::LAUNCH_BURN_RATE;
        $stock = self::stock([StreamContext::BACKLOG_STATE_PREFIX . 'launch_services' => 2.0 * $steadyLaunch]);
        $workload = Space::LAUNCH_BURN_RATE * ((2.0 * $steadyLaunch) + 1.0);

        $this->assertEqualsWithDelta(Space::LAUNCH_SERVICES_WEIGHT * ($workload - 1.0), $this->model->resolveSectorActivityShift($stock, new MacroStateDTO()), 1e-12);
    }
}
