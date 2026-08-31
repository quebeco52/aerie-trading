<?php

declare(strict_types=1);

namespace App\Service\Model\Strategy;

use App\Entity\Stock;
use App\Service\Math\MathUtility;

interface ValuationStrategyInterface
{
    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue, ?float $fcfPerShare, float $liveWacc, MathUtility $mathUtility): float;
    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float;
    public function calculateStructuralEps(float $bookValuePerShare, float $structuralRoic, float $revenuePerShare, float $riskFreeRate): float;
    public function calculateStructuralRoic(float $roicTtm, float $baselineRoic, float $revenuePerShare, float $bookValuePerShare, float $baselineMargin): float;
}
