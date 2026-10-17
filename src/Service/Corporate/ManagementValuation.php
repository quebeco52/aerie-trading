<?php

declare(strict_types=1);

namespace App\Service\Corporate;

use App\Data\Company\Sectors;
use App\DTO\DebtHealthDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\Valuation;
use App\Service\Model\BusinessModelInterface;

/**
 * The multiple a firm's board holds its own stock to be worth. The buyback, issuance and M&A engines all compare the
 * live P/E against it, so it is struck in one place, the way the market strikes it: the cost of equity on the return
 * on equity, shrunk toward the industry's baseline multiple (Sectors::baselineIndustryPe()).
 */
final class ManagementValuation
{
    public static function fairValuePE(BusinessModelInterface $strategy, Stock $stock, float $trueReturn, DebtHealthDTO $health, MacroStateDTO $macroState): float
    {
        $longRunReturn = $stock->getLongRunReturn() !== null ? (float) $stock->getLongRunReturn() : null;
        $valuationReturn = $strategy->getValuationReturn($trueReturn, $longRunReturn, $health->costOfEquity);
        $equityRates = $strategy->getEquityValuationRates($stock, $valuationReturn, $health, $macroState->corporateTaxRate);

        return Valuation::calculateManagementFairValuePE(
            $equityRates['costOfEquity'],
            $equityRates['equityReturn'],
            $strategy->getFadedSecularGrowthRate($stock, $macroState->totalTime),
            $macroState->outputGap,
            $health->leveredBeta,
            $macroState->tipsBreakeven,
            Sectors::baselineIndustryPe($stock->getIndustry()),
            (float) ($stock->getAccrualsRatio() ?? 0.0),
            $stock->getPolicyPayoutRatio(),
        );
    }
}
