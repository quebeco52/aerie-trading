<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
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
    private static function stock(array $momentum = []): Stock
    {
        $stock = (new Stock())->setTicker('SPACE_TEST')->setBeta('1.00');
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
        $result = $this->runQuarter([Space::STATE_YEARS_LISTED => $years, Space::STATE_LAUNCH_EXPERIENCE => Space::INITIAL_LAUNCH_EXPERIENCE_YEARS]);

        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE * $levels['broadband'] / array_sum($levels), $result->streamRevenue['satellite_broadband'], 1.0);
        $this->assertGreaterThan($result->streamRevenue['launch_services'], $result->streamRevenue['satellite_broadband']);
        $this->assertEqualsWithDelta(ResponseCurves::bassAdoption($years - Space::BROADBAND_SERVICE_START_YEARS, Space::BROADBAND_INNOVATION, Space::BROADBAND_IMITATION), $result->kpis['broadband_penetration'], 1e-12);
        // A subscriber costs less to serve than a launch costs to fly.
        $this->assertLessThan(self::EXPECTED_REVENUE * self::VARIABLE_COST_RATIO, $result->actualVariableCosts);
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
