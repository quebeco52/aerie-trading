<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\Aggregate\MovingAverages;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Math\MathUtility;
use App\Service\Model\AbstractBusinessModel;
use App\Service\Model\Sector\ConstructionBusinessModel;
use App\Service\Model\Sector\InternetRetailBusinessModel;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Buyers hold durables, equipment and project budgets in step with their income, so they spend the change in the stock
 * they want on top of its upkeep (StandardOperatingPhysicsTrait::resolveSpendingAdjustmentShift()).
 */
#[Group('unit')]
final class SpendingAdjustmentTest extends TestCase
{
    private static function stock(): Stock
    {
        return (new Stock())->setTicker('XXXX');
    }

    /** A firm whose buyers hold no stock of what it sells meets the level of the cycle only. */
    public function testAFirmSellingNoDurablesSpendsNoChange(): void
    {
        $this->assertSame(0.0, (new StandardCorporateBusinessModel())->resolveSpendingAdjustmentShift(self::stock(), new MacroStateDTO(outputGapEma: -0.03, outputGapLag3m: 0.01)));
    }

    /**
     * A falling gap cuts a durable seller's demand by its share, the stock-adjustment multiple and its cyclicality
     * times the rate the gap falls, read for a seller with no order book at the shortest lag; a gap at rest moves
     * nothing.
     */
    public function testAFallingGapCutsDurableDemandByTheRateItFalls(): void
    {
        $model = new InternetRetailBusinessModel();
        $stock = self::stock();
        $shift = $model->resolveSpendingAdjustmentShift($stock, new MacroStateDTO(outputGapEma: -0.01, outputGapLag3m: 0.01, outputGapLag18m: 0.03));

        $this->assertEqualsWithDelta(
            InternetRetailBusinessModel::DURABLE_DEMAND_SHARE * AbstractBusinessModel::DURABLE_STOCK_ADJUSTMENT_MULTIPLE * $model->getOperatingCyclicality($stock) * (-0.02 / 0.25),
            $shift,
            1e-12,
        );
        $this->assertLessThan(0.0, $shift);
        $this->assertSame(0.0, $model->resolveSpendingAdjustmentShift($stock, new MacroStateDTO(outputGapEma: -0.02, outputGapLag3m: -0.02)), 'a gap at rest');
    }

    /** A builder meets the change through its backlog: the rate its own lagged gap moves at. */
    public function testALaggedSellerReadsTheChangeThroughItsOwnLag(): void
    {
        $model = new ConstructionBusinessModel();
        $stock = self::stock();

        $this->assertEqualsWithDelta(
            ConstructionBusinessModel::DURABLE_DEMAND_SHARE * AbstractBusinessModel::DURABLE_STOCK_ADJUSTMENT_MULTIPLE * $model->getOperatingCyclicality($stock) * (-0.03 / 1.5),
            $model->resolveSpendingAdjustmentShift($stock, new MacroStateDTO(outputGapEma: -0.01, outputGapLag3m: 0.03, outputGapLag18m: 0.02)),
            1e-12,
        );
    }

    /**
     * Each published lag closes on the quarter-smoothed gap at its own time constant, so the gap's distance from it
     * over that time is the rate it moves at: what the shift reads is the change in the lagged gap the firm meets.
     */
    public function testALagsDistanceFromTheGapOverItsTimeIsTheRateItMoves(): void
    {
        $state = new MacroState();
        $state->outputGap = -0.03;
        $state->outputGapEma = -0.02;
        foreach (array_keys(MovingAverages::DEMAND_TRANSMISSION_LAGS) as $field) {
            $state->$field = 0.01;
        }
        $before = clone $state;
        $dt = 1.0e-6;

        MacroAggregateSubsystem::headless(new MathUtility())->updateExponentialMovingAverages($state, $dt);

        foreach (MovingAverages::DEMAND_TRANSMISSION_LAGS as $field => $lagYears) {
            $this->assertEqualsWithDelta(($before->outputGapEma - $before->$field) / $lagYears, ($state->$field - $before->$field) / $dt, 1e-6, $field);
        }
    }
}
