<?php

declare(strict_types=1);

namespace App\Service\Corporate;

use App\Data\Company\Sectors;
use App\DTO\DebtHealthDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\Earnings\CapitalBudgetEngine;
use App\Service\Corporate\Segment\SegmentBooks;
use App\Service\Math\Valuation;
use App\Service\Model\BusinessModelInterface;

/**
 * The multiple a firm's board holds its own stock to be worth, and the earnings it is struck on. The buyback, issuance
 * and M&A engines all compare the price over those earnings against the multiple, so both are struck in one place, the
 * way the market strikes them: the cost of equity on the return on equity, shrunk toward the industry's baseline
 * multiple (Sectors::baselineIndustryPe()).
 */
final class ManagementValuation
{
    public static function fairValuePE(BusinessModelInterface $strategy, Stock $stock, float $trueReturn, DebtHealthDTO $health, MacroStateDTO $macroState): float
    {
        $equityRates = self::equityRates($strategy, $stock, $trueReturn, $health, $macroState);

        return Valuation::calculateManagementFairValuePE(
            $equityRates['costOfEquity'],
            $equityRates['equityReturn'],
            SegmentBooks::fadedSecularGrowth($strategy, $stock, $macroState->totalTime),
            $macroState->outputGap,
            $health->leveredBeta,
            $macroState->tipsBreakeven,
            Sectors::baselineIndustryPe($stock->getIndustry()),
            (float) ($stock->getAccrualsRatio() ?? 0.0),
            $stock->getPolicyPayoutRatio(),
            // The catch-up to its expected market, read off the gap its last report filed (and, plant kept by segment,
            // the spare plant filed apart), as the market reads it.
            Valuation::capacityCatchUpGrowth(
                (float) (($stock->getEarningsMomentumZ() ?? [])[CapitalBudgetEngine::STATE_CAPACITY_GAP] ?? 0.0),
                CapitalBudgetEngine::CAPITAL_ERROR_CORRECTION_SPEED,
                $equityRates['costOfEquity'] - $macroState->tipsBreakeven,
            ) + Valuation::capacityCatchUpGrowth(
                (float) (($stock->getEarningsMomentumZ() ?? [])[CapitalBudgetEngine::STATE_CAPACITY_SPARE] ?? 0.0),
                CapitalBudgetEngine::CAPITAL_ERROR_CORRECTION_SPEED,
                $equityRates['costOfEquity'] - $macroState->tipsBreakeven,
            ),
        );
    }

    /**
     * The annual earnings per share the board's multiple is struck on: its return on equity at the valuation return, on
     * book (Ohlson 1995), so a quarter's loss or windfall moves it only as far as it moves the persistent part of the
     * firm's return. A firm with no book has no earnings to price.
     */
    public static function normalizedEps(BusinessModelInterface $strategy, Stock $stock, float $trueReturn, DebtHealthDTO $health, MacroStateDTO $macroState): float
    {
        $equityReturn = self::equityRates($strategy, $stock, $trueReturn, $health, $macroState)['equityReturn'];

        return $equityReturn * (float) $stock->getTotalEquity() / max(1.0, (float) $stock->getSharesOutstanding());
    }

    /** @return array{costOfEquity: float, equityReturn: float} */
    private static function equityRates(BusinessModelInterface $strategy, Stock $stock, float $trueReturn, DebtHealthDTO $health, MacroStateDTO $macroState): array
    {
        $longRunReturn = $stock->getLongRunReturn() !== null ? (float) $stock->getLongRunReturn() : null;
        $valuationReturn = $strategy->getValuationReturn($trueReturn, $longRunReturn, $health->costOfEquity);

        return $strategy->getEquityValuationRates($stock, $valuationReturn, $health, $macroState->corporateTaxRate);
    }
}
