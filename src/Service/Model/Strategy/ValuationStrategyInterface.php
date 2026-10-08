<?php

declare(strict_types=1);

namespace App\Service\Model\Strategy;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;

interface ValuationStrategyInterface
{
    /**
     * The earnings leg of fair value: the value-driver P/E value, bounded below by what the model's revenue floor
     * is worth. The multiple already capitalises the cash flow the firm's growth leaves after reinvestment.
     */
    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue): float;
    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float;
    /**
     * @param float|null $investedCapitalPerShare The firm's real capital employed per share when the caller has
     *                                            it; null falls back to a structural approximation from revenue
     *                                            and book value for callers that do not carry a balance sheet.
     */
    public function calculateStructuralEps(float $bookValuePerShare, float $structuralRoic, float $revenuePerShare, float $riskFreeRate, float $afterTaxCostOfDebt, ?float $investedCapitalPerShare = null): float;

    /**
     * The return the firm earns on its EQUITY, which is what a P/E on levered earnings and a P/B on book
     * equity are struck against at the cost of equity (Damodaran 2012, Investment Valuation, ch. 18-20).
     */
    public function getEquityReturn(float $returnOnCapital, float $investedCapital, float $equity, float $afterTaxCostOfDebt): float;

    /**
     * The (cost of equity, return on equity) pair a firm's equity multiple is struck on, from its own balance
     * sheet and debt book: what management values its shares at, the same way the market does.
     *
     * @return array{costOfEquity: float, equityReturn: float}
     */
    public function getEquityValuationRates(\App\Entity\Stock $stock, float $returnOnCapital, \App\DTO\DebtHealthDTO $health, float $corporateTaxRate): array;
    public function calculateStructuralRoic(float $roicTtm, float $baselineRoic, float $revenuePerShare, float $bookValuePerShare, float $baselineMargin): float;

    /**
     * The return the firm's equity is valued on, from its trailing return and its measured long-run level, discounted at
     * the given rate; the market and management both strike their multiple on it.
     */
    public function getValuationReturn(float $trailingReturn, ?float $longRunReturn, float $discountRate): float;

    /**
     * The intrinsic price-to-book multiple applied to book value per share.
     *
     * An operating company's book is plant and working capital, worth what the returns it earns justify,
     * which is the ROIC-over-hurdle ratio the standard implementation returns. A model whose book is
     * something else — a portfolio of marketable stakes carried at value — declares its own.
     */
    public function getIntrinsicPbMultiple(float $structuralRoic, float $hurdleRate): float;

    /**
     * A standing discount applied to the assembled fair value. Zero for an operating company, whose fair
     * value is already what the business is worth; a closed-end structure is the exception, trading below
     * the assets it represents with a gap that moves on the cycle and on sentiment.
     *
     * Takes the macro state and never the firm's own volatility: a discount that widened on realised
     * volatility would raise the volatility that widened it, with no damping in the loop.
     */
    public function getStructuralValuationDiscount(MacroStateDTO $macroState): float;
}
