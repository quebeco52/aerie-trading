<?php

declare(strict_types=1);

namespace App\Service\Market\Pricing;

use App\Data\Company\StrategicHoldings;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateActionEngine;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\MergerAndAcquisitionEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Market\Flow\OrderFlowStoreInterface;
use App\Service\Market\Index\IndexCommittee;
use App\Service\Math\Decimal;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\TimeSeries;
use App\Service\News\EventPresenter;

/**
 * Steps every listed company through one tick of the market, in the order each step reads the last: the deals and the
 * board's review of management strike first, the price steps on them, order flow moves it, the report restates the
 * books, the return is scored, the share count settles, and the name is published to the wire and the agents.
 */
class StockTracker
{
    // --- Price Momentum (Jegadeesh & Titman 1993) ---
    /**
     * Formation horizon in years of the exponentially weighted price trend, matching the 6-month window where momentum
     * is strongest.
     */
    private const MOMENTUM_FORMATION_YEARS = 0.50;
    /** Absolute cap on the accumulated trend, bounding how far momentum can delay fundamental mean reversion. */
    private const MAX_MOMENTUM_TREND = 0.50;

    // --- Market Microstructure: Impact (Almgren, Thum, Hauptmann & Li 2005) ---
    /**
     * Share of a company's own open-market repurchase or flowback impact that stays: none. Execution of an announced
     * program supplies liquidity and carries no news (Hillert, Maug & Obernberger 2016; Busch & Obernberger 2017); the
     * program's information is in fair value through its payout.
     */
    public const CORPORATE_FLOW_PERMANENT_IMPACT_SHARE = 0.0;
    /**
     * Ceiling on the peak price move of one tick's net order flow, as a log return; the impact law is a per-order
     * measurement and a tick's aggregate is not bounded by the per-order size cap.
     */
    public const MAX_TICK_IMPACT_LOG_RETURN = 0.2624;

    // --- Market Index Membership (Shleifer 1986) ---
    /**
     * Window the realized volatility an index ranks and weights on is measured over, as the mean life of its
     * exponential weighting. A year, which is what S&P's low-volatility index uses; ranking on the instantaneous
     * variance state instead turned every volatility spike into a reconstitution.
     */
    public const INDEX_TRAILING_VOLATILITY_YEARS = 1.0;

    // --- Market Microstructure: Order Flow Variance Budget ---
    /** Half-life in years of the realized impact-variance estimate the budget is drawn from. */
    public const IMPACT_VARIANCE_EMA_YEARS = 0.25;
    /**
     * Horizon the impact variance is read at: a trading day's summed impact, the horizon realized variance is quoted
     * on.
     */
    public const IMPACT_MEASUREMENT_YEARS = 1.0 / FinancialConstants::TRADING_DAYS_PER_YEAR;

    // --- Sell-Side Price Targets (Brav & Lehavy 2003) ---
    /**
     * How far above fair value the published twelve-month target is set. Targets are systematically optimistic; Brav &
     * Lehavy measure them around 28% above price, and with price near fair value on average this lands in the same
     * place.
     */
    public const ANALYST_TARGET_OPTIMISM = 0.25;
    /**
     * How far the case has to move before the published target is restated. Targets are sticky and revised in steps;
     * without a band the target would track the price continuously and a revision would never be news.
     */
    public const ANALYST_TARGET_REVISION_THRESHOLD = 0.10;

    /** Market vol of the last tick priced, at which every stored variance was built; null before the first. */
    private ?float $priorMarketVol = null;

    /** Measured trend of market variance (an EMA over a business cycle); null before the first tick. */
    private ?float $marketVarianceTrend = null;

    /**
     * @var array<string, float> Each name's transient impact still in its price, as a log displacement; lives as long
     *                           as the ticker process.
     */
    private array $transientImpact = [];

    /**
     * @var array<string, float> Annualized variance the sovereign fund's impact has supplied to each name (EMA); the
     *                           market factor gives it back.
     */
    private array $fundImpactVariance = [];

