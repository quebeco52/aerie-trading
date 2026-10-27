<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Stock;
use App\Service\Model\BusinessModelInterface;

/**
 * Context object used to pass simulation state through the EarningsEngine pipeline.
 */
class EarningsSimulationContext
{
    public function __construct(
        public readonly Stock $stock,
        public readonly MacroStateDTO $macroState,
        public readonly BusinessModelInterface $strategy,
        public readonly string $businessModel,
        public readonly float $dt = 0.25,
    ) {}

    public float $investedCapital = 0.0;
    public float $corporateTaxRate = 0.0;
    public float $baselineRoic = 0.0;
    /**
     * Book equity over total assets as the quarter opens, for a firm that manages toward a capital target; null
     * otherwise.
     */
    public ?float $openingCapitalRatio = null;
    public float $baselineVol = 0.0;
    public float $sharesOutstanding = 0.0;
    public float $stableMargin = 0.0;
    /**
     * The firm's run costs, fixed and variable, over what they would be at its structural margin: (1 - m') / (1 - m) at
     * the margin its franchise power gives it (Stock::$franchisePower).
     */
    public float $franchiseCostScale = 1.0;
    /** What the franchise takes off (or adds to) the structural quarter's run costs, before the season. */
    public float $franchiseCostSaving = 0.0;
    public float $seasonalFactor = 1.0;
    /** The quarter's fixed costs over their even-year level: the seasonal share of the base following the season. */
    public float $seasonalFixedCostFactor = 1.0;
    public float $priorSeasonalFactor = 1.0;
    public float $seasonallyAdjustedRevenue = 0.0;
    public float $seasonallyAdjustedEbit = 0.0;
    /**
     * The deseasonalized quarter's net income, taxed as the quarter is and net of the levy: what the run rate is struck
     * on.
     */
    public float $seasonallyAdjustedNetIncome = 0.0;
    public float $structuralOperatingMargin = 0.0;
    /** Calendar quarter (0-3) this report covers; drives seasonality. */
    public int $calendarQuarter = 0;
    /** Fiscal quarter (0-3) after the ticker's fiscal-year offset; drives annual events like the impairment test. */
    public int $fiscalQuarter = 0;
    public int $tickCount = 0;
    public int $ticksPerYear = 252;

