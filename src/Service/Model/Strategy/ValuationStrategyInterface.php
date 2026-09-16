<?php

declare(strict_types=1);

namespace App\Service\Model\Strategy;

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
     * A standing discount applied to the assembled fair value, as a fraction of it.
     *
     * Zero for an operating company: its fair value is already what the business is worth. A closed-end
     * structure is the exception — its shares persistently trade below the assets they represent, and the
     * gap moves with the cycle rather than with anything the firm did.
     *
     * @param float $outputGap The macro output gap. Deliberately not the firm's own volatility, which would
     *                         let a widening discount raise the volatility that widened it.
     */
    public function getStructuralValuationDiscount(float $outputGap): float;
}
