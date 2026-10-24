<?php

declare(strict_types=1);

namespace App\Service\Corporate\Earnings;

use App\DTO\EarningsSimulationContext;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;

/**
 * The trade cycle's balances: receivables, inventory and payables rebuilt each quarter from the cash conversion cycle,
 * and the two that can go bad, inventory marked to net realisable value and receivables provisioned for expected
 * credit losses.
 */
final class WorkingCapitalLedger
{
    // --- Inventory & Receivable Impairment ---
    /**
     * Capacity utilization below which unsold inventory starts failing the lower-of-cost-or-net-realizable-value test
     * (ASC 330). Calibrated to this engine's utilization scale, which centres on 1.0 rather than the ~80% of the
     * published manufacturing series.
     */
    public const INVENTORY_NRV_UTILIZATION_TRIGGER = 0.95;
    /** Fraction of inventory written off at total demand collapse; scaled by how far utilization has fallen. */
    public const INVENTORY_NRV_LOSS_RATE = 0.25;
    /**
     * Maximum share of the existing allowance that can be released in one quarter, so a recovery cannot be booked as
     * instant profit.
     */
    public const MAX_ALLOWANCE_RELEASE_RATIO = 0.25;

    public function __construct(
        private readonly CorporateMetrics $corporateMetrics,
    ) {}

    /**
     * Rebuilds the working capital balances from the cash conversion cycle and returns the new net figure.
     *
     * Carried as real balances, receivables and inventory can be impaired: a customer stops paying, or goods sit
     * unsold until they are worth less than they cost. Both are ordinary recession charges and neither is
     * forecastable, which is why they show up as misses.
     *
     * Receivables scale with revenue (they are billed sales); inventory and payables scale with the cost
     * base (they are carried at cost, not at what the firm hopes to sell them for).
     */
    public function rollForwardWorkingCapitalLedger(EarningsSimulationContext $ctx, float $annualizedRevenue): float
    {
        $stock = $ctx->stock;

        $baseDays = $ctx->strategy->getWorkingCapitalDays($stock);
        $deseasonalizedUtilization = $ctx->capacityUtilization * $ctx->sectorActivityFactor / max(0.01, $ctx->seasonalFactor);
        $shifts = FirmEconomics::calculateWorkingCapitalDayShifts(
            creditSpread: $ctx->macroState->macroCreditSpreadEma,
            creditSpreadTrend: $ctx->macroState->macroCreditSpreadTrend,
            capacityUtilization: $deseasonalizedUtilization,
            interbankLiquiditySpread: $ctx->macroState->interbankLiquiditySpreadEma,
            interbankLiquiditySpreadTrend: $ctx->macroState->interbankLiquiditySpreadTrend,
        );

        // A macro shift stretches an existing cycle; it cannot conjure one. A firm that carries no inventory
        // does not start accumulating it because demand fell, and a bank with no trade receivables does not
        // acquire some because credit spreads widened. Only components the model actually declares move.
        $shiftDays = static fn(float $base, float $shift): float => $base > 0.0 ? max(0.0, $base + $shift) : 0.0;

        $dso = $shiftDays($baseDays['dso'] ?? 0.0, $shifts['dso'] ?? 0.0);
        $dio = $shiftDays($baseDays['dio'] ?? 0.0, $shifts['dio'] ?? 0.0);
        $dpo = $shiftDays($baseDays['dpo'] ?? 0.0, $shifts['dpo'] ?? 0.0);

        $annualizedCosts = max(0.0, ($ctx->actualVariableCosts + $ctx->fixedCosts) / max(0.001, $ctx->dt));

        // Inventory allowance release: unwinds prior-period inventory allowances as goods turn over.
        $allowance = (float) $stock->getInventoryAllowance();
        $openingAllowance = max(0.0, $allowance - max(0.0, $ctx->inventoryWriteDown));
        if ($openingAllowance > 0.0) {
            $turnover = $dio > 0.0 ? min(1.0, ($ctx->dt * FinancialConstants::DAYS_PER_YEAR) / $dio) : 1.0;
            $stock->setInventoryAllowance((string) max(0.0, $allowance - ($openingAllowance * $turnover)));
        }

        return $this->corporateMetrics->buildWorkingCapitalBalances(
            $stock,
            ['dso' => $dso, 'dio' => $dio, 'dpo' => $dpo],
            $annualizedRevenue,
            $annualizedCosts,
        );
    }