    // Capacity & Revenue
    public float $capacityUtilization = 0.0;
    /** Capacity utilization without the firm's own demand shock and jump this quarter: the output analysts forecast. */
    public float $capacityUtilizationBeforeShocks = 0.0;
    /**
     * The volume a model moves inside its own sector physics, as a factor on capacity utilisation: 1 plus
     * resolveSectorActivityShift() at the opening order book, so every reader of the plant's load sees the same
     * activity.
     */
    public float $sectorActivityFactor = 1.0;
    public float $structuralRevenue = 0.0;
    public float $expectedRevenue = 0.0;
    /**
     * Expected revenue without the firm's own demand shock and jump this quarter: the base the consensus is formed on.
     */
    public float $expectedRevenueBeforeShocks = 0.0;
    /** Log gap between the price level and the one the plant book carries at its planned nominal growth. */
    public float $priceLevelSurprise = 0.0;
    public float $fixedCosts = 0.0;
    public float $baselineVariableMargin = 0.0;
    /**
     * The quarter's depreciation on the plant's schedule, carved out of the cost base: what it charges at structural
     * output.
     */
    public float $structuralDepreciation = 0.0;
    /**
     * The depreciation charge analysts forecast: the schedule, or for a units-of-production plant the output before its
     * own shocks.
     */
    public float $expectedDepreciation = 0.0;
    /** Construction in progress placed in service this quarter; leaves CIP and enters gross PP&E. */
    public float $completedCip = 0.0;
    /** Current capital-goods price level over the vintage the plant was bought at; 1.0 when prices have not moved. */
    public float $replacementCostRatio = 1.0;
    /** Capex spent replacing depreciated plant at today's capital-goods prices this quarter. */
    public float $maintenanceCapex = 0.0;
    /**
     * Growth plant the flexible accelerator still allows this quarter after the earnings engine's own; null where no
     * plant budget applies (a lender).
     */
    public ?float $expansionBudget = null;
    /** Lower-of-cost-or-NRV writedown on unsold inventory this quarter (ASC 330), non-cash. */
    public float $inventoryWriteDown = 0.0;
    /**
     * Long-lived assets written down this quarter by the business model's own impairment test (ASC 360-10), non-cash.
     */
    public float $plantImpairment = 0.0;
    /**
     * Expected credit loss provision against trade receivables this quarter (ASC 326), non-cash; negative when
     * released.
     */
    public float $receivablesProvision = 0.0;
    /** Change in net working capital this quarter; a build consumes cash, a release frees it. */
    public float $deltaWorkingCapital = 0.0;
    /**
     * Every impairment charged against EBIT after the operating cost base was struck: inventory written to
     * net realizable value, the trade receivable allowance, and the credit-loss level correction. Negative
     * when releases outweigh charges. Tracked as one figure because the seasonally adjusted run-rate is
     * rebuilt from revenue and cost ratios rather than deseasonalized from EBIT, so it has to be told what
     * else came out of EBIT or the solvency tests read a margin the firm did not earn.
     */
    public float $impairmentCharges = 0.0;
    /**
     * One-time termination benefits recognised this quarter for the committed cost base the firm cut
     * (ASC 420-10). Like the impairments above it sits in operating expense, and no analyst forecasts it.
     */
    public float $restructuringCharge = 0.0;
    /**
     * One-off operating charge the sector physics booked this quarter (a product recall, a regulator's fine); in EBIT,
     * forecast by no analyst.
     */
    public float $eventCharge = 0.0;
    /**
     * The quarter's special items before tax (restructuring, event charges and plant write-downs): in GAAP EBIT, kept
     * out of the run rate and the Street figure.
     */
    public float $specialCharges = 0.0;
    /**
     * Special items after tax plus any goodwill impairment: what GAAP net income carries that Street earnings exclude.
     */
    public float $specialItems = 0.0;
    /**
     * Opening deferred-tax balances booked straight to retained earnings on a row's first report under ASC 740 (ASC 250
     * cumulative effect): not income.
     */
    public float $cumulativeEffectAdjustment = 0.0;
    /** The committed cost base this quarter, as a fraction of the base structural capacity implies. */
    public float $committedCostScale = 1.0;
    /** How far that base was cut this quarter; zero when the firm held or rebuilt it. */
    public float $committedCostCut = 0.0;
    /**
     * Deferred part of the quarter's tax expense (ASC 740), non-cash: accelerated tax depreciation, a write-down the
     * tax books defer, a rate change's re-strike, the carryforward's asset.
     */
    public float $deferredTaxExpense = 0.0;
    /** Tax that actually left the company this quarter; total expense less the deferred portion. */
    public float $cashTaxPaid = 0.0;
    /** The plant's depreciation on the tax books this quarter (200% declining balance on the tax basis). */
    public float $taxDepreciation = 0.0;
    /** Income on the tax return: pretax income less the plant's timing differences, before the loss carryforward. */
    public float $taxableIncome = 0.0;

    // Variables
    public float $realizedVariableMargin = 0.0;
    /** The variable cost ratio the quarter was expected to run at: the margin's drift step without its own draw. */
    public float $publicVariableMargin = 0.0;
    /** The quarter as the public saw it before the report (OperatingStrategyInterface::measurePublicFinancials). */
    public ?ActualFinancialsDTO $publicActuals = null;

    // Analyst
    public float $analystExpectedRevenue = 0.0;
    public float $analystExpectedVariableCosts = 0.0;
    public float $expectedEbit = 0.0;

    // Actuals
    public float $actualRevenue = 0.0;
    public float $actualVariableCosts = 0.0;
    public float $ebitda = 0.0;
    public float $ebit = 0.0;
    public ?string $eventType = null;
    public array $eventContext = [];
    public array $streamRevenue = [];
    /** Mandatory CapEx committed by the sector physics this quarter (spectrum, grid rebuild), in dollars. */
    public float $scheduledCapex = 0.0;
    /**
     * Fraction of this quarter's demand taken by (negative) or ceded from (positive) same-industry rivals'
     * idiosyncratic gains.
     */
    public float $rivalShareDrain = 0.0;

