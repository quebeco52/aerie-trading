<?php

declare(strict_types=1);

namespace App\Service\Model\Strategy;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;

interface ValuationStrategyInterface
{
    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue, ?float $fcfPerShare, float $liveWacc, MathUtility $mathUtility): float;
    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float;
    /**
     * @param float|null $investedCapitalPerShare The firm's real capital employed per share when the caller has
     *                                            it; null falls back to a structural approximation from revenue
     *                                            and book value for callers that do not carry a balance sheet.
     */
    public function calculateStructuralEps(float $bookValuePerShare, float $structuralRoic, float $revenuePerShare, float $riskFreeRate, ?float $investedCapitalPerShare = null): float;
    public function calculateStructuralRoic(float $roicTtm, float $baselineRoic, float $revenuePerShare, float $bookValuePerShare, float $baselineMargin): float;

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