    /**
     * @var array<string, array{flow: array{sum: float, elapsed: float}, fund: array{sum: float, elapsed: float}}> Each name's impact returns summed over the trading day so far, which its impact variance is read on.
     */
    private array $impactDay = [];

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
        /**
         * Listed anchor stakes. Required, not optional: a missing one would price a sphere off its stale filed book
         * with nothing to say so. Holds only a per-tick price map, so a harness builds one free.
         */
        private \App\Service\Corporate\Holdings\AnchorStakeLedger $anchorStakes = new \App\Service\Corporate\Holdings\AnchorStakeLedger(),
    ) {}

    /**
     * Updates the prices and states of a collection of stocks for a single time step.
     *
     * @param Stock[] $stocks        Array of Stock entities to update.
     * @param float   $dt            The time step delta (e.g., in years).
     * @param bool    $recordHistory Whether to persist the new prices to the stock history table.
     * @param MacroStateDTO|null $macroState    The current state of the macroeconomic cycle.
     *
     * @return array{updates: list<array<string, mixed>>, total_cap: float, float_caps: array<string, float>, half_spreads: array<string, float>, fund_flow: array<string, float>, dividend_points: array<string, float>, board_float_cap: float, board_price_return: float, board_dividend_cash: float, board_net_issuance: float, board_stamp_duty: float, board_bank_levy: float, strategic_stake_cash: float, events: list<mixed>, market_vol: float, history: array<mixed>}
     */
    public function updateStocks(array $stocks, float $dt, bool $recordHistory, ?MacroStateDTO $macroState = null, int $tickCount = 0, int $ticksPerYear = 252): array
    {
        // Pull systemic variables from the Macro Engine
        $macroDTO = $macroState ?? new MacroStateDTO();
        $marketVol = $macroDTO->marketVolatility;
        // Each name's stored variance was built at last tick's market vol and is stripped at it.
        $priorMarketVol = $this->priorMarketVol;
        $this->priorMarketVol = $marketVol;
        // The market variance trend the common idiosyncratic factor is read against, seeded where the market settles.
        $this->marketVarianceTrend = TimeSeries::ewmaLevel(
            $this->marketVarianceTrend ?? (MacroConstants::MACRO_VOL_BASE_ANCHOR ** 2),
            $marketVol * $marketVol,
            $dt,
            MarketEngine::MARKET_VARIANCE_TREND_YEARS,
        );
        // The stamp duty the Diet has in force thins every name's turnover, and so its depth, this tick.
        $this->liquidityEngine->setStampDutyRate($macroDTO->stampDutyRate);

        $board = new BoardTick(
            $macroDTO,
            $dt,
            $tickCount,
            $ticksPerYear,
            $priorMarketVol,
            // Everything that traded since the last tick, netted per ticker. Drained once for the whole book rather
            // than per stock: it is one round trip, and a quantity that has already moved the price must not be able
            // to move it again on the next tick.
            $this->orderFlow->drain(),
            // Passive ownership weights: assets tracking each index proportional to constituent weight.
            $this->indexCommittee?->passiveOwnership() ?? [],
        );

        // The agent books are loaded once for the whole tick and written back once at the end, for the
        // same reason the order flow is drained once: a round trip per name is the cost that scales.
        $this->agentFlow->beginTick();

        // The board's capitalisations, read once for the tick: a sphere's assets ARE these companies, so
        // its net asset value comes from this map rather than from its own filed book.
        $this->anchorStakes->beginTick($stocks);

        foreach ($stocks as $stock) {
            if ($stock->isBankrupt()) {
                $board->updates[] = self::bankruptRow($stock);

                continue;
            }

            $this->updateStock(new StockTick($stock), $board, $recordHistory);
        }

        $this->agentFlow->endTick();

        return [
            'updates' => $board->updates,
            'history' => $board->history,
            'total_cap' => $board->totalCap,
            'float_caps' => $board->floatCaps,
            'half_spreads' => $board->halfSpreads,
            // Whatever flow no listed company claimed. The drain emptied the store, so what the equity loop did not
            // consume is handed on rather than dropped: that leftover is the funds' own order flow, and dropping it
            // would leave every fund pinned to its basket no matter how hard anyone traded it.
            'fund_flow' => array_diff_key($board->netOrderFlow, $board->halfSpreads),
            'dividend_points' => $board->dividendPoints,
            'board_float_cap' => (float) array_sum($board->floatCaps),
            'board_price_return' => $board->floatCapAtStart > 0.0 ? $board->priceGain / $board->floatCapAtStart : 0.0,
            'board_dividend_cash' => (float) array_sum($board->dividendPoints),
            'board_net_issuance' => $board->netIssuance,
            'board_stamp_duty' => 2.0 * $macroDTO->stampDutyRate * $board->tradedValue,
            'board_bank_levy' => $board->bankLevy,
            'strategic_stake_cash' => $board->strategicStakeCash,
            'events' => $board->events,
            'market_vol' => $marketVol,
        ];
    }

    /**
     * One company's tick. The steps run in this order because each reads what the one before it left, and the random
     * draws they make are taken in it.
     */
    private function updateStock(StockTick $tick, BoardTick $board, bool $recordHistory): void
    {
        $this->strikeDeals($tick, $board);
        $this->assessCredit($tick, $board);
        $this->reviewManagement($tick, $board);
        $this->stepPrice($tick, $board);
        $this->applyOrderFlow($tick, $board);
        $this->fileReports($tick, $board);
        $this->scoreReturn($tick, $board);
        $this->settleShareCount($tick, $board);
        $this->publish($tick, $board, $recordHistory);
    }

    /** An acquisition, or failing one a divestiture: a company won't acquire and divest in the exact same tick. */
    private function strikeDeals(StockTick $tick, BoardTick $board): void
    {
        $maResult = $this->maEngine->evaluatePrivateAcquisition($tick->stock, $board->macro, $board->dt, $board->tickCount, $board->ticksPerYear);
        if ($maResult) {
            $board->events[] = $maResult['event'];
            $tick->dealShock = $maResult['shock'];
            $tick->struckDeal = true;

            return;
        }

        $divestResult = $this->maEngine->evaluateCorporateDivestiture($tick->stock, $board->macro, $board->dt);
        if ($divestResult) {
            $board->events[] = $divestResult['event'];
            $tick->dealShock = $divestResult['shock'];
            $tick->struckDeal = true;
        }
    }

    /** The firm's true, dynamic cost of capital from the DebtEngine, and what its credit costs. */
    private function assessCredit(StockTick $tick, BoardTick $board): void
    {
        $stock = $tick->stock;
        $tick->health = $this->debtEngine->analyzeDebtHealth($stock, $board->macro);
        // The beta this tick trades the name on, kept for the option desk to quote the same exposure.
        $stock->setLeveredBeta($tick->health->leveredBeta);

        // What this firm's credit costs, published for the bond desk to discount its listed issues at.
        // Computed here rather than there so there is one authority on it: the same figure the firm
        // borrows at is the one its bonds are priced off.
        $stock->setDynamicCreditSpread(
            Decimal::format($tick->health->rawMetrics->dynamicSpread, 6),
        );

        $tick->sharesBeforeReport = (float) $stock->getSharesOutstanding();
        $tick->strategy = \App\Data\Company\Sectors::strategyFor($stock->getIndustry());
        $tick->effectiveReturn = $tick->strategy->getEffectiveReturn($stock);
    }

    /**
     * MANAGEMENT SUCCESSION
     *
     * The board judges the manager on economic profit against the firm's TRUE cost of capital, never the hurdle the
     * incumbent has been applying — a manager cannot mark their own homework by holding a lower bar. Before the first
     * report there is no realised return to judge, so the structural baseline stands in and a freshly seeded firm is
     * not dismissed for having no history.
     */
    private function reviewManagement(StockTick $tick, BoardTick $board): void
    {
        $stock = $tick->stock;
        $roicTtm = $tick->strategy->getTrueReturn($stock);
        $structuralReturn = $tick->strategy->isFinancial() ? (float) $stock->getBaselineRoe() : (float) $stock->getBaselineRoic();
        $boardReturn = $roicTtm !== 0.0 ? $roicTtm : $structuralReturn;
        $successionResult = $this->successionEngine->evaluateSuccession(
            $stock,
            $board->dt,
            $boardReturn - $tick->strategy->getHurdleRate($tick->health),
        );
        if ($successionResult) {
            $board->events[] = $successionResult['event'];
        }
    }

    /** The diffusion and its jumps (GBM + SVJJ), on the deal's shock and the credit just assessed. */
    private function stepPrice(StockTick $tick, BoardTick $board): void
    {
        $stock = $tick->stock;
        $tick->priceAtStart = (float) $stock->getPrice();
        $tick->floatCapAtStart = IndexCommittee::floatAdjustedCap($stock);

        $tick->pricing = $this->marketEngine->calculateNextPrice(\App\DTO\MarketPricingContext::forStock(
            $stock,
            $board->macro,
            $tick->health,
            $this->anchorStakes,
            $board->dt,
            $tick->dealShock,
            $board->priorMarketVol,
            $this->fundImpactVariance[$stock->getTicker()] ?? 0.0,
            $this->marketVarianceTrend,
        ));
        $tick->price = $tick->pricing['price'];
        $tick->volatility = $tick->pricing['next_volatility'];

        if ($tick->pricing['shock'] !== null) {
            $board->events[] = $this->eventService->publish($stock, 'SHOCK', EventPresenter::shockHeadline($stock->getName() . ' shares', $tick->pricing['shock']), $tick->pricing['shock']);
        }
    }

    /**
     * Order flow price impact: the peak move is applied, a share of it relaxes away, and the share that stays is
     * accounted in the impact variance EMA. The tick's price and volatility then stand on the stock.
     */
    private function applyOrderFlow(StockTick $tick, BoardTick $board): void
    {
        $stock = $tick->stock;
        $dt = $board->dt;
        $tickFlow = $board->netOrderFlow[$stock->getTicker()] ?? 0.0;

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

        // The sovereign fund's rebalance slice for this tick, in currency, spread over the float the way any
        // cap-weighted holder's is: each name gets its share of the float the fund measured the board at.
        $fundTrade = $board->macro->sovereignFundTrade;
        $fundBoardFloatCap = $board->macro->boardFloatCap;
        $fundShares = ($fundTrade !== 0.0 && $fundBoardFloatCap > 0.0 && $tick->priceAtStart > 0.0)
            ? $fundTrade * ($tick->floatCapAtStart / $fundBoardFloatCap) / $tick->priceAtStart
            : 0.0;
        $tick->flowShares = $tickFlow;
        $tick->fundShares = $fundShares;

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
                -self::MAX_TICK_IMPACT_LOG_RETURN,
                min(self::MAX_TICK_IMPACT_LOG_RETURN, $flowImpact + $fundImpact),
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
                -self::MAX_TICK_IMPACT_LOG_RETURN,
                min(self::MAX_TICK_IMPACT_LOG_RETURN, $flowImpact - $corporateImpact),
            )) + (self::CORPORATE_FLOW_PERMANENT_IMPACT_SHARE * max(
                -self::MAX_TICK_IMPACT_LOG_RETURN,
                min(self::MAX_TICK_IMPACT_LOG_RETURN, $corporateImpact),
            ));
            $budgetedFundLogReturn = FinancialConstants::PERMANENT_IMPACT_SHARE * max(
                -self::MAX_TICK_IMPACT_LOG_RETURN,
                min(self::MAX_TICK_IMPACT_LOG_RETURN, $fundImpact),
            );
        }

        // The peak move lands now; its transient share then relaxes back at the resilience rate, every tick. The
        // company's own slice moves from the permanent share to the transient one.
        $corporateShift = (FinancialConstants::PERMANENT_IMPACT_SHARE - self::CORPORATE_FLOW_PERMANENT_IMPACT_SHARE) * $corporateLogReturn;
        $nextTransient = LiquidityEngine::transientImpactAfter($outstandingTransient, $impactLogReturn, $dt) + $corporateShift;
        $this->transientImpact[$stock->getTicker()] = $nextTransient;
        $impactPriceMove = (FinancialConstants::PERMANENT_IMPACT_SHARE * $impactLogReturn) - $corporateShift + ($nextTransient - $outstandingTransient);
        if ($impactPriceMove !== 0.0) {
            $tick->price = max(0.01, $tick->price * exp($impactPriceMove));
        }

        // Realized impact variance, annualized, as an exponentially weighted mean. This is what the
        // budget draws on, so it has to decay: a name that was heavily traded a year ago must not keep
        // reclaiming variance it no longer supplies. Read on the trading day's summed impact, so flow that
        // persists across ticks counts at the horizon it moves the price over, at any tick rate.
        if ($dt > 0.0) {
            $ticker = $stock->getTicker();
            $empty = ['sum' => 0.0, 'elapsed' => 0.0];
            // Policy news moves the price outside its diffusion as order flow does, so the diffusion gives it back
            // from the same measured budget.
            $policyLogReturn = log(max(1e-12, (float) ($tick->pricing['policy_repricing'] ?? 1.0)));
            $flowRead = TimeSeries::ewmaWindowedVariance(
                $stock->getImpactVarianceEma() ?? 0.0,
                $this->impactDay[$ticker]['flow'] ?? $empty,
                $budgetedImpactLogReturn + $policyLogReturn,
                $dt,
                self::IMPACT_MEASUREMENT_YEARS,
                self::IMPACT_VARIANCE_EMA_YEARS,
            );
            $fundRead = TimeSeries::ewmaWindowedVariance(
                $this->fundImpactVariance[$ticker] ?? 0.0,
                $this->impactDay[$ticker]['fund'] ?? $empty,
                $budgetedFundLogReturn,
                $dt,
                self::IMPACT_MEASUREMENT_YEARS,
                self::IMPACT_VARIANCE_EMA_YEARS,
            );
            $stock->setImpactVarianceEma($flowRead['variance']);
            $this->fundImpactVariance[$ticker] = $fundRead['variance'];
            $this->impactDay[$ticker] = ['flow' => $flowRead['window'], 'fund' => $fundRead['window']];
        }

        $stock->setPrice((string) $tick->price);
        $stock->setCurrentVolatility((string) $tick->volatility);
    }

    /**
     * Management's warning ahead of a quarter it already knows has gone wrong, the bank levy, and the quarter's report.
     * A report or a warning restates the books after the price step struck fair value on the old ones, so fair value is
     * re-struck on what was filed and the analysts and agents who act on it this tick read the news.
     */
    private function fileReports(StockTick $tick, BoardTick $board): void
    {
        $stock = $tick->stock;
        $macro = $board->macro;

        // Guidance: management warns ahead of a quarter it already knows has gone wrong.
        $warning = $this->earningsEngine->evaluatePreAnnouncement($stock, $macro, $board->tickCount, $board->ticksPerYear);
        if (!empty($warning)) {
            $board->events = array_merge($board->events, $warning);
        }

        $board->bankLevy += $tick->strategy->calculateAnnualBankLevy($stock, $macro);

        $tick->reportEvents = $this->earningsEngine->calculate($stock, $macro, $board->tickCount, $board->ticksPerYear);
        if (!empty($tick->reportEvents)) {
            $board->events = array_merge($board->events, $tick->reportEvents);
        }

        $tick->priceAfterReport = (float) $stock->getPrice();

        if ($tick->reportEvents !== null || !empty($warning)) {
            $tick->pricing = array_replace($tick->pricing, $this->marketEngine->strikeFairValue(
                \App\DTO\MarketPricingContext::forStock($stock, $macro, $this->debtEngine->analyzeDebtHealth($stock, $macro), $this->anchorStakes, 0.0, 0.0, $board->priorMarketVol),
            ));
        }
    }

    /**
     * TOTAL RETURN OF THE TICK
     *
     * The report pays the dividend and takes it off the price in the same step. A holder was paid that cash, so the
     * return the trend and the agents are scored on adds it back; measured on the price alone, every payment read as
     * a loss for whoever was long and a gain for whoever was short.
     */
    private function scoreReturn(StockTick $tick, BoardTick $board): void
    {
        $stock = $tick->stock;
        $dt = $board->dt;
        $priceAtStart = $tick->priceAtStart;
        $priceAfterReport = $tick->priceAfterReport;

        $dividendPaidPerShare = 0.0;
        foreach ($tick->reportEvents ?? [] as $generatedEvent) {
            $dividendPaidPerShare += (float) ($generatedEvent['dividend_per_share'] ?? 0.0);
        }

        // Index dividend points: float-adjusted dividend cash per ticker for index point calculations.
        if ($dividendPaidPerShare > 0.0) {
            $board->dividendPoints[$stock->getTicker()] = $dividendPaidPerShare
                * (float) $stock->getSharesOutstanding()
                * max(0.0, min(1.0, (float) $stock->getPublicFloatPercentage()));
            $board->strategicStakeCash += $dividendPaidPerShare
                * (float) $stock->getSharesOutstanding()
                * StrategicHoldings::stake($stock->getTicker());
        }

        $tick->logReturn = $priceAtStart > 0.0 && $priceAfterReport > 0.0
            ? log(($priceAfterReport + $dividendPaidPerShare) / $priceAtStart)
            : 0.0;

        // The board's float-weighted price return, measured before any split like the trend is: the fund
        // holds the float, so a name weighs what its float was worth when the tick began.
        if ($tick->floatCapAtStart > 0.0 && $priceAtStart > 0.0) {
            $board->floatCapAtStart += $tick->floatCapAtStart;
            $board->priceGain += $tick->floatCapAtStart * (($priceAfterReport / $priceAtStart) - 1.0);
        }

        // PRICE MOMENTUM (Jegadeesh & Titman 1993)
        // An exponentially weighted sum of log returns: trend_t = phi * trend_{t-1} + r_t, where phi is a
        // decay set by a horizon in YEARS, so the formation window stays a half-year of simulated time at
        // any tick rate. Measured before splits, since a 4-for-1 split quarters the price without any
        // economic return and would otherwise register as a violent crash.
        if ($priceAtStart > 0.0 && $priceAfterReport > 0.0) {
            $momentumPhi = exp(-$dt / self::MOMENTUM_FORMATION_YEARS);
            $updatedTrend = (($stock->getPriceMomentumTrend() ?? 0.0) * $momentumPhi) + $tick->logReturn;
            $stock->setPriceMomentumTrend(
                max(-self::MAX_MOMENTUM_TREND, min(self::MAX_MOMENTUM_TREND, $updatedTrend)),
            );
        }

        // Realized variance EMA: annualized trailing window variance tracked for index and screener selection.
        if ($dt > 0.0 && $priceAtStart > 0.0 && $priceAfterReport > 0.0) {
            $stock->setRealizedVarianceEma(TimeSeries::ewmaAnnualizedVariance(
                $stock->getRealizedVarianceEma() ?? 0.0,
                $tick->logReturn,
                $dt,
                self::INDEX_TRAILING_VOLATILITY_YEARS,
            ));
        }
    }

    /**
     * CORPORATE ACTIONS (SPLITS)
     *
     * The company's own issuance and buybacks settle with the holders who keep their percentage, then a split restates
     * the price, the fair value and the analysts' targets in the new units.
     */
    private function settleShareCount(StockTick $tick, BoardTick $board): void
    {
        $stock = $tick->stock;
        $price = $tick->priceAfterReport;

        // Read again here rather than reusing the count from the top of the tick: issuance and buybacks
        // in the earnings engine change it, and only a split should reach the agents as a share ratio.
        $preSplitShares = (float) $stock->getSharesOutstanding();

        // The company's own issuance net of buybacks, as float at this tick's price, measured in pre-split shares
        // so a split -- a change of units, not of ownership -- never reads as either.
        $board->netIssuance += (($preSplitShares * max(0.0, min(1.0, (float) $stock->getPublicFloatPercentage()))) - $tick->floatSharesAtStart)
            * $price;
        $board->strategicStakeCash -= StrategicHoldings::stake($stock->getTicker()) * ($preSplitShares - $tick->sharesAtStart) * $price;
        // The anchor holders keep their percentage the same way, out of their own treasuries.
        $this->anchorStakes->settleProRata($stock, $preSplitShares - $tick->sharesAtStart, $price);

        $splitResult = $this->corporateActionEngine->processSplits(
            $stock,
            $price,
            $preSplitShares,
            $board->macro->totalTime,
        );

        // Unpack the results
        $tick->finalPrice = $splitResult['price'];
        $tick->shares = $splitResult['shares'];
        $splitEvent = $splitResult['event'] ?? null;

        // Restate perceived fair value and analyst targets for corporate stock splits.
        $tick->splitRatio = $preSplitShares > 0.0 ? $tick->shares / $preSplitShares : 1.0;
        $restateForSplit = $tick->splitRatio > 0.0 && $tick->splitRatio !== 1.0 && is_finite($tick->splitRatio);

        $tick->perceivedFairValue = (float) $tick->pricing['perceived_fair_value'];
        $tick->analystTargets = $tick->pricing['analyst_targets'];

        if ($restateForSplit) {
            $splitRatio = $tick->splitRatio;
            $tick->perceivedFairValue /= $splitRatio;
            $tick->analystTargets = array_map(
                static fn(float $target): float => $target / $splitRatio,
                $tick->analystTargets,
            );
        }

        // The equity the fundamentals support, which the next tick's beta is levered on.
        $stock->setFairEquityValue($tick->perceivedFairValue * $tick->shares);

        // Sell-side analyst price targets: infrequent discrete revisions anchored to fair value.
        $analystRevision = $this->reviseAnalystTarget($stock, $tick->perceivedFairValue, $restateForSplit ? $tick->splitRatio : 1.0);

        if ($analystRevision !== null) {
            $board->events[] = $analystRevision;
        }

        // Always update the price
        $stock->setPrice((string) $tick->finalPrice);

        // Check if the math actually changed
        if ($tick->sharesBeforeReport !== $tick->shares) {
            $stock->setSharesOutstanding((string) $tick->shares);
        }

        // Only push the event to the array if one was actually generated
        if ($splitEvent) {
            $board->events[] = $splitEvent;
        }
    }

    /**
     * The name on the board, the wire and the agents' screens: its capitalisation and float, what it costs to trade,
     * the volume it printed, its display row, and the orders the institutional agents place for the next tick.
     */
    private function publish(StockTick $tick, BoardTick $board, bool $recordHistory): void
    {
        $stock = $tick->stock;
        $macro = $board->macro;
        $dt = $board->dt;
        $finalPrice = $tick->finalPrice;

        // Calculate Market Cap
        $currentMarketCap = $finalPrice * $tick->shares;
        $board->totalCap += $currentMarketCap;

        // What a passive fund could actually buy of this name, which is what an index weights on: the
        // part of the company that trades. Published per ticker so the index can sum its OWN members
        // rather than the whole board.
        $board->floatCaps[$stock->getTicker()] = IndexCommittee::floatAdjustedCap($stock);

        // Shares printed this tick, at least the net flow the tick cleared: a name a large order hits shows it.
        // What it costs to get in and out of this name. Published per ticker as well as on the wire,
        // because a fund's arbitrage band is the weighted average of its constituents' — an expensive
        // basket is what lets a fund drift from it.
        $halfSpread = $this->liquidityEngine->halfSpreadFraction($stock);
        $board->halfSpreads[$stock->getTicker()] = $halfSpread;

        $tickVolume = $this->liquidityEngine->simulateTickVolume($stock, $dt, abs($tick->flowShares + $tick->fundShares));
        $board->tradedValue += $tickVolume * $finalPrice;

        // What the name prints on an ordinary tick, which is what makes the realized figure abnormal
        // or not. The level alone is a size statistic: a mega-cap always prints more than a micro-cap
        // and neither fact is news.
        $expectedTickVolume = $this->liquidityEngine->averageDailyVolume($stock)
            * $dt * FinancialConstants::TRADING_DAYS_PER_YEAR;

        // Determine if a fundamental corporate event occurred this tick
        $isFundamentalTick = !empty($tick->reportEvents) || $tick->struckDeal;

        // Everything below is display data that every connected browser receives on every tick, so
        // it is carried at the precision a screen can show. The full-precision figures live on the
        // entity and in the engines; a fair value with fifteen decimals was a third of the payload.
        $stockUpdate = [
            'ticker' => $stock->getTicker(),
            'sector' => $stock->getSector(),
            'industry' => $stock->getIndustry() ?: 'General',
            'price' => round($finalPrice, 2),
            'market_cap' => round($currentMarketCap),
            'current_volatility' => round($tick->volatility * 100, 2),
            // Backwards compatible fix so frontend JS updates the UI with ROE for banks
            'current_roic' => $tick->effectiveReturn,
            'current_roe' => (float) $stock->getCurrentRoe() != 0.0 ? (float) $stock->getCurrentRoe() : (float) $stock->getBaselineRoe(),
            'shares' => $tick->shares,
            'eps' => (float) $stock->getEarningsPerShare(),
            'treasury' => (float) $stock->getCorporateTreasury(),
            'equity' => (float) $stock->getTotalEquity(),
            'invested_capital' => $stock->getInvestedCapital(),
            'debt_ratio' => (float) $stock->getDebtToEquityRatio(),
            'credit_rating' => $stock->getCreditRating(),
            'credit_spread' => (float) $stock->getDynamicCreditSpread(),
            'analyst_targets' => array_map(static fn(float $target): float => round($target, 2), $tick->analystTargets),
            'analyst_price_target' => $stock->getAnalystPriceTarget() !== null ? round((float) $stock->getAnalystPriceTarget(), 2) : null,
            'analyst_rating' => $stock->getAnalystRating(),
            'perceived_fair_value' => round($tick->perceivedFairValue, 2),
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
            $evaluationCapital = $tick->strategy->getEvaluationCapital((float) $stock->getTotalEquity(), $stock->getInvestedCapital());
            $addressableShare = min(
                FinancialConstants::MAX_ADDRESSABLE_MARKET_SHARE,
                $this->corporateMetrics->calculateAddressableShare($stock, $evaluationCapital, $macro),
            );

            $stockUpdate['market_share'] = round($addressableShare * 100, 2);
        }

        // Institutional agent flow: agents evaluate market view and generate orders for the subsequent tick.
        $this->agentFlow->trade(new \App\DTO\AgentMarketViewDTO(
            ticker: $stock->getTicker(),
            price: $finalPrice,
            perceivedFairValue: $tick->perceivedFairValue,
            momentumTrend: (float) ($stock->getPriceMomentumTrend() ?? 0.0),
            averageDailyVolume: $this->liquidityEngine->structuralDailyVolume($stock),
            logReturn: $tick->logReturn,
            financialConditions: $macro->financialConditionsIndex,
            dt: $dt,
            riskFreeRate: $macro->policyRate,
            annualizedVolatility: (float) $tick->volatility,
            splitRatio: $tick->splitRatio,
            passiveOwnershipMultiple: $board->passiveOwnership === []
                ? 1.0
                : ($board->passiveOwnership[$stock->getTicker()] ?? 0.0),
            // What attention-driven retail sorts on (Barber & Odean 2008): how hard this name is
            // trading relative to its own normal, and whether anything happened to it. Both are
            // already computed above for other reasons, so neither costs a second pass.
            abnormalVolume: $expectedTickVolume > 0.0 ? ($tickVolume / $expectedTickVolume) : 1.0,
            hasNews: $isFundamentalTick,
        ));

        $board->updates[] = $stockUpdate;

        if ($recordHistory) {
            $board->history[] = [
                'stock_id' => $stock->getId(),
                'price' => $finalPrice,
                'ticker' => $stock->getTicker(),
            ];
        }
    }

    /**
     * A bankrupt shell's row: pinned where it failed, with nothing to price.
     *
     * @return array<string, mixed>
     */
    private static function bankruptRow(Stock $stock): array
    {
        return [
            'ticker' => $stock->getTicker(),
            'sector' => $stock->getSector(),
            'industry' => $stock->getIndustry() ?: 'General',
            'price' => (float) $stock->getPrice(),
            'market_cap' => (float) $stock->getPrice() * (float) $stock->getSharesOutstanding(),
            // Reported as a percentage, matching the live branch; a bankrupt shell is pinned at zero either way, but
            // the two rows feed the same field on the same UI.
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

        $fresh = $perceivedFairValue * (1.0 + self::ANALYST_TARGET_OPTIMISM);

        // Nobody has covered this name before. Initiating coverage is not a revision of anything.
        if ($standing === null || $standing <= 0.0) {
            $stock->setAnalystPriceTarget((string) $fresh);

            return null;
        }

        $drift = ($fresh - $standing) / $standing;

        if (abs($drift) < self::ANALYST_TARGET_REVISION_THRESHOLD) {
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
            0.0,
        );
    }

}