    /**
     * The firm's reach into the market it sells into (CorporateMetrics::calculateScaleRatio); weighs its build and its
     * gains in the industry ledger.
     */
    public float $addressableShare = 0.0;
    /**
     * Volume shift from the firm's own real price change (own-price elasticity times price growth above expected
     * inflation).
     */
    public float $ownPriceVolumeShift = 0.0;
    /**
     * Realized price level relative to a balanced industry: below one when the industry's installed capacity outruns
     * trend demand.
     */
    public float $industryPriceLevel = 1.0;
    /**
     * Share of the plant that runs once the industry has cleared: below one when a glut has driven the price to the
     * lowest the roster sells at and buyers take less than it has built. Idle plant makes no goods, so the inventory
     * readers leave it out.
     */
    public float $plantLoad = 1.0;
    /**
     * For a company on one plant, the share of its running plant whose wear it replaces: below one while its new plant
     * would not earn its cost and it runs more plant than its market needs (CapitalBudgetEngine).
     */
    public float $plantReplaced = 1.0;
    /** The level the public expects the firm's industry demand to reach against its expected path (1 = the opening). */
    public float $industryOutlook = 1.0;
    /**
     * For a company that keeps plant by segment (SegmentBooks), each segment's quarter: its plant share, its share of
     * its own market, the price its industry pays it, the share of its plant that runs, the growth capex it was
     * allotted and what its plan still allows after it, and the level of its own path where its model plans one.
     * Empty for any other.
     *
     * @var array<string, array{plant: float, sales: float, margin: float, turnover: float, market: float, price: float, load: float, drain: float, growth: float, leftover: float, path?: float, capital_gap?: float, gap?: float, return?: float, builds?: bool, replaced?: float}>
     */
    public array $segments = [];
    /**
     * What the segments a company built into new industries still lose while they learn the trade, over its revenue at
     * the balanced price (SegmentBooks::learningDrag()); a cost the margin the books carry leaves out.
     */
    public float $ventureLearningDrag = 0.0;
    /**
     * The industry a company started building into this quarter, the segment it opened there, the capex committed and
     * how close the industry is to its own (CapitalBudgetEngine); null in any other quarter.
     *
     * @var array{industry: string, segment: string, capex: float, closeness: float}|null
     */
    public ?array $marketEntry = null;
    /** The plant base a company that keeps plant by segment planned its segments' growth on (net PP&E). */
    public float $segmentPlantBase = 0.0;
    /** The quarter's idiosyncratic demand shock to utilisation. */
    public float $idiosyncraticDemandShock = 0.0;
    /** The quarter's fundamental jump in utilisation. */
    public float $jumpMagnitude = 0.0;
    /** Capital that earns revenue: invested capital less construction in progress and, outside finance, goodwill. */
    public float $revenueGeneratingCapital = 0.0;
    /** Quarterly revenue per unit of revenue-generating capital at a balanced price level. */
    public float $assetTurnover = 0.0;
    /** The firm as the macro physics first read it this quarter, before any lag state was stepped. */
    public ?Stock $macroPhysicsBasis = null;
    /**
     * What each macro input the model reads did to each revenue stream this quarter: stream => field => the stream's
     * revenue over what it would have been with that input at its neutral reading, less one.
     *
     * @var array<string, array<string, float>>
     */
    public array $streamMacroEffects = [];
    /** Quarterly stock-based compensation (ASC 718): non-cash expense inside the cost base, settled in shares. */
    public float $stockCompensation = 0.0;
    /** Goodwill written down this quarter under the annual impairment test (ASC 350), non-cash. */
    public float $goodwillImpairment = 0.0;
    /** Bank levy charged this quarter, after tax. */
    public float $bankLevy = 0.0;
    /** Provision for credit losses on the earning-asset book charged this quarter, net of any release (financials). */
    public float $creditLossProvision = 0.0;
    /**
     * The provision analysts carry in the estimate: the through-the-cycle charge on the book they can see (financials).
     */
    public float $expectedCreditLossProvision = 0.0;
    /** Earning assets written off against the allowance this quarter (financials). */
    public float $netChargeOffs = 0.0;
    /**
     * Funding cost the duration squeeze added to the cost ratio this quarter, which the net interest margin deducts
     * (banks).
     */
    public float $netInterestSqueeze = 0.0;
    /** Cash deployed into new earning assets less assets sold (financials). */
    public float $netLoanOriginations = 0.0;
    /** Loss realized on earning assets sold below carrying value, booked to equity as other comprehensive loss. */
    public float $assetSaleLoss = 0.0;
    /**
     * The board's dividend return a year since the last report on the treasury's equity sleeve, booked in place of its
     * expected return; null on a first report, which has no earlier reading.
     */
    public ?float $equitySleeveDividendReturn = null;
    /** The price mark the equity sleeve took to book value at this report, net of tax. */
    public float $equitySleeveMark = 0.0;
    /**
     * Mark carried on the investment securities book after this quarter's roll (ASC 320); negative is a loss. Not
     * income.
     */
    public float $unrealizedSecuritiesMark = 0.0;
    /** Quarterly net cash from operations (net income + D&A - working capital build). */
    public float $operatingCashFlow = 0.0;
    /** Quarterly net cash from investing (negative = net investment). */
    public float $investingCashFlow = 0.0;
    /** Quarterly net cash from financing (positive = net capital raised). */
    public float $financingCashFlow = 0.0;
    /** Dickinson life-cycle stage classified from this quarter's cash-flow signs. */
    public ?\App\Data\Company\LifecycleStage $lifecycleStage = null;
    /**
     * Net accrual booked this quarter to steer reported earnings toward consensus; negative when a prior quarter's
     * borrowing unwinds.
     */
    public float $managedAccrual = 0.0;
    /**
     * That accrual before tax, as the filed statement carries it in the operating lines; the tax on it is deferred,
     * since no cash moved.
     */
    public float $managedAccrualPretax = 0.0;

