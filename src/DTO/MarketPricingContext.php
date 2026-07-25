<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Context object holding all state required to calculate the next market price tick.
 */
class MarketPricingContext
{
    public function __construct(
        public readonly float $currentPrice,
        public readonly float $currentVolatility,
        public readonly float $longTermVolatility,
        public readonly float $earningsPerShare,
        public readonly float $dt,
        public float $lambda = 2.0,
        public float $jumpVol = 0.05,
        public float $beta = 1.0,
        public float $marketZ = 0.0,
        public float $marketVol = 0.15,
        public float $drift = 0.08,
        public float $reversionSpeed = 0.25,
        public float $kappa = 6.0,
        public float $volOfVol = 0.3,
        public ?MacroStateDTO $macroState = null,
        public ?float $fcfPerShare = null,
        public float $bookValuePerShare = 0.0,
        public float $maShock = 0.0,
        public float $currentRoic = 0.10,
        public float $roicTtm = 0.10,
        public float $dividendPerShare = 0.0,
        public float $liveWacc = 0.08,
        public float $baselineIndustryPE = 20.0,
        public float $revenuePerShare = 0.0,
        public string $businessModel = 'none',
        public float $liveCostOfEquity = 0.10
    ) {}
}
