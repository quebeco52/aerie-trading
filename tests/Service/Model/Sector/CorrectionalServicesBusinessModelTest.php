<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\TimeSeries;
use App\Service\Model\Sector\CorrectionalServicesBusinessModel as Model;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class CorrectionalServicesBusinessModelTest extends TestCase
{
    private const PLAN = 100_000_000.0;
    private const COST_RATIO = 0.60;

    private Model $model;

    protected function setUp(): void
    {
        $this->model = new Model();
    }

    /**
     * One quarter with the custody, detention, tagging and disturbance draws scripted in that order.
     *
     * @param array<string, float> $state
     * @param list<float>          $draws
     */
    private function runQuarter(MacroStateDTO $macro, array $state = [], array $draws = [0.0, 0.0, 0.0, 0.0]): ActualFinancialsDTO
    {
        $stock = (new Stock())->setTicker('JAIL_TEST')->setBeta('1.00')->setEarningsMomentumZ($state);

        return $this->model->computeActualFinancials($stock, self::PLAN, self::COST_RATIO, 10_000_000.0, 0.0, $macro, new ScriptedDraws(generatePersistentZ: $draws));
    }

    public function testACalmQuarterRunsThePlanAtThePlannedCostRatio(): void
    {
        $result = $this->runQuarter(new MacroStateDTO());

        $this->assertEqualsWithDelta(self::PLAN * Model::CUSTODY_WEIGHT, $result->streamRevenue['custody'], 1.0);
        $this->assertEqualsWithDelta(self::PLAN * Model::DETENTION_WEIGHT, $result->streamRevenue['detention'], 1.0);
        $this->assertEqualsWithDelta(self::PLAN * Model::ESCORT_TAGGING_WEIGHT, $result->streamRevenue['escort_tagging'], 1.0);
        $this->assertEqualsWithDelta(self::PLAN * self::COST_RATIO, $result->actualVariableCosts, 1.0);
        $this->assertNull($result->eventType);
    }

    public function testEmptyBedsAreStillPaidDownToTheGuaranteeAndCostNothing(): void
    {
        $empty = 0.70;
        $result = $this->runQuarter(new MacroStateDTO(), ['state:custody_population' => $empty]);

        $this->assertEqualsWithDelta(self::PLAN * Model::CUSTODY_WEIGHT * Model::MINIMUM_OCCUPANCY_GUARANTEE / Model::PLANNED_OCCUPANCY, $result->streamRevenue['custody'], 1.0);
        $held = (Model::CUSTODY_WEIGHT * $empty / Model::PLANNED_OCCUPANCY) + Model::DETENTION_WEIGHT + Model::ESCORT_TAGGING_WEIGHT;
        $this->assertEqualsWithDelta(self::PLAN * self::COST_RATIO * $held, $result->actualVariableCosts, 1.0);
    }

    public function testAFullPrisonTurnsTheOverflowAway(): void
    {
        $result = $this->runQuarter(new MacroStateDTO(), ['state:custody_population' => 1.10]);
        $full = self::PLAN * Model::CUSTODY_WEIGHT * Model::MAXIMUM_OCCUPANCY / Model::PLANNED_OCCUPANCY;

        $this->assertEqualsWithDelta($full, $result->streamRevenue['custody'], 1.0);
    }

    public function testThePopulationFollowsCrimeOnlyAsFastAsSentencesRunOut(): void
    {
        $gap = 0.01;
        $result = $this->runQuarter(new MacroStateDTO(unemploymentRateEma: MacroConstants::NATURAL_UNEMPLOYMENT + $gap, nairuEma: MacroConstants::NATURAL_UNEMPLOYMENT));

        // This quarter holds who was there as it opened; the next opens a step toward the higher demand.
        $this->assertEqualsWithDelta(self::PLAN * Model::CUSTODY_WEIGHT, $result->streamRevenue['custody'], 1.0);
        $step = TimeSeries::smoothingWeight(FinancialConstants::QUARTER_YEARS, Model::CUSTODY_STAY_YEARS);
        $this->assertEqualsWithDelta(Model::PLANNED_OCCUPANCY * (1.0 + ($step * $gap * Model::CRIME_UNEMPLOYMENT_ELASTICITY)), $result->streamZ['state:custody_population'], 1e-12);
        // The courts' caseload moves at once.
        $this->assertEqualsWithDelta(self::PLAN * Model::ESCORT_TAGGING_WEIGHT * (1.0 + ($gap * Model::CRIME_UNEMPLOYMENT_ELASTICITY)), $result->streamRevenue['escort_tagging'], 1.0);
    }

    public function testDetentionFollowsArrivals(): void
    {
        $intake = MacroConstants::STRUCTURAL_LABOR_GROWTH_RATE - MacroConstants::NATURAL_LABOR_FORCE_GROWTH;
        $migration = 0.1 * $intake;
        $result = $this->runQuarter(new MacroStateDTO(cyclicalMigrationRate: $migration));

        $step = TimeSeries::smoothingWeight(FinancialConstants::QUARTER_YEARS, Model::DETENTION_STAY_YEARS);
        $this->assertEqualsWithDelta(Model::PLANNED_OCCUPANCY * (1.0 + ($step * 0.1 * Model::DETENTION_ARRIVALS_ELASTICITY)), $result->streamZ['state:detention_population'], 1e-12);
    }

    public function testABudgetCutReachesTheRateOnlyAsConcessionsAreReLet(): void
    {
        $cut = $this->runQuarter(new MacroStateDTO(governmentSpendingIndexEma: 90.0));
        $relet = TimeSeries::calculateDistributedLag(1.0, 1.0 - (0.10 * Model::RATE_BUDGET_SCALAR), FinancialConstants::QUARTER_YEARS, Model::CONCESSION_TERM_YEARS);
        $this->assertEqualsWithDelta($relet, $cut->streamZ['state:concession_rate'], 1e-12);
        $this->assertGreaterThan(0.99, $relet);

        // A lower rate in force cuts the District's lines and none of the cost of running them.
        $calm = $this->runQuarter(new MacroStateDTO());
        $lower = $this->runQuarter(new MacroStateDTO(), ['state:concession_rate' => 0.95]);
        $this->assertEqualsWithDelta(0.95 * $calm->streamRevenue['custody'], $lower->streamRevenue['custody'], 1.0);
        $this->assertEqualsWithDelta($calm->streamRevenue['escort_tagging'], $lower->streamRevenue['escort_tagging'], 1.0);
        $this->assertEqualsWithDelta($calm->actualVariableCosts, $lower->actualVariableCosts, 1.0);
    }

    public function testARiotChargesTheQuarterAndSendsTaggingClientsAway(): void
    {
        $calm = $this->runQuarter(new MacroStateDTO(), [], [0.0, 0.0, 0.0, Model::DISTURBANCE_Z_SCORE + 0.1]);
        $riot = $this->runQuarter(new MacroStateDTO(), [], [0.0, 0.0, 0.0, Model::DISTURBANCE_Z_SCORE - 0.1]);

        $this->assertNull($calm->eventType);
        $this->assertSame(ShockEvent::PRISON_DISTURBANCE, $riot->eventType);
        $this->assertTrue($riot->isPublicEvent);
        $this->assertEqualsWithDelta(Model::DISTURBANCE_CHARGE * $riot->actualRevenue, $riot->specialCharge, 1.0);
        $this->assertEqualsWithDelta(Model::DISTURBANCE_TAGGING_REV_MULT * $calm->streamRevenue['escort_tagging'], $riot->streamRevenue['escort_tagging'], 1.0);
        $this->assertEqualsWithDelta($calm->streamRevenue['custody'], $riot->streamRevenue['custody'], 1.0);
    }

    public function testTheOfficersFollowThePrisonersHeld(): void
    {
        $stock = (new Stock())->setTicker('JAIL_TEST')->setEarningsMomentumZ(['state:custody_population' => 1.10]);

        $full = (Model::MAXIMUM_OCCUPANCY / Model::PLANNED_OCCUPANCY) - 1.0;
        $this->assertEqualsWithDelta(Model::CUSTODY_WEIGHT * $full, $this->model->resolveSectorActivityShift($stock, new MacroStateDTO()), 1e-12);
    }
}
