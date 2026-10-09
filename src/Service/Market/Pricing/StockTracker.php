<?php

namespace App\Service\Market\Pricing;

use App\Service\Math\Decimal;
use App\Service\News\EventPresenter;
use App\Data\Company\StrategicHoldings;
use App\Entity\Stock;
use App\DTO\MacroStateDTO;
use App\Service\Corporate\CorporateActionEngine;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\MergerAndAcquisitionEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Market\Flow\OrderFlowStoreInterface;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Math\FinancialConstants;
use App\Service\Macro\MacroEngine;
use App\Service\Market\Index\IndexCommittee;
use App\Service\Math\TimeSeries;

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

    /** Market vol of the last tick priced, at which every stored variance was built; null before the first. */
    private ?float $priorMarketVol = null;

    /** Measured trend of market variance (an EMA over a business cycle); null before the first tick. */
    private ?float $marketVarianceTrend = null;

    /** @var array<string, float> Each name's transient impact still in its price, as a log displacement; lives as long as the ticker process. */
    private array $transientImpact = [];

    /** @var array<string, float> Annualized variance the sovereign fund's impact has supplied to each name (EMA); the market factor gives it back. */
    private array $fundImpactVariance = [];

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
        private \App\Service\Corporate\ManagementSuccessionEngine $successionEngine,
        /** Standing index membership; null (unit tests without an index) leaves every name a constituent. */
        private ?IndexCommittee $indexCommittee = null,
        /** Listed anchor stakes. Required, not optional: a missing one would price a sphere off its stale filed book with nothing to say so. Holds only a per-tick price map, so a harness builds one free. */
        private \App\Service\Corporate\Holdings\AnchorStakeLedger $anchorStakes = new \App\Service\Corporate\Holdings\AnchorStakeLedger()
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
     * @return array{updates: array<mixed>, total_cap: float, float_caps: array<string, float>, half_spreads: array<string, float>, fund_flow: array<string, float>, dividend_points: array<string, float>, board_float_cap: float, board_price_return: float, board_dividend_cash: float, board_net_issuance: float, board_stamp_duty: float, board_bank_levy: float, strategic_stake_cash: float, events: array<mixed>, market_vol: float, history: array<mixed>}
     */
    public function updateStocks(array $stocks, float $dt, bool $recordHistory, ?\App\DTO\MacroStateDTO $macroState = null, int $tickCount = 0, int $ticksPerYear = 252): array
    {

        $stockUpdates = [];
        $historyData = [];
        $totalMarketCap = 0.0;
        $floatAdjustedCaps = [];
        $dividendPoints = [];
        $events = [];

        // Pull systemic variables from the Macro Engine
        $macroDTO = $macroState ?? new \App\DTO\MacroStateDTO();
        $marketVol = $macroDTO->marketVolatility;
        // Each name's stored variance was built at last tick's market vol and is stripped at it.
        $priorMarketVol = $this->priorMarketVol;
        $this->priorMarketVol = $marketVol;
        // The market variance trend the common idiosyncratic factor is read against, seeded where the market settles.
        $this->marketVarianceTrend = TimeSeries::ewmaLevel(
            $this->marketVarianceTrend ?? (MacroEngine::MACRO_VOL_BASE_ANCHOR ** 2),
            $marketVol * $marketVol,
            $dt,
            MarketEngine::MARKET_VARIANCE_TREND_YEARS
        );
        // The stamp duty the Diet has in force thins every name's turnover, and so its depth, this tick.
        $this->liquidityEngine->setStampDutyRate($macroDTO->stampDutyRate);

        // The sovereign fund's rebalance slice for this tick, in currency, spread over the float the way any
        // cap-weighted holder's is: each name gets its share of the float the fund measured the board at.
        $fundTrade = $macroDTO->sovereignFundTrade;
        $fundBoardFloatCap = $macroDTO->boardFloatCap;

        // The board as the fund reads it next tick: float-weighted price return, the dividend cash paid, and the float
        // the companies' own issuance and buybacks added, which an index holder takes up or tenders into pro rata.
        $boardFloatCapAtStart = 0.0;
        $boardPriceGain = 0.0;
        $boardNetIssuance = 0.0;
        // What the board traded this tick, which the District's stamp duty is charged on, buyer and seller each.
        $boardTradedValue = 0.0;
        // What the board's banks owe a year in bank levy at the rate in force, which the budget books as revenue.
        $boardBankLevy = 0.0;
        // Cash the District's strategic stakes pay it: dividends, and its share of buybacks less its share of issues,
        // since it keeps its percentage by tendering and subscribing pro rata. Off the float; paid into the fund.
        $strategicStakeCash = 0.0;

        // Everything that traded since the last tick, netted per ticker. Drained once for the whole book
        // rather than per stock: it is one round trip, and a quantity that has already moved the price must
        // not be able to move it again on the next tick.
        $netOrderFlow = $this->orderFlow->drain();

        // Half-spread per name, and whatever flow no listed company claimed. The drain empties the store,
        // so anything the equity loop does not consume has to be handed on rather than dropped — that
        // leftover is the funds' own order flow, and dropping it would leave every fund pinned to its
        // basket no matter how hard anyone traded it.
        $halfSpreads = [];

        // Passive ownership weights: assets tracking each index proportional to constituent weight.
        $passiveOwnership = $this->indexCommittee?->passiveOwnership() ?? [];

        // The agent books are loaded once for the whole tick and written back once at the end, for the
        // same reason the order flow is drained once: a round trip per name is the cost that scales.
        $this->agentFlow->beginTick();

        // The board's capitalisations, read once for the tick: a sphere's assets ARE these companies, so
        // its net asset value comes from this map rather than from its own filed book.
        $this->anchorStakes->beginTick($stocks);

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

            // Float shares before anything this tick can issue or retire stock: an acquisition paid in stock is struck
            // ahead of the earnings engine's buybacks, offerings and vested awards.
            $sharesAtTickStart = (float) $stock->getSharesOutstanding();
            $floatSharesAtTickStart = $sharesAtTickStart * max(0.0, min(1.0, (float) $stock->getPublicFloatPercentage()));

            // M&A
            $maResult = $this->maEngine->evaluatePrivateAcquisition($stock, $macroDTO, $dt, $tickCount, $ticksPerYear);
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
                Decimal::format($health->rawMetrics->dynamicSpread, 6)
            );

            $sharesOutstanding = (float) $stock->getSharesOutstanding();
            $strategy = \App\Data\Company\Sectors::strategyFor($stock->getIndustry());
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

            $priceAtTickStart = (float) $stock->getPrice();
            $floatCapAtTickStart = IndexCommittee::floatAdjustedCap($stock);

            $pricingCtx = \App\DTO\MarketPricingContext::forStock($stock, $macroDTO, $health, $this->anchorStakes, $dt, $maShock, $priorMarketVol, $this->fundImpactVariance[$stock->getTicker()] ?? 0.0, $this->marketVarianceTrend);

            // Calculate new price (GBM + SVJJ)
            $calculation = $this->marketEngine->calculateNextPrice($pricingCtx);

            $newPrice = $calculation['price'];
            $nextVolatility = $calculation['next_volatility'];

            if ($calculation['shock'] !== null) {
                $events[] = $this->eventService->publish($stock, 'SHOCK', EventPresenter::shockHeadline($stock->getName() . ' shares', $calculation['shock']), $calculation['shock']);
            }

            // Order flow price impact: the peak move is applied, a share of it relaxes away, and the share that stays
            // is accounted in the impact variance EMA.
            $tickFlow = $netOrderFlow[$stock->getTicker()] ?? 0.0;

            // The company's own program — a repurchase still being executed, or issued stock still being
            // distributed — is worked off at the 10b-18 pace and joins the tick's flow here. It is a buyer
            // or seller like any other and is charged the same peak impact; the invented per-report shock it
            // replaces charged the price for a quarter's buying in a single tick with no slippage.
            $corporateSlice = 0.0;
            $corporateBacklog = $stock->getCorporateFlowBacklog();
            if ($corporateBacklog !== 0.0) {
                $corporateSlice = $this->liquidityEngine->corporateFlowSlice($stock, $corporateBacklog, $dt);
                $tickFlow += $corporateSlice;
                $stock->setCorporateFlowBacklog($corporateBacklog - $corporateSlice);
            }

            // The sovereign fund's slice: its share of the fund's trade in proportion to this name's float.
            $fundShares = ($fundTrade !== 0.0 && $fundBoardFloatCap > 0.0 && $priceAtTickStart > 0.0)
                ? $fundTrade * ($floatCapAtTickStart / $fundBoardFloatCap) / $priceAtTickStart
                : 0.0;

            $impactLogReturn = 0.0;
            $budgetedImpactLogReturn = 0.0;
            $budgetedFundLogReturn = 0.0;
            $corporateLogReturn = 0.0;
            $outstandingTransient = $this->transientImpact[$stock->getTicker()] ?? 0.0;

            if ($tickFlow !== 0.0 || $fundShares !== 0.0) {
                // Bounded because this is the one price move that answers to nothing else: it is applied
                // after the diffusion's own circuit breaker, and the per-order size cap the trade desk
                // enforces says nothing about what a tick's NET flow adds up to — many orders, the players'
                // and the agents' together, land in the same tick. The same per-move bound every jump in the
                // system obeys applies here, so a pathological tick cannot dislocate a name without limit.
                $flowImpact = $tickFlow !== 0.0 ? $this->liquidityEngine->peakImpact($stock, $tickFlow) : 0.0;
                $fundImpact = $fundShares !== 0.0 ? $this->liquidityEngine->peakImpact($stock, $fundShares) : 0.0;

                $impactLogReturn = max(
                    -FinancialConstants::MAX_TICK_IMPACT_LOG_RETURN,
                    min(FinancialConstants::MAX_TICK_IMPACT_LOG_RETURN, $flowImpact + $fundImpact)
                );
                // Impact is linear in the quantity, so the fund's part separates exactly. It hits every name at
                // once, which makes it SYSTEMATIC: it is charged to the market factor's budget, never the name's
                // idiosyncratic one, or every rebalance would quietly shrink single-name volatility.
                // Only the share that stays is long-run variance; the transient part washes out within days.
                // The company's own slice stays only at CORPORATE_FLOW_PERMANENT_IMPACT_SHARE: its part of the clamped
                // peak is tracked so the rest of it is moved into the transient book below and kept out of the budget.
                $corporateImpact = $corporateSlice !== 0.0 ? $this->liquidityEngine->peakImpact($stock, $corporateSlice) : 0.0;
                $peakImpact = $flowImpact + $fundImpact;
                $corporateLogReturn = $peakImpact !== 0.0 ? $impactLogReturn * ($corporateImpact / $peakImpact) : 0.0;
                $budgetedImpactLogReturn = (FinancialConstants::PERMANENT_IMPACT_SHARE * max(
                    -FinancialConstants::MAX_TICK_IMPACT_LOG_RETURN,
                    min(FinancialConstants::MAX_TICK_IMPACT_LOG_RETURN, $flowImpact - $corporateImpact)
                )) + (FinancialConstants::CORPORATE_FLOW_PERMANENT_IMPACT_SHARE * max(
                    -FinancialConstants::MAX_TICK_IMPACT_LOG_RETURN,
                    min(FinancialConstants::MAX_TICK_IMPACT_LOG_RETURN, $corporateImpact)
                ));
                $budgetedFundLogReturn = FinancialConstants::PERMANENT_IMPACT_SHARE * max(
                    -FinancialConstants::MAX_TICK_IMPACT_LOG_RETURN,
                    min(FinancialConstants::MAX_TICK_IMPACT_LOG_RETURN, $fundImpact)
                );
            }

            // The peak move lands now; its transient share then relaxes back at the resilience rate, every tick. The
            // company's own slice moves from the permanent share to the transient one.
            $corporateShift = (FinancialConstants::PERMANENT_IMPACT_SHARE - FinancialConstants::CORPORATE_FLOW_PERMANENT_IMPACT_SHARE) * $corporateLogReturn;
            $nextTransient = LiquidityEngine::transientImpactAfter($outstandingTransient, $impactLogReturn, $dt) + $corporateShift;
            $this->transientImpact[$stock->getTicker()] = $nextTransient;
            $impactPriceMove = (FinancialConstants::PERMANENT_IMPACT_SHARE * $impactLogReturn) - $corporateShift + ($nextTransient - $outstandingTransient);
            if ($impactPriceMove !== 0.0) {
                $newPrice = max(0.01, $newPrice * exp($impactPriceMove));
            }

            // Realized impact variance, annualized, as an exponentially weighted mean. This is what the
            // budget draws on, so it has to decay: a name that was heavily traded a year ago must not keep
            // reclaiming variance it no longer supplies.
            if ($dt > 0.0) {
                $stock->setImpactVarianceEma(TimeSeries::ewmaAnnualizedVariance(
                    $stock->getImpactVarianceEma() ?? 0.0,
                    $budgetedImpactLogReturn,
                    $dt,
                    FinancialConstants::IMPACT_VARIANCE_EMA_YEARS
                ));
                $this->fundImpactVariance[$stock->getTicker()] = TimeSeries::ewmaAnnualizedVariance(
                    $this->fundImpactVariance[$stock->getTicker()] ?? 0.0,
                    $budgetedFundLogReturn,
                    $dt,
                    FinancialConstants::IMPACT_VARIANCE_EMA_YEARS
                );
            }

            $stock->setPrice((string) $newPrice);
            $stock->setCurrentVolatility((string) $nextVolatility);

            // Guidance: management warns ahead of a quarter it already knows has gone wrong.
            $warning = $this->earningsEngine->evaluatePreAnnouncement($stock, $tickCount, $ticksPerYear);
            if (!empty($warning)) {
                $events = array_merge($events, $warning);
            }

            $boardBankLevy += \App\Data\Company\Sectors::getBusinessModelStrategy(\App\Data\Company\Sectors::businessModelFor($stock->getIndustry()))
                ->calculateAnnualBankLevy($stock, $macroDTO);

            // Earnings Engine
            $generatedEvents = $this->earningsEngine->calculate($stock, $macroDTO, $tickCount, $ticksPerYear);
            if (!empty($generatedEvents)) {
                $events = array_merge($events, $generatedEvents);
            }

            $currentPriceAfterEarnings = (float) $stock->getPrice();

            // A report or a warning restates the books after the price step struck fair value on the old ones.
            // Re-strike on what was filed, so the analysts and agents who act on it this tick read the news.
            if ($generatedEvents !== null || !empty($warning)) {
                $calculation = array_replace($calculation, $this->marketEngine->strikeFairValue(
                    \App\DTO\MarketPricingContext::forStock($stock, $macroDTO, $this->debtEngine->analyzeDebtHealth($stock, $macroDTO), $this->anchorStakes, 0.0, 0.0, $priorMarketVol)
                ));
            }

            // TOTAL RETURN OF THE TICK
            // The report pays the dividend and takes it off the price in the same step. A holder was paid
            // that cash, so the return the trend and the agents are scored on adds it back; measured on the
            // price alone, every payment read as a loss for whoever was long and a gain for whoever was short.
            $dividendPaidPerShare = 0.0;
            foreach ($generatedEvents ?? [] as $generatedEvent) {
                $dividendPaidPerShare += (float) ($generatedEvent['dividend_per_share'] ?? 0.0);
            }

            // Index dividend points: float-adjusted dividend cash per ticker for index point calculations.
            if ($dividendPaidPerShare > 0.0) {
                $dividendPoints[$stock->getTicker()] = $dividendPaidPerShare
                    * (float) $stock->getSharesOutstanding()
                    * max(0.0, min(1.0, (float) $stock->getPublicFloatPercentage()));
                $strategicStakeCash += $dividendPaidPerShare
                    * (float) $stock->getSharesOutstanding()
                    * StrategicHoldings::stake($stock->getTicker());
            }

            $tickLogReturn = $priceAtTickStart > 0.0 && $currentPriceAfterEarnings > 0.0
                ? log(($currentPriceAfterEarnings + $dividendPaidPerShare) / $priceAtTickStart)
                : 0.0;

            // The board's float-weighted price return, measured before any split like the trend is: the fund
            // holds the float, so a name weighs what its float was worth when the tick began.
            if ($floatCapAtTickStart > 0.0 && $priceAtTickStart > 0.0) {
                $boardFloatCapAtStart += $floatCapAtTickStart;
                $boardPriceGain += $floatCapAtTickStart * (($currentPriceAfterEarnings / $priceAtTickStart) - 1.0);
            }

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

            // Realized variance EMA: annualized trailing window variance tracked for index and screener selection.
            if ($dt > 0.0 && $priceAtTickStart > 0.0 && $currentPriceAfterEarnings > 0.0) {
                $stock->setRealizedVarianceEma(TimeSeries::ewmaAnnualizedVariance(
                    $stock->getRealizedVarianceEma() ?? 0.0,
                    $tickLogReturn,
                    $dt,
                    FinancialConstants::INDEX_TRAILING_VOLATILITY_YEARS
                ));
            }

            // CORPORATE ACTIONS (SPLITS)
            // Read again here rather than reusing the count from the top of the tick: issuance and buybacks
            // in the earnings engine change it, and only a split should reach the agents as a share ratio.
            $preSplitShares = (float) $stock->getSharesOutstanding();

            // The company's own issuance net of buybacks, as float at this tick's price, measured in pre-split shares so
            // a split -- a change of units, not of ownership -- never reads as either.
            $boardNetIssuance += (($preSplitShares * max(0.0, min(1.0, (float) $stock->getPublicFloatPercentage()))) - $floatSharesAtTickStart)
                * $currentPriceAfterEarnings;
            $strategicStakeCash -= StrategicHoldings::stake($stock->getTicker()) * ($preSplitShares - $sharesAtTickStart) * $currentPriceAfterEarnings;

            $splitResult = $this->corporateActionEngine->processSplits(
                $stock,
                $currentPriceAfterEarnings,
                $preSplitShares,
                $macroDTO->totalTime
            );

            // Unpack the results
            $finalPrice = $splitResult['price'];
            $newShares = $splitResult['shares'];
            $splitEvent = $splitResult['event'] ?? null;

            // Restate perceived fair value and analyst targets for corporate stock splits.
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

            // Sell-side analyst price targets: infrequent discrete revisions anchored to fair value.
            $analystRevision = $this->reviseAnalystTarget($stock, $perceivedFairValue, $restateForSplit ? $splitRatio : 1.0);

            if ($analystRevision !== null) {
                $events[] = $analystRevision;
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

            // What a passive fund could actually buy of this name, which is what an index weights on: the
            // part of the company that trades. Published per ticker so the index can sum its OWN members
            // rather than the whole board.
            $floatAdjustedCaps[$stock->getTicker()] = IndexCommittee::floatAdjustedCap($stock);

            // Shares printed this tick. The players' own fills are prints too, so they are added rather than
            // assumed away: a name nobody but the players trades still shows the volume they generated.
            // What it costs to get in and out of this name. Published per ticker as well as on the wire,
            // because a fund's arbitrage band is the weighted average of its constituents' — an expensive
            // basket is what lets a fund drift from it.
            $halfSpread = $this->liquidityEngine->halfSpreadFraction($stock);
            $halfSpreads[$stock->getTicker()] = $halfSpread;

            $tickVolume = $this->liquidityEngine->simulateTickVolume($stock, $dt, abs($tickFlow + $fundShares));
            $boardTradedValue += $tickVolume * $finalPrice;

            // What the name prints on an ordinary tick, which is what makes the realized figure abnormal
            // or not. The level alone is a size statistic: a mega-cap always prints more than a micro-cap
            // and neither fact is news.
            $expectedTickVolume = $this->liquidityEngine->averageDailyVolume($stock)
                * $dt * FinancialConstants::TRADING_DAYS_PER_YEAR;

            // Determine if a fundamental corporate event occurred this tick
            $isFundamentalTick = !empty($generatedEvents) || $maResult || (isset($divestResult) && $divestResult);


            // Everything below is display data that every connected browser receives on every tick, so
            // it is carried at the precision a screen can show. The full-precision figures live on the
            // entity and in the engines; a fair value with fifteen decimals was a third of the payload.
            $stockUpdate = [
                'ticker' => $stock->getTicker(),
                'sector' => $sectorName,
                'industry' => $stock->getIndustry() ?: 'General',
                'price' => round($finalPrice, 2),
                'market_cap' => round($currentMarketCap),
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
                'credit_spread' => (float) $stock->getDynamicCreditSpread(),
                'analyst_targets' => array_map(static fn (float $target): float => round($target, 2), $analystTargets),
                'analyst_price_target' => $stock->getAnalystPriceTarget() !== null ? round((float) $stock->getAnalystPriceTarget(), 2) : null,
                'analyst_rating' => $stock->getAnalystRating(),
                'perceived_fair_value' => round($perceivedFairValue, 2),
                'volume' => round($tickVolume),
                'adv_shares' => round($this->liquidityEngine->averageDailyVolume($stock)),
                'spread_bps' => round($halfSpread * 20000.0, 2),
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

            // Institutional agent flow: agents evaluate market view and generate orders for the subsequent tick.
            $this->agentFlow->trade(new \App\DTO\AgentMarketViewDTO(
                ticker: $stock->getTicker(),
                price: $finalPrice,
                perceivedFairValue: $perceivedFairValue,
                momentumTrend: (float) ($stock->getPriceMomentumTrend() ?? 0.0),
                averageDailyVolume: $this->liquidityEngine->structuralDailyVolume($stock),
                logReturn: $tickLogReturn,
                financialConditions: $macroDTO->financialConditionsIndex,
                dt: $dt,
                riskFreeRate: $macroDTO->policyRate,
                annualizedVolatility: (float) $nextVolatility,
                splitRatio: $splitRatio,
                passiveOwnershipMultiple: $passiveOwnership === []
                    ? 1.0
                    : ($passiveOwnership[$stock->getTicker()] ?? 0.0),
                // What attention-driven retail sorts on (Barber & Odean 2008): how hard this name is
                // trading relative to its own normal, and whether anything happened to it. Both are
                // already computed above for other reasons, so neither costs a second pass.
                abnormalVolume: $expectedTickVolume > 0.0 ? ($tickVolume / $expectedTickVolume) : 1.0,
                hasNews: $isFundamentalTick
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
            'float_caps' => $floatAdjustedCaps,
            'half_spreads' => $halfSpreads,
            'fund_flow' => array_diff_key($netOrderFlow, $halfSpreads),
            'dividend_points' => $dividendPoints,
            'board_float_cap' => (float) array_sum($floatAdjustedCaps),
            'board_price_return' => $boardFloatCapAtStart > 0.0 ? $boardPriceGain / $boardFloatCapAtStart : 0.0,
            'board_dividend_cash' => (float) array_sum($dividendPoints),
            'board_net_issuance' => $boardNetIssuance,
            'board_stamp_duty' => 2.0 * $macroDTO->stampDutyRate * $boardTradedValue,
            'board_bank_levy' => $boardBankLevy,
            'strategic_stake_cash' => $strategicStakeCash,
            'events' => $events,
            'market_vol' => $marketVol
        ];
    }

    /**
     * Restates the published price target when the case for it has moved past the revision band.
     *
     * Returns the revision as a market event, or null on the overwhelming majority of ticks where the
     * standing target still stands. A split restates the target like every other per-share figure, and does
     * so silently: a four-for-one is not a seventy-five percent downgrade.
     *
     * @param float $splitRatio New shares per old share this tick; 1.0 when nothing happened.
     * @return array<string, mixed>|null
     */
    private function reviseAnalystTarget(Stock $stock, float $perceivedFairValue, float $splitRatio): ?array
    {
        if ($perceivedFairValue <= 0.0) {
            return null;
        }

        $standing = $stock->getAnalystPriceTarget() !== null ? (float) $stock->getAnalystPriceTarget() : null;

        if ($standing !== null && $splitRatio > 0.0 && $splitRatio !== 1.0) {
            $standing /= $splitRatio;
            $stock->setAnalystPriceTarget((string) $standing);
        }

        $fresh = $perceivedFairValue * (1.0 + FinancialConstants::ANALYST_TARGET_OPTIMISM);

        // Nobody has covered this name before. Initiating coverage is not a revision of anything.
        if ($standing === null || $standing <= 0.0) {
            $stock->setAnalystPriceTarget((string) $fresh);

            return null;
        }

        $drift = ($fresh - $standing) / $standing;

        if (abs($drift) < FinancialConstants::ANALYST_TARGET_REVISION_THRESHOLD) {
            return null;
        }

        $stock->setAnalystPriceTarget((string) $fresh);

        $direction = $drift > 0.0 ? 'raised' : 'cut';
        $from = number_format($standing, 2);
        $to = number_format($fresh, 2);

        // The revision itself carries no shock. It is a statement about what the name is worth, and what it
        // is worth has already reached the price through the fair value the target was struck on; pricing
        // it again here would pay for the same information twice.
        return $this->eventService->publish(
            $stock,
            'ANALYST',
            "Sell-side {$direction} its price target on {$stock->getTicker()} to \${$to} from \${$from}.",
            0.0
        );
    }

}
