<?php
declare(strict_types=1);

namespace App\Service\Model\Trait;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Math\FinancialConstants;

trait StandardValuationTrait
{
    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float {
        // Assume FAIR_VALUE_EARNINGS_WEIGHT is 0.90, FAIR_VALUE_BOOK_WEIGHT is 0.10, FAIR_VALUE_DDM_WEIGHT is 0.15
        $baseConsensus = ($earningsValue * 0.90) + ($pbFairValue * 0.10);
        return $dividendSupportValue > 0.0
            ? ($baseConsensus * (1.0 - 0.15)) + ($dividendSupportValue * 0.15)
            : $baseConsensus;
    }
}