    /** @var array<string, float> Reported operating KPIs emitted by the sector physics. */
    public array $kpis = [];

    // Interest & Depreciation
    public float $expectedInterestExpense = 0.0;
    public float $quarterlyInterestExpense = 0.0;
    public float $quarterlyInterestIncome = 0.0;
    public float $quarterlyDepreciation = 0.0;
    public float $trueOperatingMargin = 0.0;
    public float $operatingCosts = 0.0;
    /** Set by calculateInterestAndRunRates, before any later step reads it. */
    public DebtMetricsDTO $debtMetrics;

    // Net Income
    public float $preTaxIncome = 0.0;
    public float $taxPaid = 0.0;
    public float $expectedQuarterlyNetIncome = 0.0;
    public float $actualQuarterlyNetIncome = 0.0;
    public float $reportedExpectedNetIncome = 0.0;
    public float $reportedActualNetIncome = 0.0;
    /** Dollars the published EPS estimate sits below the unbiased one (MarketConsensusEngine::epsWalkDown()). */
    public float $consensusWalkDown = 0.0;
    /** The sd of the firm's quarterly earnings surprise in dollars, as its record measures it. */
    public float $earningsSurpriseSd = 0.0;
    public float $truePostTaxReturn = 0.0;

    // EPS & Gap
    public float $actualAnnualEpsRaw = 0.0;
    public float $actualQuarterlyEps = 0.0;
    /** EPS before special items (Bradshaw & Sloan 2002 "Street" earnings): the figure consensus is struck against. */
    public float $streetQuarterlyEps = 0.0;
    public float $expectedQuarterlyEps = 0.0;
    public float $surpriseAmountQuarterly = 0.0;
    /** Fair value per share struck on the books before the report; its revision is what the price gaps on. */
    public float $fairValueBeforeReport = 0.0;
    /** The cost of equity that fair value was struck at: the return it was expected to earn by this report. */
    public float $costOfEquityBeforeReport = 0.0;
    public float $priceGapPct = 0.0;

    // FCF & Capital
    /** Quarterly free cash flow to the firm per share, before capital allocation (calculateFreeCashFlow()). */
    public float $freeCashFlowPerShare = 0.0;
    /** Quarterly operating capex (maintenance, growth, scheduled), before the expansion the allocation funds. */
    public float $operatingCapex = 0.0;
    public array $allocation = [];
    public float $totalReportedCapex = 0.0;
    public float $trueQuarterlyFcf = 0.0;

    // Market Event
    public float $totalShockPct = 0.0;
    public string $corporateActionDescriptions = "";
    /**
     * What came with the report, as the card lists it: a dividend, buyback or bond issue with its figures, else a note.
     *
     * @var list<array<string, mixed>>
     */
    public array $corporateActions = [];

    // Health & Value
    /** Set by reconcileTaxesAndNetIncome, before any later step reads it. */
    public DebtHealthDTO $health;
    public float $wacc = 0.0;
    public float $quarterlyEconomicProfit = 0.0;
}
