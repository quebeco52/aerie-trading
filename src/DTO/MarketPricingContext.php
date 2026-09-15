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
        public float $sectorZ = 0.0,
        public float $marketJumpMultiplier = 1.0,
        public float $marketVol = 0.15,
        public float $reversionSpeed = 0.25,
        public float $kappa = \App\Service\Market\MarketEngine::BASE_VARIANCE_REVERSION_SPEED,
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
        public float $liveCostOfEquity = 0.10,
        public float $netDebtPerShare = 0.0,
        public float $recentPriceTrend = 0.0,
        public float $secularGrowth = 0.02,
        public float $baselineRoic = 0.10,
        public float $baselineMargin = 0.20,
        public float $accrualsRatio = 0.0,
        /** Capital actually employed per share (equity + debt + deferred tax - cash); 0 when the caller has no balance sheet. */
        public float $investedCapitalPerShare = 0.0,
        /**
         * Annualized variance this name's order flow has actually been supplying, from the measured EMA.
         *
         * The diffusion gives back exactly this much, so real flow and the reduced-form process it stands
         * in for are not both counted. Zero for a name nobody trades, which is the correct answer: its
         * calibrated volatility is left alone.
         */
        public float $orderFlowVariance = 0.0
    ) {}
}
