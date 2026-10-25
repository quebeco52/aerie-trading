<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\Distributions;
use App\Service\Math\StochasticProcesses;
use App\Service\Model\Sector\AirlineBusinessModel;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class AirlineBusinessModelTest extends TestCase
{
    private const EXPECTED_REVENUE = 100_000_000.0;
    private const VARIABLE_COST_RATIO = 0.60;
    /** Passenger lines' share of the plan at the baseline mix. */
    private const PASSENGER_SHARE = AirlineBusinessModel::LEISURE_PASSENGER_WEIGHT + AirlineBusinessModel::BUSINESS_PASSENGER_WEIGHT;

    private AirlineBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new AirlineBusinessModel();
    }

    /** A schedule set at plan the quarter before. */
    private const PLANNED_SCHEDULE = [AirlineBusinessModel::STATE_SCHEDULED_SEATS => 1.0];

    private static function macro(float $domesticGap = 0.0, float $outputGap = 0.0): MacroStateDTO
    {
        return new MacroStateDTO(outputGapEma: $outputGap, domesticDemandGapEma: $domesticGap);
    }

    /**
     * One quarter with every draw at zero unless the labour draw (the fifth persistent draw) is scripted.
     *
     * @param array<string, float> $momentum
     */
    private function runQuarter(MacroStateDTO $macro, array $momentum = [], float $labourZ = 0.0): ActualFinancialsDTO
    {
        $stock = (new Stock())->setTicker('AIRLINE_TEST')->setBeta('1.00');
        $stock->setEarningsMomentumZ($momentum);
        $draws = 0;

        return $this->model->computeActualFinancials(
            $stock,
            self::EXPECTED_REVENUE,
            self::VARIABLE_COST_RATIO,
            30_000_000.0,
            0.0,
            $macro,
            new ScriptedDraws(generatePersistentZ: function (float $prev) use (&$draws, $labourZ): float {
                return ++$draws === 5 ? $labourZ : 0.0;
            }),
        );
    }

    private static function strikeCompensation(): float
    {
        return StochasticProcesses::bernoulliLossCompensator(Distributions::standardNormalCdf(AirlineBusinessModel::STRIKE_Z_SCORE), AirlineBusinessModel::STRIKE_GROUNDED_SHARE);
    }

    /** What the flying costs: the passenger plan at the seats flown, cargo and miles at plan. */
    private static function flownCosts(float $seatsFlown): float
    {
        return self::EXPECTED_REVENUE * ((self::PASSENGER_SHARE * $seatsFlown) + (1.0 - self::PASSENGER_SHARE)) * self::VARIABLE_COST_RATIO;
    }

    public function testACalmQuarterFliesThePlanMixAtThePlannedLoad(): void
    {
        $result = $this->runQuarter(self::macro());
        $flown = self::strikeCompensation();

        $this->assertEqualsWithDelta(45_000_000.0 * $flown, $result->streamRevenue['leisure_passengers'], 1.0);
        $this->assertEqualsWithDelta(32_000_000.0 * $flown, $result->streamRevenue['business_passengers'], 1.0);
        $this->assertEqualsWithDelta(10_000_000.0 * $flown, $result->streamRevenue['air_cargo'], 1.0);
        $this->assertEqualsWithDelta(13_000_000.0, $result->streamRevenue['loyalty_miles'], 1.0);
        $this->assertEqualsWithDelta(AirlineBusinessModel::PLANNED_LOAD_FACTOR, $result->kpis['load_factor'], 1e-12);
        $this->assertEqualsWithDelta(self::flownCosts($flown), $result->actualVariableCosts, 1.0);
        $this->assertNull($result->eventType);
    }

    /** Passenger demand across the cabins for leisure and business demand over plan. */
    private static function passengerDemand(float $leisure, float $business): float
    {
        return ((AirlineBusinessModel::LEISURE_PASSENGER_WEIGHT * $leisure) + (AirlineBusinessModel::BUSINESS_PASSENGER_WEIGHT * $business)) / self::PASSENGER_SHARE;
    }

    public function testFallingDemandEmptiesTheScheduledPlanesAndCutsTheFareOnThem(): void
    {
        $gap = -0.03;
        $calm = $this->runQuarter(self::macro(), self::PLANNED_SCHEDULE);
        $slump = $this->runQuarter(self::macro(domesticGap: $gap), self::PLANNED_SCHEDULE);

        // The schedule was set at plan: passengers per seat fall, and the fare on every seat with them.
        $leisure = 1.0 + ((1.0 - AirlineBusinessModel::VISITOR_SHARE) * $gap * AirlineBusinessModel::LEISURE_DEMAND_SENSITIVITY);
        $fare = 1.0 + (AirlineBusinessModel::FARE_LOAD_ELASTICITY * (self::passengerDemand($leisure, 1.0) - 1.0));
        $this->assertEqualsWithDelta($leisure * $fare, $slump->streamRevenue['leisure_passengers'] / $calm->streamRevenue['leisure_passengers'], 1e-9);
        $this->assertEqualsWithDelta($fare, $slump->streamRevenue['business_passengers'] / $calm->streamRevenue['business_passengers'], 1e-9);
        $this->assertEqualsWithDelta(AirlineBusinessModel::PLANNED_LOAD_FACTOR * self::passengerDemand($leisure, 1.0), $slump->kpis['load_factor'], 1e-12);

        // The flights still fly, so the cost of flying them is the plan's.
        $this->assertEqualsWithDelta($calm->actualVariableCosts, $slump->actualVariableCosts, 1.0);
    }

    public function testTheScheduleChasesTheTrafficWithAHalfYearLag(): void
    {
        $gap = -0.03;
        $first = $this->runQuarter(self::macro(outputGap: $gap), self::PLANNED_SCHEDULE);
        $demand = self::passengerDemand(1.0, 1.0 + ($gap * AirlineBusinessModel::BUSINESS_GAP_SENSITIVITY));
        $seats = 1.0 + ((1.0 - exp(-0.25 / AirlineBusinessModel::SCHEDULE_LAG_YEARS)) * ($demand - 1.0));
        $this->assertEqualsWithDelta($seats, $first->streamZ[AirlineBusinessModel::STATE_SCHEDULED_SEATS], 1e-12);

        // The next quarter flies the cut schedule: its costs follow the seats, and the planes fill back toward plan.
        $second = $this->runQuarter(self::macro(outputGap: $gap), $first->streamZ);
        $this->assertEqualsWithDelta($seats * self::strikeCompensation(), $second->kpis['seat_capacity_index'], 1e-12);
        $this->assertEqualsWithDelta(self::flownCosts($seats * self::strikeCompensation()), $second->actualVariableCosts, 1.0);
        $this->assertGreaterThan($first->kpis['load_factor'], $second->kpis['load_factor']);

        // The cost base is staffed to the schedule set for the quarter.
        $stock = (new Stock())->setTicker('AIRLINE_TEST');
        $stock->setEarningsMomentumZ($first->streamZ);
        $this->assertEqualsWithDelta(self::PASSENGER_SHARE * ($seats - 1.0), $this->model->resolveSectorActivityShift($stock, self::macro(outputGap: $gap)), 1e-12);
    }

    public function testAFullScheduleTurnsPassengersAwayAndPricesTheLastSeats(): void
    {
        $surge = 0.10;
        $result = $this->runQuarter(self::macro(outputGap: $surge), self::PLANNED_SCHEDULE);

        $business = 1.0 + ($surge * AirlineBusinessModel::BUSINESS_GAP_SENSITIVITY);
        $demand = self::passengerDemand(1.0, $business);
        $carried = (AirlineBusinessModel::MAXIMUM_LOAD_FACTOR / AirlineBusinessModel::PLANNED_LOAD_FACTOR) / $demand;
        $fare = 1.0 + (AirlineBusinessModel::FARE_LOAD_ELASTICITY * ($demand - 1.0));
        $this->assertLessThan(1.0, $carried);
        $this->assertEqualsWithDelta(32_000_000.0 * $business * $carried * $fare * self::strikeCompensation(), $result->streamRevenue['business_passengers'], 1.0);
        $this->assertEqualsWithDelta(AirlineBusinessModel::MAXIMUM_LOAD_FACTOR, $result->kpis['load_factor'], 1e-12);
    }

    public function testAStrikeCancelsFlightsWithTheirPassengersCargoAndCosts(): void
    {
        $result = $this->runQuarter(self::macro(), labourZ: -3.0);
        $flown = self::strikeCompensation() * (1.0 - AirlineBusinessModel::STRIKE_GROUNDED_SHARE);

        $this->assertSame(ShockEvent::FLIGHT_CREW_STRIKE, $result->eventType);
        $this->assertEqualsWithDelta(45_000_000.0 * $flown, $result->streamRevenue['leisure_passengers'], 1.0);
        $this->assertEqualsWithDelta(10_000_000.0 * $flown, $result->streamRevenue['air_cargo'], 1.0);
        $this->assertEqualsWithDelta(13_000_000.0, $result->streamRevenue['loyalty_miles'], 1.0);
        $this->assertEqualsWithDelta(self::flownCosts($flown), $result->actualVariableCosts, 1.0);
        // Fares were sold against the schedule, so the flights that do go keep their load and fare.
        $this->assertEqualsWithDelta(AirlineBusinessModel::PLANNED_LOAD_FACTOR, $result->kpis['load_factor'], 1e-12);

        // Over the labour draw's distribution the flying averages the schedule.
        $probability = Distributions::standardNormalCdf(AirlineBusinessModel::STRIKE_Z_SCORE);
        $this->assertEqualsWithDelta(1.0, self::strikeCompensation() * (1.0 - ($probability * AirlineBusinessModel::STRIKE_GROUNDED_SHARE)), 1e-12);
    }
}
