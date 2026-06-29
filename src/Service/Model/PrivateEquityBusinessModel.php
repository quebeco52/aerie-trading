<?php

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Private Equity & Alternative Asset Managers.
 * 
 * Financial Physics:
 * - Base revenue comes from sticky AUM management fees.
 * - Massive volatility comes from "Carried Interest" (performance fees) and deal exits.
 * - Thrives during economic expansions and cheap credit (easy to IPO/sell targets).
 * - Suffers "deal droughts" during recessions when credit freezes and exits are impossible.
 */
class PrivateEquityBusinessModel extends AssetManagementBusinessModel
{
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();

        // Private Equity Volatility:
        // During booms (positive output gap), they exit investments at massive premiums (Carried Interest).
        // During busts (negative output gap), M&A markets freeze and they earn zero performance fees.
        $outputGap = $macroState['output_gap_ema'] ?? ($macroState['output_gap'] ?? 0.0);

        $dealFlowMultiplier = $outputGap > 0.0 ? ($outputGap * 3.0) : ($outputGap * 1.5);

        $actualRevenue = $expectedRevenue * (1.0 + ($revenueZ * ($baselineVol * 0.15)) + $dealFlowMultiplier);
        $actualVariableCosts = $actualRevenue * min(1.50, max(0.01, $realizedVariableMargin));

        $eventLore = null;
        if ($outputGap > 0.02 && $revenueZ > 1.5) {
            $eventLore = "Generated massive carried interest fees following a series of highly successful portfolio exits.";
        } elseif ($outputGap < -0.02 && $revenueZ < -1.5) {
            $eventLore = "Suffered a severe deal drought as frozen credit markets prevented portfolio exits.";
        }

        return [
            'actual_revenue' => $actualRevenue,
            'actual_variable_costs' => $actualVariableCosts,
            'ebit' => $actualRevenue - $fixedCosts - $actualVariableCosts,
            'primary_shock_z' => $revenueZ,
            'event_lore' => $eventLore
        ];
    }

    public function calculateMaxBuybackSpend(float $excessCash, float $retainedEarningsThisQuarter, bool $isMegaHoarder): float
    {
        // Private Equity uses extreme leverage. We must cap normal buybacks to recent earnings to prevent them from hollowing out their equity base.
        return $isMegaHoarder ? $excessCash * 0.30 : min($excessCash * 0.10, $retainedEarningsThisQuarter);
    }

    public function calculateOrganicCapexSpend(float $organicSpend, float $debtIssued): float
    {
        // PE firms constantly inject capital into their portfolio companies (bolt-on acquisitions, restructuring costs).
        return max($organicSpend, $debtIssued * 0.90);
    }

    public function getDebtExpansionAggressiveness(float $spreadMultiplier): array
    {
        // Highly aggressive borrowing to fuel buyouts and portfolio injections
        return [
            'probability' => 0.70 + ($spreadMultiplier * 0.20),
            'aggressiveness' => 0.10 + (0.30 * $spreadMultiplier)
        ];
    }

    public function evaluateHoardingStatus(float $treasury, float $targetCashReserves, float $operatingBase, float $totalDebt): array
    {
        $excessCash = max(0.0, $treasury - $targetCashReserves);
        return [
            'excess_cash'     => $excessCash,
            // Private Equity holds cash to deploy into leveraged buyouts.
            // We evaluate their hoard status against their massive debt load, not their base revenue.
            'is_hoarder'      => $excessCash > ($totalDebt * 0.15),
            'is_mega_hoarder' => $excessCash > ($totalDebt * 0.30),
        ];
    }

    public function calculateTargetOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return max($operatingBase * 0.10, $wholesaleDebt * 0.10);
    }

    public function calculateMinOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return max($operatingBase * 0.05, $wholesaleDebt * 0.05);
    }

    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate): float
    {
        $equity = (float) $stock->getTotalEquity();
        $truePostTaxReturn = $equity > 0 ? ($actualTotalNetIncome / $equity) * 4.0 : 0.0;

        $stock->setCurrentRoe((string) max(-0.50, min(1.0, $truePostTaxReturn)));

        // Private Equity earnings are extremely lumpy due to massive, infrequent deal exits (Carried Interest).
        // Use a 0.20 smoothing factor to prevent violent P/E whipsaws during deal droughts.
        $oldTtm = (float) $stock->getRoeTtm();
        $newTtm = $oldTtm === 0.0 ? $truePostTaxReturn : ($truePostTaxReturn * 0.20) + ($oldTtm * 0.80);
        $stock->setRoeTtm((string) max(-0.50, min(1.0, $newTtm)));

        return $truePostTaxReturn;
    }
}
