<?php
declare(strict_types=1);

namespace App\Service\Model\Trait;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Math\FinancialConstants;

trait StandardValuationTrait
{
    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue, ?float $fcfPerShare, float $liveWacc, MathUtility $mathUtility): float
    {
        if ($fcfPerShare !== null && $fcfPerShare > 0.0) {
            $terminalGrowth = defined('static::DCF_TERMINAL_GROWTH_RATE') ? static::DCF_TERMINAL_GROWTH_RATE : FinancialConstants::DEFAULT_PERPETUAL_GROWTH_RATE;
            $maxDcfCap = defined('static::MAX_DCF_TO_PE_CAP_MULT') ? static::MAX_DCF_TO_PE_CAP_MULT : 1.50;
            $multiplier = $mathUtility->calculateDcfMultiplier($liveWacc, $terminalGrowth);
            $annualFcf = $fcfPerShare;
            // Cap the DCF so a temporary lack of CapEx doesn't cause an infinite perpetual valuation.
            $dcfFairValue = min(max(0.01, $annualFcf * $multiplier), $peFairValue * $maxDcfCap);
            return ($peFairValue + $dcfFairValue) / 2.0;
        }
        // Negative FCF (capex burn, R&D burn) discounts the earnings multiple, never the revenue floor:
        // the floor is the liquidation-style value a buyer pays for the top line regardless of current cash burn.
        $discount = defined('static::NEGATIVE_FCF_VAL_DISCOUNT') ? (float) static::NEGATIVE_FCF_VAL_DISCOUNT : 0.75;
        return $fcfPerShare !== null ? max($revenueFloorValue, $peFairValue * $discount) : max($revenueFloorValue, $peFairValue);
    }

    /**
     * A living company rarely trades below 0.4x book unless bankruptcy is imminent, and the multiple it
     * earns above that is the ratio of the return it makes on its capital to the return that capital is
     * required to make. A model whose book is a portfolio rather than plant declares its own instead.
     */
    public function getIntrinsicPbMultiple(float $structuralRoic, float $hurdleRate): float
    {
        return max(
            FinancialConstants::MIN_INTRINSIC_PB,
            min(FinancialConstants::MAX_INTRINSIC_PB, $structuralRoic / max(0.01, $hurdleRate))
        );
    }

    /** An operating company carries no standing discount: its fair value is already what it is worth. */
    public function getStructuralValuationDiscount(MacroStateDTO $macroState): float
    {
        return 0.0;
    }

    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float
    {
        $baseConsensus = ($earningsValue * FinancialConstants::FAIR_VALUE_EARNINGS_WEIGHT) + ($pbFairValue * FinancialConstants::FAIR_VALUE_BOOK_WEIGHT);
        return $dividendSupportValue > 0.0
            ? ($baseConsensus * (1.0 - FinancialConstants::FAIR_VALUE_DDM_WEIGHT)) + ($dividendSupportValue * FinancialConstants::FAIR_VALUE_DDM_WEIGHT)
            : $baseConsensus;
    }

    public function calculateStructuralEps(float $bookValuePerShare, float $structuralRoic, float $revenuePerShare, float $riskFreeRate, ?float $investedCapitalPerShare = null): float
    {
        // For non-financials, Structural ROIC applies to Invested Capital, not Equity (Book Value). The real
        // figure is used when the caller has one; the revenue-and-book approximation remains only for
        // callers without a balance sheet in hand. With real capital the implied debt below becomes the
        // firm's actual net debt, so the interest drag is charged on what it genuinely owes.
        $investedCapitalPerShare ??= max($revenuePerShare * 0.5, $bookValuePerShare * 1.5);
        $investedCapitalPerShare = max(0.01, $investedCapitalPerShare);

        // NOPAT = Invested Capital * ROIC
        $structuralNopat = $investedCapitalPerShare * $structuralRoic;

        // Structural after-tax interest expense drag for levered capital structure
        $impliedDebt = max(0.0, $investedCapitalPerShare - $bookValuePerShare);
        $costOfDebt = $riskFreeRate + 0.02;
        $structuralInterestExpense = $impliedDebt * $costOfDebt * (1.0 - 0.21);

        $structuralOperatingEps = max(0.0, $structuralNopat - $structuralInterestExpense);

        $cashPerShare = $this->calculateTargetOperatingCash($investedCapitalPerShare, 0.0, 0.0);
        $structuralCashYieldEps = $cashPerShare * $riskFreeRate;

        return max(0.01, $structuralOperatingEps + $structuralCashYieldEps);
    }

    public function calculateStructuralRoic(float $roicTtm, float $baselineRoic, float $revenuePerShare, float $bookValuePerShare, float $baselineMargin): float
    {
        return $roicTtm;
    }
}
