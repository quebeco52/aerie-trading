<?php

namespace App\Service\Market;

use App\Entity\Stock;
use App\DTO\MacroStateDTO;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Corporate\CorporateActionEngine;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\MergerAndAcquisitionEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Market\Flow\OrderFlowStoreInterface;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;

/**
 * Service responsible for tracking and updating stock prices.
 *
 * This service orchestrates the market simulation for individual stocks.
 * It calculates new prices based on market conditions, handles random market shocks,
 * processes earnings events, and persists historical data.
 */
class StockTracker
{
    // --- Price Momentum (Jegadeesh & Titman 1993) ---
    /** Formation horizon in years of the exponentially weighted price trend, matching the 6-month window where momentum is strongest. */
    private const MOMENTUM_FORMATION_YEARS = 0.50;
    /** Absolute cap on the accumulated trend, bounding how far momentum can delay fundamental mean reversion. */
    private const MAX_MOMENTUM_TREND = 0.50;

    /**
     * Constructor.
     *
     * @param EntityManagerInterface $entityManager The Doctrine entity manager.
     * @param MarketEngine $marketEngine Engine for calculating stock price movements.
     * @param EarningsEngine $earningsEngine Engine for processing quarterly earnings reports.
     * @param CorporateActionEngine $corporateActionEngine Engine for handling corporate actions like stock splits.
     * @param MarketEventPublisher $eventService Publisher for market events, shocks, and headlines.
     * @param MathUtility $mathUtility Utility for generating standard normal distributions.
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MarketEngine $marketEngine,
        private EarningsEngine $earningsEngine,
        private CorporateActionEngine $corporateActionEngine,
        private MergerAndAcquisitionEngine $maEngine,
        private MarketEventPublisher $eventService,
        private DebtEngine $debtEngine,
        private MathUtility $mathUtility,
        private CorporateMetrics $corporateMetrics,
        private LiquidityEngine $liquidityEngine,
        private OrderFlowStoreInterface $orderFlow
    ) {}

    /**
     * Updates the prices and states of a collection of stocks for a single time step.
     *
     * This method orchestrates the full market simulation loop for each stock, including:
     * - Calculating the next price and volatility using the MarketEngine.
     * - Checking for and processing random market shocks.
     * - Evaluating potential quarterly earnings reports.
     * - Processing corporate actions like stock splits or reverse splits.
     * - Optionally recording the historical price data.
     *
     * @param Stock[] $stocks        Array of Stock entities to update.
     * @param float   $dt            The time step delta (e.g., in years).
     * @param bool    $recordHistory Whether to persist the new prices to the stock history table.
     * @param MacroStateDTO|null $macroState    The current state of the macroeconomic cycle.
     * 
     * @return array{updates: array<mixed>, total_cap: float, events: array<mixed>, market_vol: float, history: array<mixed>}
     */
    public function updateStocks(array $stocks, float $dt, bool $recordHistory, ?\App\DTO\MacroStateDTO $macroState = null, int $tickCount = 0, int $ticksPerYear = 252): array
    {

        $stockUpdates = [];
        $historyData = [];
        $totalMarketCap = 0.0;
        $events = [];

        // Pull systemic variables from the Macro Engine
        $macroDTO = $macroState ?? new \App\DTO\MacroStateDTO();
        $marketZ = $macroDTO->marketZ;
        $marketVol = $macroDTO->marketVolatility;

        // Everything that traded since the last tick, netted per ticker. Drained once for the whole book
        // rather than per stock: it is one round trip, and a quantity that has already moved the price must
        // not be able to move it again on the next tick.
        $netOrderFlow = $this->orderFlow->drain();

        foreach ($stocks as $stock) {
            $sectorName = $stock->getSector();

            if ($stock->isBankrupt()) {
                $stockUpdates[] = [
                    'ticker' => $stock->getTicker(),
                    'sector' => $sectorName,
                    'industry' => $stock->getIndustry() ?: 'General',
                    'price' => (float) $stock->getPrice(),
                    'market_cap' => (float) $stock->getPrice() * (float) $stock->getSharesOutstanding(),
                    'current_volatility' => (float) ($stock->getCurrentVolatility() ?? $stock->getVolatility()),
                    'current_roic' => (float) $stock->getCurrentRoic(),
                    'current_roe' => (float) $stock->getCurrentRoe(),
                    'shares' => (float) $stock->getSharesOutstanding(),
                    'eps' => (float) $stock->getEarningsPerShare(),
                    'treasury' => (float) $stock->getCorporateTreasury(),
                    'equity' => (float) $stock->getTotalEquity(),
                    'invested_capital' => (float) $stock->getInvestedCapital(),
                    'debt_ratio' => (float) $stock->getDebtToEquityRatio(),
                    'credit_rating' => $stock->getCreditRating(),
                    'analyst_targets' => [],
                    'perceived_fair_value' => 0.0,
                    'is_bankrupt' => true,
                ];
                continue;
            }

            // Determine Volatility
            $baselineVol = (float) $stock->getVolatility();
            $currentVol = (float) ($stock->getCurrentVolatility() ?? $baselineVol);

            // M&A
            $maResult = $this->maEngine->evaluatePrivateAcquisition($stock, $macroDTO, $dt);
            $maShock = 0.0;
            if ($maResult) {
                $events[] = $maResult['event'];
                $maShock = $maResult['shock'];
            }

            // DIVESTITURE (Spin-offs)
            // A company won't acquire and divest in the exact same tick
            if (!$maResult) {
                $divestResult = $this->maEngine->evaluateCorporateDivestiture($stock, $macroDTO, $dt);
                if ($divestResult) {
                    $events[] = $divestResult['event'];
                    $maShock = $divestResult['shock'];
                }
            }

            // Fetch true, dynamic WACC from the DebtEngine
            $health = $this->debtEngine->analyzeDebtHealth($stock, $macroDTO);

            $sharesOutstanding = (float) $stock->getSharesOutstanding();
            $shares = max(1.0, $sharesOutstanding);

            // Fetch the industry limits and structural data
            $industryKey = $stock->getIndustry() ?: 'General';
            $metrics = \App\Data\Sectors::INDUSTRY_METRICS[$industryKey] ?? \App\Data\Sectors::INDUSTRY_METRICS['General'];
            $businessModel = $metrics['business_model'] ?? 'none';
            $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
            $baselineIndustryPE = $metrics['pe'] ?? 20.0;

            // Use the annualized total_revenue from the stock entity directly
            $revenuePerShare = (float) $stock->getTotalRevenue() / $shares;

            $effectiveRoic = $strategy->getEffectiveReturn($stock);
            $roicTtm = $strategy->getTrueReturn($stock);

            $totalDebt = (float) $stock->getTotalDebt();
            $corporateTreasury = (float) $stock->getCorporateTreasury();
            $netDebtPerShare = max(0.0, ($totalDebt - $corporateTreasury) / $shares);

            $secularGrowth = $strategy->getSecularGrowthRate($stock);

            // Leverage re-levers the magnitude of a firm's systematic exposure, never its sign. DebtEngine
            // computes the Hamada beta from max(0.5, |beta|), which would turn an inverse hedge into a
            // market-following name and inflate a 0.10-beta defensive to 0.50, so only the leverage
            // multiplier is recovered and applied to the firm's own beta.
            $rawBeta = (float) $stock->getBeta();
            $hamadaBaseBeta = max(0.5, abs($rawBeta));
            $leverageMultiplier = max(1.0, ($health->leveredBeta ?? $hamadaBaseBeta) / $hamadaBaseBeta);
            $leveredBeta = $rawBeta * $leverageMultiplier;

            $priceAtTickStart = (float) $stock->getPrice();
            $sectorZ = (float) ($macroDTO->sectorZ[$sectorName] ?? 0.0);

            $pricingCtx = new \App\DTO\MarketPricingContext(
                currentPrice: (float) $stock->getPrice(),
                currentVolatility: $currentVol,
                longTermVolatility: $baselineVol,
                earningsPerShare: (float) $stock->getEarningsPerShare(),
                dt: $dt,
                lambda: (float) $stock->getJumpIntensity(),
                jumpVol: (float) $stock->getJumpVol(),
                beta: $leveredBeta,
                marketZ: $marketZ,
                sectorZ: $sectorZ,
                marketJumpMultiplier: $macroDTO->marketJumpMultiplier,
                marketVol: $marketVol,
                macroState: $macroDTO,
                fcfPerShare: $stock->getFreeCashFlowPerShare() !== null ? (float) $stock->getFreeCashFlowPerShare() : null,
                bookValuePerShare: (float) $stock->getBookValuePerShare(),
                maShock: $maShock,
                currentRoic: $effectiveRoic,
                roicTtm: $roicTtm,
                dividendPerShare: (float) $stock->getLastDividend(),
                liveWacc: $health->wacc ?? 0.08,
                baselineIndustryPE: $baselineIndustryPE,
                revenuePerShare: $revenuePerShare,
                businessModel: $businessModel,
                liveCostOfEquity: $health->costOfEquity ?? 0.10,
                netDebtPerShare: $netDebtPerShare,
                recentPriceTrend: (float) ($stock->getPriceMomentumTrend() ?? 0.0),
                secularGrowth: $secularGrowth,
                baselineRoic: (float) ($stock->getBaselineRoic() ?? 0.10),
                baselineMargin: (float) ($stock->getOperatingMargin() ?? 0.20),
                accrualsRatio: (float) ($stock->getAccrualsRatio() ?? 0.0),
                investedCapitalPerShare: $stock->getInvestedCapital() / max(1.0, (float) $stock->getSharesOutstanding()),
                orderFlowVariance: (float) ($stock->getImpactVarianceEma() ?? 0.0)
            );

            // Calculate new price (GBM + SVJJ)
            $calculation = $this->marketEngine->calculateNextPrice($pricingCtx);

            $newPrice = $calculation['price'];
            $nextVolatility = $calculation['next_volatility'];

            if ($calculation['shock'] !== null) {
                $events[] = $this->eventService->publish($stock, 'SHOCK', "Sudden market shock detected.", $calculation['shock']);
            }

            // ORDER FLOW IMPACT (Almgren & Chriss 2005)
            // The permanent leg only. The temporary leg was already paid by whoever traded, as slippage on
            // their own fill, and putting it here would charge it twice and leave it in the quote besides.
            //
            // Applied after the diffusion rather than inside it because it is not a random draw: it is a
            // known quantity of stock that changed hands, and the price it leaves behind is a fact rather
            // than a distribution. The variance it supplies is handed back through the budget in
            // MarketEngine, using the EMA maintained below.
            $tickFlow = $netOrderFlow[$stock->getTicker()] ?? 0.0;
            $impactLogReturn = 0.0;

            if ($tickFlow !== 0.0) {
                $impactLogReturn = $this->liquidityEngine->permanentImpact($stock, $tickFlow);
                $newPrice = max(0.01, $newPrice * exp($impactLogReturn));
            }

            // Realized impact variance, annualized, as an exponentially weighted mean. This is what the
            // budget draws on, so it has to decay: a name that was heavily traded a year ago must not keep
            // reclaiming variance it no longer supplies.
            if ($dt > 0.0) {
                $impactPhi = exp(-$dt / FinancialConstants::IMPACT_VARIANCE_EMA_YEARS);
                $annualizedTickVariance = ($impactLogReturn * $impactLogReturn) / $dt;

                $stock->setImpactVarianceEma(
                    (($stock->getImpactVarianceEma() ?? 0.0) * $impactPhi) + ($annualizedTickVariance * (1.0 - $impactPhi))
                );
            }

            $stock->setPrice((string) $newPrice);
            $stock->setCurrentVolatility((string) $nextVolatility);

            // Guidance: management warns ahead of a quarter it already knows has gone wrong.
            $warning = $this->earningsEngine->evaluatePreAnnouncement($stock, $tickCount, $ticksPerYear);
            if (!empty($warning)) {
                $events = array_merge($events, $warning);
            }

            // Earnings Engine
            $generatedEvents = $this->earningsEngine->calculate($stock, $macroDTO, $tickCount, $ticksPerYear);
            if (!empty($generatedEvents)) {
                $events = array_merge($events, $generatedEvents);
            }

            $currentPriceAfterEarnings = (float) $stock->getPrice();

            // PRICE MOMENTUM (Jegadeesh & Titman 1993)
            // An exponentially weighted sum of log returns: trend_t = phi * trend_{t-1} + r_t, where phi is a
            // decay set by a horizon in YEARS, so the formation window stays a half-year of simulated time at
            // any tick rate. Measured before splits, since a 4-for-1 split quarters the price without any
            // economic return and would otherwise register as a violent crash.
            if ($priceAtTickStart > 0.0 && $currentPriceAfterEarnings > 0.0) {
                $momentumPhi = exp(-$dt / self::MOMENTUM_FORMATION_YEARS);
                $tickLogReturn = log($currentPriceAfterEarnings / $priceAtTickStart);
                $updatedTrend = (($stock->getPriceMomentumTrend() ?? 0.0) * $momentumPhi) + $tickLogReturn;
                $stock->setPriceMomentumTrend(
                    max(-self::MAX_MOMENTUM_TREND, min(self::MAX_MOMENTUM_TREND, $updatedTrend))
                );
            }

            // CORPORATE ACTIONS (SPLITS)
            $splitResult = $this->corporateActionEngine->processSplits(
                $stock,
                $currentPriceAfterEarnings,
                (float) $stock->getSharesOutstanding()
            );

            // Unpack the results
            $finalPrice = $splitResult['price'];
            $newShares = $splitResult['shares'];
            $splitEvent = $splitResult['event'] ?? null;

            // Always update the price
            $stock->setPrice((string) $finalPrice);

            // Check if the math actually changed
            if ($sharesOutstanding !== $newShares) {
                $stock->setSharesOutstanding((string) $newShares);
            }

            // Only push the event to the array if one was actually generated
            if ($splitEvent) {
                $events[] = $splitEvent;
            }

            // Calculate Market Cap
            $currentMarketCap = $finalPrice * $newShares;
            $totalMarketCap += $currentMarketCap;

            // Shares printed this tick. The players' own fills are prints too, so they are added rather than
            // assumed away: a name nobody but the players trades still shows the volume they generated.
            $tickVolume = $this->liquidityEngine->simulateTickVolume($stock, $dt, abs($tickFlow));

            // Determine if a fundamental corporate event occurred this tick
            $isFundamentalTick = !empty($generatedEvents) || $maResult || (isset($divestResult) && $divestResult);


            $stockUpdate = [
                'ticker' => $stock->getTicker(),
                'sector' => $sectorName,
                'industry' => $stock->getIndustry() ?: 'General',
                'price' => round($finalPrice, 2),
                'market_cap' => $currentMarketCap,
                'current_volatility' => round($nextVolatility * 100, 2),
                'current_roic' => $effectiveRoic, // Backwards compatible fix so frontend JS updates the UI with ROE for banks
                'current_roe' => (float) $stock->getCurrentRoe() != 0.0 ? (float) $stock->getCurrentRoe() : (float) $stock->getBaselineRoe(),
                'shares' => $newShares,
                'eps' => (float) $stock->getEarningsPerShare(),
                'treasury' => (float) $stock->getCorporateTreasury(),
                'equity' => (float) $stock->getTotalEquity(),
                'invested_capital' => $stock->getInvestedCapital(),
                'debt_ratio' => (float) $stock->getDebtToEquityRatio(),
                'credit_rating' => $stock->getCreditRating(),
                'analyst_targets' => $calculation['analyst_targets'],
                'perceived_fair_value' => $calculation['perceived_fair_value'],
                'volume' => $tickVolume,
                'adv_shares' => $this->liquidityEngine->averageDailyVolume($stock),
                'half_spread_bps' => round($this->liquidityEngine->halfSpreadFraction($stock) * 20000.0, 2),
                'is_bankrupt' => false,
            ];


            // Only update Market Share on the UI when Corporate Fundamentals actually change
            // This prevents the percentage from jittering constantly as the Nominal GDP index expands
            if ($isFundamentalTick) {
                $investedCapital = $stock->getInvestedCapital();
                $equity = (float) $stock->getTotalEquity();
                $nominalGdpIndex = $macroDTO->nominalGdpIndex;
                $samRatio = (float) $stock->getSamRatio();

                $evaluationCapital = $strategy->getEvaluationCapital($equity, $investedCapital);
                $marketShare = min(0.9999, $this->corporateMetrics->calculateMarketShare($evaluationCapital, $nominalGdpIndex, $samRatio));

                $stockUpdate['market_share'] = round($marketShare * 100, 2);
            }

            $stockUpdates[] = $stockUpdate;

            if ($recordHistory) {

                $historyData[] = [
                    'stock_id' => $stock->getId(),
                    'price' => $finalPrice,
                    'ticker' => $stock->getTicker(),
                ];
            }
        }

        return [
            'updates' => $stockUpdates,
            'history' => $historyData,
            'total_cap' => $totalMarketCap,
            'events' => $events,
            'market_vol' => $marketVol
        ];
    }
}
