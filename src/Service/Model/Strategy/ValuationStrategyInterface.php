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
}
