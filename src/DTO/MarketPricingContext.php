<?php

declare(strict_types=1);

namespace App\DTO;

use App\Data\Sectors;
use App\Entity\Stock;
use App\Service\Corporate\Holdings\AnchorStakeLedger;

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
        public float $orderFlowVariance = 0.0,
        /** Book equity less goodwill, per share; null when the caller has none, and the P/B leg then reads book. */
        public ?float $tangibleBookValuePerShare = null,
        /** The payout ratio the firm's dividend policy steers to; zero for a firm with no dividend policy. */
        public float $targetPayoutRatio = 0.0,
        /** Share of the gap to its target dividend the firm closes each quarter (Lintner 1956). */
        public float $dividendAdjustmentSpeed = 1.0
    ) {}

    /**
     * A listed firm as MarketEngine prices it: the one mapping from a firm to its pricing inputs. The ticker prices the
     * board through it and the company page prices its analyst view through it, so a page cannot strike a firm on
     * fundamentals the market is not using. A dt of zero prices the firm without advancing its path; the ticker passes
     * its step and any deal shock struck this tick.
     *
     * @param AnchorStakeLedger $anchorStakes The caller's ledger, primed with the board a sphere's holdings are marked on.
     */
    public static function forStock(
        Stock $stock,
        MacroStateDTO $macroState,
        DebtHealthDTO $health,
        AnchorStakeLedger $anchorStakes,
        float $dt = 0.0,
        float $maShock = 0.0
    ): self {
        $strategy = Sectors::strategyFor($stock->getIndustry());
        $shares = max(1.0, (float) $stock->getSharesOutstanding());
        $baselineVolatility = (float) $stock->getVolatility();

        return new self(
            currentPrice: (float) $stock->getPrice(),
            currentVolatility: (float) ($stock->getCurrentVolatility() ?? $baselineVolatility),
            longTermVolatility: $baselineVolatility,
            // Fair value EPS basis: deduct guided shortfall from EPS until official quarterly report.
            earningsPerShare: (float) $stock->getEarningsPerShare() - ($stock->getPreAnnouncedShortfall() / $shares),
            dt: $dt,
            lambda: (float) $stock->getJumpIntensity(),
            jumpVol: (float) $stock->getJumpVol(),
            // DebtEngine levers the firm's own signed beta through Hamada, so an inverse hedge stays inverse.
            beta: $health->leveredBeta,
            marketZ: $macroState->marketZ,
            sectorZ: (float) ($macroState->sectorZ[$stock->getSector()] ?? 0.0),
            marketJumpMultiplier: $macroState->marketJumpMultiplier,
            marketVol: $macroState->marketVolatility,
            macroState: $macroState,
            // A sphere's filed book moves once a quarter; the listed portfolio inside it moves every
            // tick, and fair value is struck on the second.
            bookValuePerShare: $anchorStakes->resolveMarkedBookValuePerShare($stock) ?? (float) $stock->getBookValuePerShare(),
            maShock: $maShock,
            currentRoic: $strategy->getEffectiveReturn($stock),
            roicTtm: $strategy->getTrueReturn($stock),
            dividendPerShare: (float) $stock->getLastDividend(),
            liveWacc: $health->wacc,
            baselineIndustryPE: Sectors::baselineIndustryPe($stock->getIndustry()),
            revenuePerShare: (float) $stock->getTotalRevenue() / $shares,
            businessModel: Sectors::businessModelFor($stock->getIndustry()),
            liveCostOfEquity: $health->costOfEquity,
            // The model's own net debt, as DebtEngine reads it: a lender's deposits and a clearinghouse's margin fund
            // its book rather than finance it, so they are no claim ahead of the equity.
            netDebtPerShare: max(0.0, $strategy->getNetDebtCapital((float) $stock->getTotalDebt(), (float) $stock->getWholesaleDebt(), (float) $stock->getCorporateTreasury())) / $shares,
            recentPriceTrend: (float) ($stock->getPriceMomentumTrend() ?? 0.0),
            secularGrowth: $strategy->getSecularGrowthRate($stock),
            // The anchor the firm's own model measures it by: a lender or underwriter carries a placeholder in
            // baselineRoic, and its through-the-cycle return is its ROE.
            baselineRoic: $strategy->getBaselineReturn($stock),
            baselineMargin: (float) ($stock->getOperatingMargin() ?? 0.20),
            accrualsRatio: (float) ($stock->getAccrualsRatio() ?? 0.0),
            investedCapitalPerShare: $stock->getInvestedCapital() / $shares,
            orderFlowVariance: (float) ($stock->getImpactVarianceEma() ?? 0.0),
            tangibleBookValuePerShare: $stock->getTangibleEquity() / $shares,
            targetPayoutRatio: $stock->getPolicyPayoutRatio(),
            dividendAdjustmentSpeed: (float) $stock->getDividendSpeed()
        );
    }
}