    /**
     * Impairs the working capital balances that can go bad.
     *
     * Inventory (ASC 330, lower of cost and net realizable value): when the plant is running well below
     * capacity the goods are not moving, and stock that has to be cleared goes out below cost. The charge
     * scales with how far demand has fallen.
     *
     * Receivables (ASC 326, expected credit losses): the allowance is a level, not a flow, so a provision is
     * booked when the expected loss rate RISES and released slowly when it falls. Modelling it as a flow
     * would charge a firm every quarter of a downturn rather than at the point the outlook deteriorates.
     *
     * Both are non-cash and neither is in the analyst forecast, so they land as negative surprises. They
     * are deducted after consensus is formed for exactly that reason. The cash a write-down foretells, the
     * replacement of the impaired stock, leaves later through the working capital roll-forward as the
     * allowance unwinds.
     */
    public function applyWorkingCapitalCharges(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        if (!$stock->hasWorkingCapitalLedger()) {
            return; // No ledger yet (first report, or a balance-sheet business that keeps no trade cycle).
        }

        $ctx->inventoryWriteDown = $this->resolveInventoryWriteDown($ctx);
        $ctx->receivablesProvision = $this->resolveReceivablesProvision($ctx);

        $totalCharge = $ctx->inventoryWriteDown + $ctx->receivablesProvision;
        if ($totalCharge === 0.0) {
            return;
        }

        // Both charges sit in operating expense, so they reduce EBITDA and EBIT alike. Analysts do not
        // forecast them, so expectedEbit is deliberately left untouched.
        $ctx->ebitda -= $totalCharge;
        $ctx->ebit -= $totalCharge;
        $ctx->impairmentCharges += $totalCharge;
    }

    /**
     * Lower-of-cost-or-net-realizable-value writedown on inventory the firm cannot move (ASC 330).
     *
     * Charged against the carrying value, so stock already written down is not written down again, and held
     * as an allowance against the gross balance rather than cut from it, since the gross figure is rebuilt from the
     * trade cycle every quarter. The allowance unwinds in the roll-forward as the
     * impaired stock turns, which is when the replacement cash actually leaves.
     */
    private function resolveInventoryWriteDown(EarningsSimulationContext $ctx): float
    {
        $inventory = $ctx->stock->getNetInventory();
        if ($inventory <= 0.0) {
            return 0.0;
        }

        $trigger = self::INVENTORY_NRV_UTILIZATION_TRIGGER;
        $utilization = $ctx->capacityUtilization * $ctx->sectorActivityFactor / max(0.01, $ctx->seasonalFactor);
        if ($utilization >= $trigger) {
            return 0.0;
        }

        // Stock still maturing is sold years on, after the slump: only the goods on sale now are marked down.
        $exposed = $inventory * (1.0 - $ctx->strategy->getMaturingInventoryShare($ctx->stock));
        $severity = min(1.0, ($trigger - $utilization) / $trigger);
        $charge = $exposed * self::INVENTORY_NRV_LOSS_RATE * $severity;

        $ctx->stock->setInventoryAllowance((string) ((float) $ctx->stock->getInventoryAllowance() + $charge));

        return $charge;
    }

    /** Expected credit loss allowance on trade receivables, provisioned to a level (ASC 326). */
    private function resolveReceivablesProvision(EarningsSimulationContext $ctx): float
    {
        $receivables = (float) ($ctx->stock->getReceivables() ?? 0.0);
        if ($receivables <= 0.0) {
            return 0.0;
        }

        $defaultRate = max(0.0, min(1.0, $ctx->macroState->corporateDefaultRateEma));
        $targetAllowance = $receivables * $defaultRate * FinancialConstants::TRADE_RECEIVABLE_LGD;
        $currentAllowance = (float) $ctx->stock->getReceivablesAllowance();

        $provision = $targetAllowance - $currentAllowance;
        if ($provision < 0.0) {
            // A recovery is released gradually: an improving outlook is not instant profit.
            $provision = -min(abs($provision), $currentAllowance * self::MAX_ALLOWANCE_RELEASE_RATIO);
        }

        $ctx->stock->setReceivablesAllowance((string) max(0.0, $currentAllowance + $provision));

        return $provision;
    }
}
