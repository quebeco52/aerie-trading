<?php

declare(strict_types=1);

namespace App\Service\Macro\Subsystem\Aggregate;

use App\Service\Macro\MacroState;
use App\Service\Math\FirmEconomics;
use App\Service\Math\StochasticProcesses;
use App\Service\Math\TimeSeries;

/**
 * The two level parts of GDP that move output between quarters without adding to it over time: inventory investment,
 * as production runs ahead of or behind sales (Metzler 1941, Blinder 1982), and purchases brought forward or put off
 * and then paid back (Mian & Sufi 2012).
 */
final class InventoryAndTiming
{
    // --- Metzler-Blinder Inventory Investment Cycle (Metzler 1941, Blinder 1982) ---
    /**
     * Inventories over a year's sales (~15%): a miss worth a share of the year's output moves the inventory-to-sales
     * ratio by that share over this.
     */
    public const INVENTORY_TO_ANNUAL_SALES = 0.15;
    /** Speed (a year) at which firms work an overhang back to their target stock: half of it goes in 0.87 years. */
    public const INVENTORY_ADJUSTMENT_SPEED = 0.80;
    /**
     * Share of a miss on expected sales that goes into stock before production adjusts: 0.6, so on a quarter's
     * expectation output trails final sales by about 0.15 years.
     */
    public const INVENTORY_SURPRISE_SENSITIVITY = 0.60;
    /** Mean reversion (a year) of production run ahead of or behind plan: a cost window lasts about half a year. */
    public const INVENTORY_COST_SHOCK_REVERSION = 2.0;
    /**
     * Stationary sd of production over plan, a share of a year's domestic sales (Ramey & West 1999 cost shocks): 0.3%,
     * so inventory investment moves about 0.3% of GDP, under the US's 0.4% as a services centre holds less stock.
     */
    public const INVENTORY_COST_SHOCK_SD = 0.003;

    // --- Spending Timing (durables, equipment and procurement moved between quarters) ---
    /** Mean reversion (a year) of purchases brought forward or put off: a timing window lasts about a quarter. */
    public const SPENDING_TIMING_SHOCK_REVERSION = 4.0;
    /**
     * Stationary sd of purchases brought forward (+) or put off (-), a share of GDP a year: 0.4%, a promotion, a tax
     * change, a hard winter or a large order moving spending between quarters.
     */
    public const SPENDING_TIMING_SHOCK_SD = 0.004;
    /**
     * Speed (a year) at which purchases brought forward are missing from later quarters, and put-off ones made: half
     * in about four months (Mian & Sufi 2012: the clunkers' pull-forward reversed within the year).
     */
    public const SPENDING_TIMING_PAYBACK_SPEED = 2.0;

    /**
     * Steps the inventory cycle and returns the change in inventory investment over the step, the level part's move.
     *
     * @param MacroState $state      Current macroeconomic state.
     * @param float      $y          The domestic demand gap the tick opened on (OutputGap::domesticDemandGap()).
     * @param float      $inventoryZ Standard normal draw for production over plan.
     * @param float      $dt         Time increment in years.
     */
    public static function stepInventoryInvestment(MacroState $state, float $y, float $inventoryZ, float $dt): float
    {
        // Metzler (1941) & Blinder (1982) inventory investment cycle step, on domestic demand against its own average:
        // like with like, so the level parts riding beside the demand equation set off no surprise. Inventory
        // investment is the stock's change over the step, a level part of GDP beside final sales.
        $state->inventoryCostShock = StochasticProcesses::calculateOrnsteinUhlenbeckStep($state->inventoryCostShock, self::INVENTORY_COST_SHOCK_REVERSION, self::INVENTORY_COST_SHOCK_SD, $dt, $inventoryZ);
        $openingStock = $state->inventoryStockGap;
        $state->inventoryStockGap = FirmEconomics::calculateInventoryCycleStep(
            currentInventoryGap: $openingStock,
            outputGap: $y,
            outputGapEma: $state->domesticDemandGapEma,
            speed: self::INVENTORY_ADJUSTMENT_SPEED,
            surpriseSens: self::INVENTORY_SURPRISE_SENSITIVITY,
            dt: $dt,
            inventoryToSales: self::INVENTORY_TO_ANNUAL_SALES,
            productionShock: $state->inventoryCostShock,
        );
        $inventoryInvestment = self::inventoryInvestmentAt($state->inventoryStockGap - $openingStock, $dt);
        $inventoryChange = $inventoryInvestment - $state->inventoryInvestmentGap;
        $state->inventoryInvestmentGap = $inventoryInvestment;

        return $inventoryChange;
    }

