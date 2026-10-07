<?php
declare(strict_types=1);

namespace App\Service\Model\Trait;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Math\FinancialConstants;

trait StandardValuationTrait
{
    /**
     * The value-driver multiple already is the discounted cash flow: NOPAT x (1 - g/RONIC) / (WACC - g) (Koller,
     * Goedhart & Wessels, "Valuation"), the free cash flow left once growth has been funded. Realised free cash flow
     * adds no second estimate: one quarter's figure, net of lumpy growth capex, capitalised at a generic growth rate,
     * turned every capex cycle into a valuation swing and priced reinvestment as if it earned nothing.
     */
    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue): float
    {
        return max($revenueFloorValue, $peFairValue);
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

    /**
     * The consensus fair value: earnings value and book value blended at the model's book weight, declared through
     * getFairValueBookWeight(). The dividend is not a further input: what a firm pays out leaves the firm, and for a
     * given investment policy the split between paying and retaining does not change what it is worth (Miller &
     * Modigliani 1961). Only a model whose payout is set for it, by statute or by its regulator, prices on income.
     */
    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float
    {
        $bookWeight = $this->getFairValueBookWeight($normalizedEps);

        return ($earningsValue * (1.0 - $bookWeight)) + ($pbFairValue * $bookWeight);
    }

    /** Weight of book value against earnings value in the consensus, given the firm's normalised earnings. */
    protected function getFairValueBookWeight(float $normalizedEps): float
    {
        return FinancialConstants::FAIR_VALUE_BOOK_WEIGHT;
    }


    public function calculateStructuralEps(float $bookValuePerShare, float $structuralRoic, float $revenuePerShare, float $riskFreeRate, float $afterTaxCostOfDebt, ?float $investedCapitalPerShare = null): float
    {
        // For non-financials, Structural ROIC applies to Invested Capital, not Equity (Book Value). The real
        // figure is used when the caller has one; the revenue-and-book approximation remains only for
        // callers without a balance sheet in hand. With real capital the implied debt below becomes the
        // firm's actual net debt, so the interest drag is charged on what it genuinely owes.
        $investedCapitalPerShare ??= max(
            $revenuePerShare * FinancialConstants::STRUCTURAL_INVESTED_CAPITAL_TO_REVENUE,
            $bookValuePerShare * FinancialConstants::STRUCTURAL_INVESTED_CAPITAL_TO_BOOK
        );
        $investedCapitalPerShare = max(0.01, $investedCapitalPerShare);

        // NOPAT = Invested Capital * ROIC
        $structuralNopat = $investedCapitalPerShare * $structuralRoic;

        // After-tax interest on the debt financing the rest, at the firm's own marginal borrowing rate and tax.
        $impliedDebt = max(0.0, $investedCapitalPerShare - $bookValuePerShare);
        $structuralInterestExpense = $impliedDebt * $afterTaxCostOfDebt;

        $structuralOperatingEps = max(0.0, $structuralNopat - $structuralInterestExpense);

        $cashPerShare = $this->calculateTargetOperatingCash($investedCapitalPerShare, 0.0, 0.0);
        $structuralCashYieldEps = $cashPerShare * $riskFreeRate;

        return max(0.01, $structuralOperatingEps + $structuralCashYieldEps);
    }

    public function calculateStructuralRoic(float $roicTtm, float $baselineRoic, float $revenuePerShare, float $bookValuePerShare, float $baselineMargin): float
    {
        return $roicTtm;
    }

    /** Levered through the identity: an operating return is earned on invested capital, not on equity. */
    public function getEquityReturn(float $returnOnCapital, float $investedCapital, float $equity, float $afterTaxCostOfDebt): float
    {
        return MathUtility::equityReturnFromRoic($returnOnCapital, $investedCapital, $equity, $afterTaxCostOfDebt);
    }

    /** @return array{costOfEquity: float, equityReturn: float} */
    public function getEquityValuationRates(\App\Entity\Stock $stock, float $returnOnCapital, \App\DTO\DebtHealthDTO $health, float $corporateTaxRate): array
    {
        $afterTaxCostOfDebt = $health->rawMetrics->currentMarketRate * (1.0 - $this->getEffectiveTaxRate($corporateTaxRate));

        return [
            'costOfEquity' => $health->costOfEquity,
            'equityReturn' => $this->getEquityReturn($returnOnCapital, $stock->getInvestedCapital(), (float) $stock->getTotalEquity(), $afterTaxCostOfDebt),
        ];
    }
}
