<?php

namespace App\Service\Market;

use App\Entity\Stock;
use App\DTO\MacroStateDTO;
use App\Service\Corporate\CorporateActionEngine;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\MergerAndAcquisitionEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Market\Flow\OrderFlowStoreInterface;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\FinancialConstants;

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
     * @param MarketEngine $marketEngine Engine for calculating stock price movements.
     * @param EarningsEngine $earningsEngine Engine for processing quarterly earnings reports.
     * @param CorporateActionEngine $corporateActionEngine Engine for handling corporate actions like stock splits.
     * @param MarketEventPublisher $eventService Publisher for market events, shocks, and headlines.
     */
    public function __construct(
        private MarketEngine $marketEngine,
        private EarningsEngine $earningsEngine,
        private CorporateActionEngine $corporateActionEngine,
        private MergerAndAcquisitionEngine $maEngine,
        private MarketEventPublisher $eventService,
        private DebtEngine $debtEngine,
        private CorporateMetrics $corporateMetrics,
        private LiquidityEngine $liquidityEngine,
        private OrderFlowStoreInterface $orderFlow,
        private \App\Service\Market\Agent\AgentFlowEngine $agentFlow,
        private \App\Service\Corporate\ManagementSuccessionEngine $successionEngine
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

        // The agent books are loaded once for the whole tick and written back once at the end, for the
        // same reason the order flow is drained once: a round trip per name is the cost that scales.
        $this->agentFlow->beginTick();

        foreach ($stocks as $stock) {
            $sectorName = $stock->getSector();

            if ($stock->isBankrupt()) {
                $stockUpdates[] = [
                    'ticker' => $stock->getTicker(),
                    'sector' => $sectorName,
                    'industry' => $stock->getIndustry() ?: 'General',
                    'price' => (float) $stock->getPrice(),
                    'market_cap' => (float) $stock->getPrice() * (float) $stock->getSharesOutstanding(),
                    // Reported as a percentage, matching the live branch below; a bankrupt shell is pinned at zero
                    // either way, but the two rows feed the same field on the same UI.
                    'current_volatility' => round((float) ($stock->getCurrentVolatility() ?? $stock->getVolatility()) * 100, 2),
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

            // What this firm's credit costs, published for the bond desk to discount its listed issues at.
            // Computed here rather than there so there is one authority on it: the same figure the firm
            // borrows at is the one its bonds are priced off.
            $stock->setDynamicCreditSpread(
                \App\Service\Math\MathUtility::formatDecimal($health->rawMetrics->dynamicSpread, 6)
            );

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

            // MANAGEMENT SUCCESSION
            // The board judges the manager on economic profit against the firm's TRUE cost of capital, never
            // the hurdle the incumbent has been applying — a manager cannot mark their own homework by
            // holding a lower bar. Before the first report there is no realised return to judge, so the
            // structural baseline stands in and a freshly seeded firm is not dismissed for having no history.
            $structuralReturn = $strategy->isFinancial() ? (float) $stock->getBaselineRoe() : (float) $stock->getBaselineRoic();
            $boardReturn = $roicTtm !== 0.0 ? $roicTtm : $structuralReturn;
            $successionResult = $this->successionEngine->evaluateSuccession(
                $stock,
                $dt,
                $boardReturn - $strategy->getHurdleRate($health)
            );
            if ($successionResult) {
                $events[] = $successionResult['event'];
            }

            $totalDebt = (float) $stock->getTotalDebt();
            $corporateTreasury = (float) $stock->getCorporateTreasury();
            $netDebtPerShare = max(0.0, ($totalDebt - $corporateTreasury) / $shares);

            $secularGrowth = $strategy->getSecularGrowthRate($stock);

            // Leverage re-levers the magnitude of a firm's systematic exposure, never its sign. DebtEngine
            // now levers the firm's own signed beta through Hamada, so its result is already the beta this
            // diffusion wants and is used directly. This previously had to reconstruct the multiplier and
            // reapply it, because DebtEngine levered max(0.5, |beta|) and would otherwise have turned an
            // inverse hedge into a market-following name.
            $leveredBeta = $health->leveredBeta ?? (float) $stock->getBeta();

            $priceAtTickStart = (float) $stock->getPrice();
            $sectorZ = (float) ($macroDTO->sectorZ[$sectorName] ?? 0.0);

            $pricingCtx = new \App\DTO\MarketPricingContext(
                currentPrice: (float) $stock->getPrice(),
                currentVolatility: $currentVol,
                longTermVolatility: $baselineVol,
                // Analysts cut their number on a guidance warning, and fair value is what analysts think
                // the name is worth. Until the report puts the quarter into trailing earnings, the guided
                // shortfall comes off the figure fair value is struck on — otherwise the warning moved the
                // price and nothing else, and the reversion pulled it straight back before the report.
                // The report resets the guided figure and puts the actual quarter in, so there is no
                // double count: the two hand over.
                earningsPerShare: (float) $stock->getEarningsPerShare()
                    - ($stock->getPreAnnouncedShortfall() / max(1.0, (float) $stock->getSharesOutstanding())),
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

            // The company's own program — a repurchase still being executed, or issued stock still being
            // distributed — is worked off at the 10b-18 pace and joins the tick's flow here. It is a buyer
            // or seller like any other and is charged the same impact; the invented per-report shock it
            // replaces charged the price for a quarter's buying in a single tick with no slippage.
            $corporateBacklog = $stock->getCorporateFlowBacklog();
            if ($corporateBacklog !== 0.0) {
                $corporateSlice = $this->liquidityEngine->corporateFlowSlice($stock, $corporateBacklog, $dt);
                $tickFlow += $corporateSlice;
                $stock->setCorporateFlowBacklog($corporateBacklog - $corporateSlice);
            }

            $impactLogReturn = 0.0;

            if ($tickFlow !== 0.0) {
                // Bounded because this is the one price move that answers to nothing else: it is applied
                // after the diffusion's own circuit breaker, and the per-order size cap the trade desk
                // enforces says nothing about what a tick's NET flow adds up to — many orders, the players'
                // and the agents' together, land in the same tick. The same per-move bound every jump in the
                // system obeys applies here, so a pathological tick cannot dislocate a name without limit.
                $impactLogReturn = max(
                    -FinancialConstants::MAX_TICK_IMPACT_LOG_RETURN,
                    min(
                        FinancialConstants::MAX_TICK_IMPACT_LOG_RETURN,
                        $this->liquidityEngine->permanentImpact($stock, $tickFlow)
                    )
                );
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

            // TOTAL RETURN OF THE TICK
            // The report pays the dividend and takes it off the price in the same step. A holder was paid
            // that cash, so the return the trend and the agents are scored on adds it back; measured on the
            // price alone, every payment read as a loss for whoever was long and a gain for whoever was short.
            $dividendPaidPerShare = 0.0;
            foreach ($generatedEvents ?? [] as $generatedEvent) {
                $dividendPaidPerShare += (float) ($generatedEvent['dividend_per_share'] ?? 0.0);
            }

            $tickLogReturn = $priceAtTickStart > 0.0 && $currentPriceAfterEarnings > 0.0
                ? log(($currentPriceAfterEarnings + $dividendPaidPerShare) / $priceAtTickStart)
                : 0.0;

            // PRICE MOMENTUM (Jegadeesh & Titman 1993)
            // An exponentially weighted sum of log returns: trend_t = phi * trend_{t-1} + r_t, where phi is a
            // decay set by a horizon in YEARS, so the formation window stays a half-year of simulated time at
            // any tick rate. Measured before splits, since a 4-for-1 split quarters the price without any
            // economic return and would otherwise register as a violent crash.
            if ($priceAtTickStart > 0.0 && $currentPriceAfterEarnings > 0.0) {
                $momentumPhi = exp(-$dt / self::MOMENTUM_FORMATION_YEARS);
                $updatedTrend = (($stock->getPriceMomentumTrend() ?? 0.0) * $momentumPhi) + $tickLogReturn;
                $stock->setPriceMomentumTrend(
                    max(-self::MAX_MOMENTUM_TREND, min(self::MAX_MOMENTUM_TREND, $updatedTrend))
                );
            }

            // CORPORATE ACTIONS (SPLITS)
            // Read again here rather than reusing the count from the top of the tick: issuance and buybacks
            // in the earnings engine change it, and only a split should reach the agents as a share ratio.
            $preSplitShares = (float) $stock->getSharesOutstanding();
            $splitResult = $this->corporateActionEngine->processSplits(
                $stock,
                $currentPriceAfterEarnings,
                $preSplitShares
            );

            // Unpack the results
            $finalPrice = $splitResult['price'];
            $newShares = $splitResult['shares'];
            $splitEvent = $splitResult['event'] ?? null;

            // A split restates every per-share figure, and fair value is one of them. It was struck at the
            // top of this tick, on the share count the quarter was reported against, so publishing it
            // alongside a post-split price compared two different units: a 4-for-1 made the name look 75%
            // cheap and a 1-for-10 reverse split made it look ten times dear. The fundamentalist and
            // relative-value agents both saturate at a log gap of 0.4, so either one put a full-commitment
            // order into the market on a corporate action that moved no money — and the reverse split is
            // the dangerous direction, because it only ever fires on a name already close to failing.
            $splitRatio = $preSplitShares > 0.0 ? $newShares / $preSplitShares : 1.0;
            $restateForSplit = $splitRatio > 0.0 && $splitRatio !== 1.0 && is_finite($splitRatio);

            $perceivedFairValue = (float) $calculation['perceived_fair_value'];
            $analystTargets = $calculation['analyst_targets'];

            if ($restateForSplit) {
                $perceivedFairValue /= $splitRatio;
                $analystTargets = array_map(
                    static fn (float $target): float => $target / $splitRatio,
                    $analystTargets
                );
            }

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
                'analyst_targets' => $analystTargets,
                'perceived_fair_value' => $perceivedFairValue,
                'volume' => $tickVolume,
                'adv_shares' => $this->liquidityEngine->averageDailyVolume($stock),
                'spread_bps' => round($this->liquidityEngine->halfSpreadFraction($stock) * 20000.0, 2),
                'is_bankrupt' => false,
            ];


            // The headline market share is the firm's reach into its serviceable addressable market — the
            // whole market it sells into, modelled rivals or not, which is what the saturation physics is
            // struck on (IndustryPositionBuilder shows the same figure with the penalty it carries). Only
            // refreshed when fundamentals change, so it does not jitter with the nominal GDP index.
            if ($isFundamentalTick) {
                $evaluationCapital = $strategy->getEvaluationCapital((float) $stock->getTotalEquity(), $stock->getInvestedCapital());
                $addressableShare = min(
                    FinancialConstants::MAX_ADDRESSABLE_MARKET_SHARE,
                    $this->corporateMetrics->calculateScaleRatio($evaluationCapital, $macroDTO->nominalGdpIndex, (float) $stock->getSamRatio())
                );

                $stockUpdate['market_share'] = round($addressableShare * 100, 2);
            }

            // AGENT FLOW (Brock & Hommes 1997, 1998)
            // The simulated institutional book reacts to the price that has just been published and its
            // orders land on the next tick, through the same impact channel and the same variance budget a
            // player's fill goes through. The one-tick lag is the causality, not a shortcut: a participant
            // observes a price and then trades.
            // The return the agents are scored on is the tick's total return, measured BEFORE the split
            // like the momentum trend above, and the split reaches them as a share ratio: a 4-for-1 is not
            // a 75% loss. Their books are sized on the structural volume, not today's activity-scaled
            // depth, so a stressed tape does not grow every book. The volatility handed in only seeds a
            // book with no history; after that the agents see the realized measure their engine keeps.
            $this->agentFlow->trade(new \App\DTO\AgentMarketViewDTO(
                ticker: $stock->getTicker(),
                price: $finalPrice,
                perceivedFairValue: $perceivedFairValue,
                momentumTrend: (float) ($stock->getPriceMomentumTrend() ?? 0.0),
                averageDailyVolume: $this->liquidityEngine->structuralDailyVolume($stock),
                logReturn: $tickLogReturn,
                financialConditions: $macroDTO->financialConditionsIndexEma,
                dt: $dt,
                riskFreeRate: $macroDTO->policyRate,
                annualizedVolatility: (float) $nextVolatility,
                splitRatio: $splitRatio
            ));

            $stockUpdates[] = $stockUpdate;

            if ($recordHistory) {

                $historyData[] = [
                    'stock_id' => $stock->getId(),
                    'price' => $finalPrice,
                    'ticker' => $stock->getTicker(),
                ];
            }
        }

        $this->agentFlow->endTick();

        return [
            'updates' => $stockUpdates,
            'history' => $historyData,
            'total_cap' => $totalMarketCap,
            'events' => $events,
            'market_vol' => $marketVol
        ];
    }
}