    /**
     * Steps the purchases moved between quarters and returns the change in the timing flow over the step, the level
     * part's move.
     *
     * @param MacroState $state   Current macroeconomic state.
     * @param float      $timingZ Standard normal draw for purchases brought forward or put off.
     * @param float      $dt      Time increment in years.
     */
    public static function stepSpendingTiming(MacroState $state, float $timingZ, float $dt): float
    {
        // Spending moved between quarters (Mian & Sufi 2012): purchases brought forward are missing from the quarters
        // they came from, so the stock of them is paid back and timing adds no spending over time. The stock's change
        // over the step is the timing's flow, a level part of GDP beside final demand.
        $state->spendingTimingShock = StochasticProcesses::calculateOrnsteinUhlenbeckStep($state->spendingTimingShock, self::SPENDING_TIMING_SHOCK_REVERSION, self::SPENDING_TIMING_SHOCK_SD, $dt, $timingZ);
        $pulledForward = self::spendingPulledForwardAfter($state->spendingPulledForward, $state->spendingTimingShock, $dt);
        $spendingTiming = ($pulledForward - $state->spendingPulledForward) / $dt;
        $state->spendingPulledForward = $pulledForward;
        $timingChange = $spendingTiming - $state->spendingTimingGap;
        $state->spendingTimingGap = $spendingTiming;

        return $timingChange;
    }

    /**
     * The purchases brought forward and not yet missed from later quarters, a share of a year's GDP, after a step: the
     * timing flow adds to them and they are paid back at SPENDING_TIMING_PAYBACK_SPEED, exact for a flow held through
     * the step.
     *
     * @param float $pulledForward Purchases brought forward outstanding (negative: put off).
     * @param float $timingShock   Purchases being brought forward, a share of GDP a year.
     * @param float $dt            Time step in years.
     */
    public static function spendingPulledForwardAfter(float $pulledForward, float $timingShock, float $dt): float
    {
        return TimeSeries::calculateDistributedLag($pulledForward, $timingShock / self::SPENDING_TIMING_PAYBACK_SPEED, $dt, 1.0 / self::SPENDING_TIMING_PAYBACK_SPEED);
    }

    /**
     * Inventory investment over a step, a share of GDP a year: the change in the inventory-to-sales overhang with the
     * target held, times the ratio, is the stock built out of a year's domestic sales, which reach GDP on domestic
     * demand's weight. The target's own moves shift the overhang, not the stock, and are no investment.
     *
     * @param float $overhangChange Change in the inventory-to-sales overhang over the step, the target held.
     * @param float $dt             Time step in years.
     */
    public static function inventoryInvestmentAt(float $overhangChange, float $dt): float
    {
        return OutputGap::DOMESTIC_GAP_WEIGHT * self::INVENTORY_TO_ANNUAL_SALES * $overhangChange / $dt;
    }

    /**
     * Inventory investment the stock's motion implies with production on plan: the miss on expected sales going into
     * stock less the overhang being worked off. A state predating inventory investment as a level part opens with it.
     *
     * @param MacroState $state Current macroeconomic state.
     */
    public static function plannedInventoryInvestment(MacroState $state): float
    {
        $missFlow = self::INVENTORY_SURPRISE_SENSITIVITY * ($state->domesticDemandGapEma - OutputGap::domesticDemandGap($state));

        return OutputGap::DOMESTIC_GAP_WEIGHT * ($missFlow - (self::INVENTORY_ADJUSTMENT_SPEED * self::INVENTORY_TO_ANNUAL_SALES * $state->inventoryStockGap));
    }
}
