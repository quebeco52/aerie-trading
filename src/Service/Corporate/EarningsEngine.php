<?php

declare(strict_types=1);

namespace App\Service\Corporate;

use App\DTO\EarningsSimulationContext;
use App\DTO\MarketPricingContext;
use App\Entity\Stock;
use App\Service\Corporate\Earnings\CapitalBudgetEngine;
use App\Service\Corporate\Earnings\CreditLossLedger;
use App\Service\Corporate\Earnings\FixedAssetLedger;
use App\Service\Corporate\Earnings\IncomeTaxLedger;
use App\Service\Corporate\Earnings\InvestmentMarkLedger;
use App\Service\Corporate\Earnings\OperatingCapacityEngine;
use App\Service\Corporate\Earnings\WorkingCapitalLedger;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Corporate\Segment\SegmentBooks;
use App\Service\Corporate\Segment\SegmentOutlook;
use App\Service\Event\EarningsReportedEvent;
use App\Service\Market\Pricing\MarketConsensusEngine;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Market\Ticker\TickCadence;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use App\Service\Math\Valuation;
use App\Service\Model\BusinessModelInterface;
use App\Service\News\MarketEventPublisher;
use App\Service\News\NarrativeEngine;
use App\Service\News\WireFormat;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Files a firm's quarterly earnings report: runs the quarter through its ledgers in order (the operating side,
 * the plant, working capital, credit losses, income tax, the capital budget and the investment marks), strikes the
 * income statement against consensus, lets the board allocate the cash, and prices the report.
 */
class EarningsEngine
{
    // --- Expected EBIT ---
    /** Failsafe max expected EBIT loss relative to structural revenue. */
    public const MAX_EBIT_LOSS_RATIO = 0.50;

    // --- Earnings Reporting Season ---
    /** Fraction of quarter that passes before the earnings reporting season opens (~14 ticks). */
    public const EARNINGS_REPORTING_LAG_RATIO = 0.22;
    /** Duration of the clustered earnings reporting window as a fraction of the quarter (~25 ticks). */
    public const EARNINGS_SEASON_LENGTH_RATIO = 0.40;

    // --- Fiscal Calendar ---
    /** Fiscal quarter index at which the annual goodwill impairment test runs (fiscal Q4). */
    public const FISCAL_YEAR_END_QUARTER = 3;

    // --- Report Price Response ---
    /**
     * Share of a report's fair-value revision priced in the announcement window; the other 40% is the post-announcement
     * drift on a weekday report (DellaVigna & Pollet 2009, J. Finance 64(2), abstract).
     */
    public const REPORT_IMMEDIATE_RESPONSE_SHARE = 0.60;

    // --- Announcement Variance ---
    /**
     * Years the announcement-variance estimate averages over: two, the eight reports SUE is conventionally scaled over
     * (Foster, Olsen & Shevlin 1984).
     */
    public const ANNOUNCEMENT_VARIANCE_EMA_YEARS = 2.0;

    // --- Trailing Twelve Month Earnings ---
    /** Number of reported quarters summed into the trailing twelve month earnings figure. */
    public const TTM_QUARTERS = 4;
    /**
     * Absolute clamp on stored trailing net income, just inside the DECIMAL(30, 4) money column the EPS bridge also
     * guards (Stock::MAX_MONEY_AMOUNT).
     */
    public const MAX_ABSOLUTE_NET_INCOME = 9.9e25;

    // --- Bayesian Analyst Consensus ---
    /**
     * Stream-state key: the pre-shock expected revenue the last consensus was formed against, so the analyst anchor can
     * be carried forward with the base rather than frozen at last quarter's size.
     */
    public const STATE_LAST_EXPECTED_REVENUE = 'state:last_expected_revenue';
    /**
     * Carried stream-state key: the firm's surprise record, its weighted sum of squared EPS surprises on revenue
     * (MarketConsensusEngine::recordSurprise()).
     */
    public const STATE_SURPRISE_SQUARES = self::CARRIED_STATE_PREFIX . 'surprise_squares';
    /** Carried stream-state key: the surprise record's total weight. */
    public const STATE_SURPRISE_WEIGHT = self::CARRIED_STATE_PREFIX . 'surprise_weight';

    // --- Carried Stream State ---
    /**
     * Prefix for state kept on the stream map by anything outside the quarterly physics: each report rebuilds the map
     * from what the physics registers, and carries a key under this prefix until its owner removes it.
     */
    public const CARRIED_STATE_PREFIX = 'carry:';

    // --- Institutional & Market Architecture ---
    /** Circuit breaker limiting the price move of a single quarterly earnings report to +/-40%. */
    public const MAX_QUARTERLY_PRICE_CIRCUIT_BREAKER = 0.40;

    // --- Earnings Pre-Announcements (Kasznik & Lev 1995) ---
    /**
     * Share of a quarter before the scheduled report at which management closes the books far enough to know it will
     * miss.
     */
    public const PREANNOUNCEMENT_LEAD_RATIO = 0.10;
    /**
     * Known shortfall, as a fraction of structural quarterly earnings, at which management warns rather than let the
     * market find out on the day.
     */
    public const PREANNOUNCEMENT_WARNING_THRESHOLD = 0.20;
    /**
     * Share of the warned shortfall analysts take out of their estimate, so the report itself lands as a smaller
     * surprise.
     */
    public const PREANNOUNCEMENT_CONSENSUS_ABSORPTION = 0.80;
    /**
     * Floor on the operating margin that sizes structural earnings for a warning, so a break-even firm is scaled by its
     * revenue rather than by a near-zero print.
     */
    public const PREANNOUNCEMENT_MIN_MARGIN_SCALE = 0.05;

    // --- Equity Grant Burn Rate ---
    /**
     * Most shares a large company grants in a year, as a share of shares outstanding, by GICS sector: ISS 2026 S&P 500
     * value-adjusted burn-rate benchmarks (86th percentile).
     */
    public const EQUITY_BURN_RATE_CAP_BY_SECTOR = [
        'Information Technology' => 0.0215,
        'Communication Services' => 0.0172,
        'Consumer Discretionary' => 0.0133,
        'Financials'             => 0.0103,
        'Health Care'            => 0.0088,
    ];
    /**
     * ISS 2026 S&P 500 burn-rate benchmark for every other sector (energy, materials, industrials, staples, utilities,
     * real estate): 0.77% a year.
     */
    public const DEFAULT_EQUITY_BURN_RATE_CAP = 0.0077;

    // --- Goodwill Impairment ---
    /** Goodwill impairment smaller than this fraction of the goodwill balance is immaterial and not booked. */
    public const MIN_GOODWILL_IMPAIRMENT_FRACTION = 0.01;

    // --- Earnings Management (Burgstahler & Dichev 1997) ---
    /**
     * Largest shortfall against consensus management closes with accruals, in sds of the firm's own measured surprise:
     * half of one, so about one report in seven is a near miss and a fifth to a quarter of those are steered onto the
     * line, 3-4% of reports (a fifth of firms manage in a period, by ~10% of EPS: Dichev, Graham, Harvey & Rajgopal
     * 2013). The odds fall linearly to nothing across it, as the cost of manipulation rises with the gap.
     */
    public const EARNINGS_MANAGEMENT_MAX_GAP_SURPRISE_SDS = 0.50;
    /**
     * Cap on the accumulated managed-accrual balance as a fraction of total assets: past this the reversal is too large
     * to keep hiding.
     */
    public const EARNINGS_MANAGEMENT_MAX_BANK_RATIO = 0.02;
    /**
     * Quarterly fraction of the borrowed balance that unwinds back into reported earnings (Dechow & Dichev 2002 accrual
     * reversal).
     */
    public const EARNINGS_MANAGEMENT_REVERSAL_RATE = 0.25;
    /**
     * Most a managed quarter lands above consensus, per share: a cent, drawn uniformly, so it prints as a met or a
     * one-cent beat (Degeorge, Patel & Zeckhauser 1999: the excess mass sits at zero and one cent).
     */
    public const EARNINGS_MANAGEMENT_BEAT_CUSHION_PER_SHARE = 0.01;

    // --- Profitability Persistence (Fama & French 2000; Ohlson 1995) ---
    /**
     * Time constant in years of each firm's measured long-run return, its operating return through the cycle: five
     * years, the span Damodaran normalizes earnings over.
     */
    public const LONG_RUN_RETURN_EMA_YEARS = 5.0;
    /**
     * Time constant in years of the level a firm's filed returns revert to, which it is valued on: twenty years, as a
     * firm's own level is most of how its returns differ from its rivals' and moves only slowly, so a long average
     * forecasts the returns it goes on to file better than a short one.
     */
    public const ANCHOR_RETURN_EMA_YEARS = 20.0;
    /** Time constant in years of each firm's recent filed return, the good or bad year it is valued on: one. */
    public const TRAILING_RETURN_EMA_YEARS = 1.0;
    /** Carried stream-state key: the level the firm's filed returns revert to (ANCHOR_RETURN_EMA_YEARS). */
    public const STATE_ANCHOR_RETURN = self::CARRIED_STATE_PREFIX . 'anchor_return';
    /**
     * Carried stream-state key: the firm's recent filed return (TRAILING_RETURN_EMA_YEARS), which its valuation fades.
     */
    public const STATE_TRAILING_RETURN = self::CARRIED_STATE_PREFIX . 'trailing_return';

    /** The quarter's operating side: capacity, revenue and the cost base. */
    private readonly OperatingCapacityEngine $operations;
    /** The plant's book: depreciation, write-downs, replacement cost and the PP&E roll-forward. */
    private readonly FixedAssetLedger $plant;
    /** The trade cycle's balances and the charges when they go bad. */
    private readonly WorkingCapitalLedger $workingCapital;
    /** A lender's earning-asset book and its credit-loss allowance. */
    private readonly CreditLossLedger $creditLosses;
    /** Income tax: carryforward, deferred balances and the after-tax figures. */
    private readonly IncomeTaxLedger $incomeTax;
    /** The capital budget and the free cash flow it leaves. */
    private readonly CapitalBudgetEngine $capitalBudget;
    /** The securities book and the treasury's equity sleeve, marked through other comprehensive income. */
    private readonly InvestmentMarkLedger $investmentMarks;

    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private MarketEventPublisher $marketEvent,
        private CapitalAllocationEngine $capitalAllocationEngine,
        private DebtEngine $debtEngine,
        private CapExEngine $capExEngine,
        private MathUtility $mathUtility,
        private CorporateMetrics $corporateMetrics,
        private NarrativeEngine $narrativeEngine,
        private MarketConsensusEngine $marketConsensusEngine,
        /**
         * Zero-sum industry share ledger; null (unit tests, harnesses) means every firm's gain comes from a larger
         * market.
         */
        private ?IndustryShareLedger $industryShareLedger = null,
        /**
         * Listed anchor stakes. Required, not optional: a sphere with no ledger would silently never open a plant
         * ledger either. Holds only a per-tick price map, so a harness builds one free.
         */
        private AnchorStakeLedger $anchorStakes = new AnchorStakeLedger(),
        /**
         * Investment securities mark. Defaulted so a harness or a unit test builds an engine without wiring the curve.
         */
        SecuritiesBookService $securitiesBook = new SecuritiesBookService(new \App\Service\Market\Bond\BondPricingEngine()),
        /**
         * Strikes fair value before and after a report; its revision is the report's price news. It draws nothing, so a
         * default is safe.
         */
        private MarketEngine $marketEngine = new MarketEngine(new MathUtility()),
    ) {
        // Built here from the engine's own services, so every ledger draws from the one random stream and reads the
        // one industry ledger the engine was given.
        $this->plant = new FixedAssetLedger($corporateMetrics);
        $this->operations = new OperatingCapacityEngine($mathUtility, $corporateMetrics, $this->plant, $industryShareLedger);
        $this->workingCapital = new WorkingCapitalLedger($corporateMetrics);
        $this->creditLosses = new CreditLossLedger($corporateMetrics);
        $this->incomeTax = new IncomeTaxLedger($this->plant);
        $this->capitalBudget = new CapitalBudgetEngine($capExEngine, $corporateMetrics, $this->plant, $this->workingCapital, $industryShareLedger);
        $this->investmentMarks = new InvestmentMarkLedger($securitiesBook);
    }

    /**
     * @return list<array<string, mixed>>|null The published earnings event, or null for a bankrupt firm or a tick that
     *                                         is not its reporting date.
     */
    public function calculate(Stock $stock, \App\DTO\MacroStateDTO $macroState, int $tickCount = 0, int $ticksPerYear = 252): ?array
    {
        if ($stock->isBankrupt() || !$this->checkReportingEligibility($stock, $tickCount, $ticksPerYear)) {
            return null;
        }

        $businessModel = \App\Data\Company\Sectors::businessModelFor($stock->getIndustry());
        $strategy = \App\Data\Company\Sectors::getBusinessModelStrategy($businessModel);

        $ctx = new EarningsSimulationContext(
            $stock,
            $macroState,
            $strategy,
            $businessModel,
            FinancialConstants::QUARTER_YEARS,
        );
        $ctx->tickCount = $tickCount;
        $ctx->ticksPerYear = $ticksPerYear;
        [$ctx->fairValueBeforeReport, $ctx->costOfEquityBeforeReport] = $this->strikeFairValue($stock, $macroState);

        // Remarked before anything is struck on the balance sheet, because everything downstream is struck
        // ON it: a portfolio worth more carries more invested capital and upstreams more in dividends.
        $this->anchorStakes->markToMarket($stock, $strategy->getEffectiveTaxRate($macroState->corporateTaxRate));

        // The curve moved under the securities book too, and for the same reason it is struck here: the
        // mark is a balance-sheet fact every downstream valuation reads, not an earnings one. It reaches
        // equity through other comprehensive income and never touches EBIT, EPS or the surprise.
        $this->investmentMarks->rollForwardSecuritiesBook($ctx);
        // The equity the treasury holds is marked for the same reason, to the board's realised return.
        $this->investmentMarks->markEquitySleeve($ctx);

        $this->initializeContext($ctx);
        // Assets finished this quarter leave construction in progress; the ledger roll-forward below moves
        // them into gross PP&E, where they start earning revenue and start depreciating.
        $ctx->completedCip = $this->capExEngine->processCipQueue($ctx->stock);
        $this->operations->generateCapacityAndRevenue($ctx);
        $this->operations->processVariableMargins($ctx);
        $this->plant->calculateDepreciation($ctx);
        $this->calculateExpectedVsActualFinancials($ctx);
        $this->operations->applyRestructuringCharge($ctx);
        $this->workingCapital->applyWorkingCapitalCharges($ctx);
        $this->plant->applyPlantImpairment($ctx);
        $this->creditLosses->rollForwardCreditLossAllowance($ctx);
        $this->calculateInterestAndRunRates($ctx);
        $this->reconcileTaxesAndNetIncome($ctx);
        $this->manageReportedEarnings($ctx);
        $this->calculateEPSAndSurprise($ctx);
        $this->capitalBudget->calculateFreeCashFlow($ctx);
        $this->allocateCapital($ctx);
        $this->capitalBudget->rollSegmentPlant($ctx, (float) ($ctx->allocation['organic_capex'] ?? 0.0));
        $this->reportFreeCashFlow($ctx);
        $this->executeAnnouncementReturn($ctx);

        return $this->publishEventAndReport($ctx);
    }

    /**
     * Resolves the deterministic quarter tick on which a ticker reports earnings.
     * Clustered inside the post-quarter reporting season window: [lag, lag + season).
     */
    public static function resolveReportingTick(string $ticker, int $ticksPerYear): int
    {
        $ticksPerQuarter = TickCadence::intervalTicks($ticksPerYear, FinancialConstants::QUARTERS_PER_YEAR);
        $lagTicks    = (int) round($ticksPerQuarter * self::EARNINGS_REPORTING_LAG_RATIO);
        $seasonTicks = max(1, (int) round($ticksPerQuarter * self::EARNINGS_SEASON_LENGTH_RATIO));

        return $lagTicks + (abs(crc32($ticker)) % $seasonTicks);
    }

    /**
     * Ticks since the ticker last reached its reporting tick, in [0, ticks per quarter): zero on the tick it reports.
     */
    public static function ticksSinceReportingTick(string $ticker, int $tickCount, int $ticksPerYear): int
    {
        $ticksPerQuarter = TickCadence::intervalTicks($ticksPerYear, FinancialConstants::QUARTERS_PER_YEAR);

        return ((($tickCount % $ticksPerQuarter) - self::resolveReportingTick($ticker, $ticksPerYear)) % $ticksPerQuarter + $ticksPerQuarter) % $ticksPerQuarter;
    }

    /**
     * Calendar quarter (0 = Jan-Mar .. 3 = Oct-Dec) a report filed on this tick covers: the one before the quarter it
     * is filed in.
     */
    public static function coveredCalendarQuarter(int $tickCount, int $ticksPerYear): int
    {
        $ticksPerQuarter = TickCadence::intervalTicks($ticksPerYear, FinancialConstants::QUARTERS_PER_YEAR);

        return ((intdiv($tickCount, $ticksPerQuarter) - 1) % 4 + 4) % 4;
    }

    /**
     * The tick at which management has closed the books far enough to know the quarter has gone wrong.
     */
    public static function resolvePreAnnouncementTick(string $ticker, int $ticksPerYear): int
    {
        $ticksPerQuarter = TickCadence::intervalTicks($ticksPerYear, FinancialConstants::QUARTERS_PER_YEAR);
        $lead = max(1, (int) round($ticksPerQuarter * self::PREANNOUNCEMENT_LEAD_RATIO));

        return max(0, self::resolveReportingTick($ticker, $ticksPerYear) - $lead);
    }

    /**
     * Negative earnings pre-announcement (Kasznik & Lev 1995, "To Warn or Not to Warn").
     *
     * Firms heading into a large negative surprise disclose it ahead of the report far more often than
     * firms heading into a large positive one — the asymmetry is the finding, and it is driven by
     * litigation and reputation risk, not by symmetry of information. Only bad news is warned here.
     *
     * What management can honestly know before the close is what the firm is already carrying: the accrual
     * reversal that prior quarters borrowed and now owe back, and the input cost the closing quarter has put on
     * the cost base, projected from the lag state the last report persisted and today's input prices. Neither
     * peeks at a draw that has not happened, so this is genuine foreknowledge rather than a shadow copy of the
     * quarter.
     *
     * The warning is news only in what the estimate does not already carry: consensus reads the input-cost change
     * since the last report at the share analysts can read off published input prices (MarketConsensusEngine), so
     * only the rest of that change is guided.
     * It is priced as a report is, by the immediate share of the fair-value revision it causes.
     *
     * @return list<array<string, mixed>> the published warning event, or an empty list
     */
    public function evaluatePreAnnouncement(Stock $stock, \App\DTO\MacroStateDTO $macroState, int $tickCount, int $ticksPerYear): array
    {
        $ticksPerQuarter = TickCadence::intervalTicks($ticksPerYear, FinancialConstants::QUARTERS_PER_YEAR);
        if ($stock->isBankrupt() || ($tickCount % $ticksPerQuarter) !== self::resolvePreAnnouncementTick($stock->getTicker(), $ticksPerYear)) {
            return [];
        }

        $strategy = \App\Data\Company\Sectors::getBusinessModelStrategy(\App\Data\Company\Sectors::businessModelFor($stock->getIndustry()));
        $afterTax = 1.0 - $strategy->getEffectiveTaxRate($macroState->corporateTaxRate);

        // Owed back to earlier quarters that were papered over, already in net income terms. Accruals are
        // firm-private, so the whole reversal is news: nothing on the street can have anticipated it.
        $reversalDue = $stock->getManagedAccrualBank() * self::EARNINGS_MANAGEMENT_REVERSAL_RATE;

        // The squeeze reaches earnings through the cost base the model charges its input gap to: a shift in the cost
        // ratio, so the dollars at risk are that shift times the quarter's revenue. The base is the model's own (a
        // refiner's opex share, never its crude, which it sells back barrel for barrel); a row whose last report
        // predates the recorded base falls back to the variable cost ratio.
        $dragChange = $strategy->projectInputCostDragChange($stock, $macroState);
        if ($dragChange === null) {
            $streamState = $stock->getEarningsMomentumZ() ?? [];
            $projectedGap = $strategy->projectInputCostGap($stock, $macroState);
            $reportedGap = (float) ($streamState[FinancialConstants::STATE_INPUT_COST_LEVEL] ?? 0.0)
                - (float) ($streamState[FinancialConstants::STATE_INPUT_COST_RECOVERY] ?? 0.0);
            $dragChange = $projectedGap !== null ? ($projectedGap - $reportedGap) * $this->resolveVariableCostRatio($stock) : 0.0;
        }
        $quarterlyRevenue = max(0.0, (float) $stock->getTotalRevenue()) / 4.0;

        // Analysts carry the published part of an input move (MarketConsensusEngine reads the change since the last
        // report at ANALYST_COST_BASE_VISIBILITY), so only the share they cannot see is worth warning about, and it is
        // a cost: it reaches the net income the estimate is struck in after tax.
        $costSqueeze = max(0.0, $dragChange)
            * $quarterlyRevenue
            * (1.0 - FinancialConstants::ANALYST_COST_BASE_VISIBILITY)
            * $afterTax;

        $knownShortfall = $reversalDue + $costSqueeze;
        if ($knownShortfall <= 0.0) {
            return [];
        }

        // Shortfall sized against structural quarterly earnings power, after tax, rather than volatile trailing
        // earnings.
        $structuralMargin = max(self::PREANNOUNCEMENT_MIN_MARGIN_SCALE, (float) $stock->getOperatingMargin());
        $structuralQuarterlyEarnings = max(1.0, $quarterlyRevenue * $structuralMargin * $afterTax);
        $shortfallRatio = $knownShortfall / $structuralQuarterlyEarnings;

        if ($shortfallRatio < self::PREANNOUNCEMENT_WARNING_THRESHOLD) {
            return [];
        }

        // Analysts cut their number on the warning, so the report that follows is a smaller surprise —
        // which is exactly why managements warn. They cut it by the whole shortfall, the reported number's;
        // fair value hears only the cost squeeze, through the trailing return the report will book it to
        // (MarketPricingContext), since the accruals reversing move reported earnings and not economic ones.
        [$fairValueBefore] = $this->strikeFairValue($stock, $macroState);
        $stock->setPreAnnouncedShortfall($knownShortfall * self::PREANNOUNCEMENT_CONSENSUS_ABSORPTION);
        $stock->setPreAnnouncedEconomicShortfall($costSqueeze * self::PREANNOUNCEMENT_CONSENSUS_ABSORPTION);
        [$fairValueAfter] = $this->strikeFairValue($stock, $macroState);

        // No books close and no time passes between the two strikes, so no expected return is netted out.
        $currentPrice = (float) $stock->getPrice();
        $reaction = self::reportPriceGap($fairValueBefore, $fairValueAfter, 0.0, 0.0);
        $stock->setPrice(number_format(max(0.01, $currentPrice * (1.0 + $reaction)), 8, '.', ''));
        $this->recordAnnouncementVariance($stock, log(1.0 + $reaction), false);

        return [$this->marketEvent->publish(
            $stock,
            'GUIDANCE',
            sprintf('Guidance cut: management expects to miss consensus by roughly %d%% on cost and accrual pressure already incurred.', (int) round($shortfallRatio * 100)),
            $reaction * 100,
        )];
    }

    /**
     * The firm's variable cost ratio: the CIR state the margin process actually carries, falling back before
     * the first report has opened that state to the same construction the seeder builds it from.
     *
     * The complement of the operating margin on its own is the TOTAL cost ratio — it still carries fixed costs
     * and depreciation — so charging it against volume overstated the squeeze by the reciprocal of the
     * fixed-cost complement: 0.85 rather than 0.5525 at seeded defaults, a factor of 1.54.
     */
    private function resolveVariableCostRatio(Stock $stock): float
    {
        $structural = $stock->getStructuralVariableMargin();
        if ($structural !== null) {
            return max(0.01, min(0.99, (float) $structural));
        }

        // MarketResetCommand's construction: the cost base implied by the operating margin, net of the share
        // of it that does not scale with volume.
        $totalCostRatio = 1.0 - (float) $stock->getOperatingMargin();

        return max(0.01, min(0.99, $totalCostRatio * (1.0 - $stock->getFixedCostRatio())));
    }

    /**
     * The returns a firm is valued on, both of what it filed rather than its operating return state, which is pulled
     * toward the required return whether or not the business earns it: the level its filed returns revert to, opening
     * at the return it was seeded to earn, and its recent year (MarketPricingContext::trailingReturn()). Both are filed
     * without the return the franchise margin adds, which fair value carries on its own (FairValue).
     *
     * @param float $franchiseReturn The return the quarter's franchise margin added to the filed return.
     */
    private function fileValuationReturns(Stock $stock, BusinessModelInterface $strategy, float $franchiseReturn): void
    {
        $filed = $strategy->isFinancial() ? $stock->getCurrentRoe() : $stock->getCurrentRoic();
        if ($filed === null) {
            return;
        }
        $filed = (float) $filed - $franchiseReturn;

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $seeded = $strategy->isFinancial() ? $stock->getBaselineRoe() : $stock->getBaselineRoic();
        $anchor = $momentum[self::STATE_ANCHOR_RETURN] ?? ($seeded !== null ? (float) $seeded : null);
        $trailing = $momentum[self::STATE_TRAILING_RETURN] ?? null;
        $stock->setEarningsMomentumZ([
            self::STATE_ANCHOR_RETURN => TimeSeries::ewmaLevel($anchor !== null ? (float) $anchor : null, (float) $filed, FinancialConstants::QUARTER_YEARS, self::ANCHOR_RETURN_EMA_YEARS),
            self::STATE_TRAILING_RETURN => TimeSeries::ewmaLevel($trailing !== null ? (float) $trailing : null, (float) $filed, FinancialConstants::QUARTER_YEARS, self::TRAILING_RETURN_EMA_YEARS),
        ] + $momentum);
    }

    private function checkReportingEligibility(Stock $stock, int $tickCount, int $ticksPerYear): bool
    {
        return self::ticksSinceReportingTick($stock->getTicker(), $tickCount, $ticksPerYear) === 0;
    }

    private function initializeContext(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        $ctx->baselineVol = (float) $stock->getVolatility();
        $ctx->sharesOutstanding = (float) $stock->getSharesOutstanding();
        $ctx->stableMargin = max(0.01, (float) $stock->getOperatingMargin());

        $targetMetrics = $ctx->strategy->getTargetMetrics($stock, $ctx->macroState, $this->mathUtility);
        $ctx->investedCapital = $targetMetrics['invested_capital'];
        $ctx->baselineRoic = $targetMetrics['baseline_roic'];

        $macroTaxRate = $ctx->macroState->corporateTaxRate;
        $ctx->corporateTaxRate = $ctx->strategy->getEffectiveTaxRate($macroTaxRate);

        $this->seedFixedAssetLedgerIfNeeded($ctx);
        $this->creditLosses->seedEarningAssetLedgerIfNeeded($ctx);

        // Struck once the ledgers exist and before earnings or distributions move either side.
        if ($ctx->strategy->getTargetCapitalRatio($stock) !== null) {
            $openingAssets = $stock->getTotalAssets();
            $ctx->openingCapitalRatio = $openingAssets > 0.0 ? (float) $stock->getTotalEquity() / $openingAssets : null;
        }
    }

    /**
     * Opens a fixed-asset ledger for a firm that has never reported. Existing databases therefore heal
     * themselves on the next earnings report instead of needing a backfill, the same way the structural
     * asset turnover seeds itself. Financial models keep depreciating their capital proxy and never open
     * a plant ledger.
     */
    private function seedFixedAssetLedgerIfNeeded(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        if ($stock->getGrossPpe() !== null || $ctx->strategy->isFinancial()) {
            return;
        }

        // The plant is what invested capital is made of once working capital, goodwill and construction
        // are taken out, so the working capital ledger has to exist BEFORE the plant is sized. Seeding it
        // here with the same day counts the quarterly roll-forward uses means the opening balance sheet
        // balances to the dollar; estimating it any other way left a permanent hole the size of the
        // difference between the estimate and what the ledger then built.
        if (!$stock->hasWorkingCapitalLedger()) {
            // A firm that has never reported may carry no revenue figure at all. Seeding the trade cycle
            // against zero would open an empty ledger and then charge the whole first quarter's working
            // capital to cash as if it had been built from nothing. Structural revenue is what capital can
            // generate at its DuPont turnover, the same anchor the revenue engine uses, so the opening
            // balances land where the first quarter will actually put them.
            $recordedRevenue = max(0.0, (float) $stock->getTotalRevenue());
            $structuralRevenue = $recordedRevenue > 0.0
                ? $recordedRevenue
                : abs($ctx->investedCapital) * $this->operations->resolveAnnualCapitalTurnover($ctx);
            $structuralCashCosts = $structuralRevenue * (1.0 - $ctx->stableMargin);
            $this->corporateMetrics->buildWorkingCapitalBalances(
                $stock,
                $ctx->strategy->getWorkingCapitalDays($stock),
                $structuralRevenue,
                $structuralCashCosts,
            );

            // A going concern already carries a credit-loss allowance against its receivables. Opening the
            // ledger with none would have every firm book a provision on its first report to build one,
            // a phantom miss that lands on the whole market in the same earnings season.
            $this->corporateMetrics->seedReceivablesAllowance($stock, $ctx->macroState->corporateDefaultRateEma);
        }
        $netWorkingCapital = (float) $stock->getNetWorkingCapital();

        // Marketable investments open on their own asset line before the plant is carved out of what is
        // left, so the two together account for the whole balance sheet. Null means the investments have no
        // price yet and the ledger waits: a plant struck first would BE the portfolio, and be depreciated.
        $openingInvestments = $ctx->strategy->getOpeningInvestmentAssets($stock);

        if ($openingInvestments === null) {
            return;
        }

        if ($stock->getListedStakesCarrying() === null && $openingInvestments > 0.0) {
            $stock->setListedStakesCarrying((string) $openingInvestments);
        }

        $this->corporateMetrics->seedFixedAssetLedger(
            $stock,
            $ctx->strategy->getPlantCapital($stock, $ctx->investedCapital),
            $netWorkingCapital,
            (float) $stock->getGoodwill(),
            $stock->getTotalCipAmount(),
        );
    }

    /**
     * What each macro input the model reads did to each of its revenue streams this quarter, through both paths it
     * takes: the firm-wide demand and pricing the macro physics sets, and the model's own stream physics. For each
     * input, the macro physics is re-read with that input neutral and the quarter's expected revenue rebuilt from it
     * through the same utilisation and capacity steps; the stream physics then runs on that. See
     * MacroDriverAttribution.
     *
     * @param array{uniforms: list<float>, spare: ?float} $draws
     * @param array<string, float>                        $actualStreams
     */
    private function attributeMacroDrivers(EarningsSimulationContext $ctx, Stock $streamBasis, array $draws, array $actualStreams): void
    {
        if ($actualStreams === [] || $ctx->macroPhysicsBasis === null) {
            return;
        }

        $counterfactuals = [];
        foreach (MacroDriverAttribution::counterfactualStates($ctx->strategy, $ctx->macroState) as $field => $state) {
            $basis = clone $ctx->macroPhysicsBasis;
            $macroPhysics = $ctx->strategy->getMacroPhysics($basis, $state);
            $pricingPowerMultiplier = $macroPhysics['pricing_power_multiplier'];
            $inputCostMultiplier = (float) ($macroPhysics['input_cost_multiplier'] ?? $pricingPowerMultiplier);
            $macroDemandShift = $macroPhysics['macro_demand_shift']
                + $ctx->strategy->resolveSpendingAdjustmentShift($basis, $state)
                + $ctx->rivalShareDrain
                - ($ctx->strategy->getPriceElasticityOfDemand() * ($pricingPowerMultiplier - $inputCostMultiplier));

            [, $expectedRevenue] = $this->operations->capacityRevenueAt($ctx, $pricingPowerMultiplier, $this->operations->capacityUtilizationAt($ctx, $macroDemandShift));
            $counterfactuals[$field] = [$state, $expectedRevenue * $ctx->industryPriceLevel * $ctx->plantLoad];
        }

        $ctx->streamMacroEffects = $ctx->strategy->measureStreamMacroEffects(
            $streamBasis,
            $draws,
            $counterfactuals,
            $ctx->realizedVariableMargin,
            $ctx->fixedCosts,
            $ctx->baselineVol,
            $actualStreams,
        );
    }

    /**
     * The cost side of the quarter, filed with the KPIs for the company page's business map: the variable cost ratio
     * the quarter ran at, the fixed cost base, and for a model that buys inputs at market prices the price gap of each
     * input it bought at and the input-cost gap it has not yet passed on (StandardOperatingPhysicsTrait). Filed rather
     * than read off the live state so the map shows the macro the quarter was struck under.
     *
     * @param array<string, float> $streamState
     */
    private function recordCostKpis(EarningsSimulationContext $ctx, array $streamState): void
    {
        if ($ctx->actualRevenue > 0.0) {
            $ctx->kpis['variable_cost_ratio'] = $ctx->actualVariableCosts / $ctx->actualRevenue;
        }
        $ctx->kpis['fixed_costs'] = $ctx->fixedCosts;

        if (!isset($streamState[FinancialConstants::STATE_INPUT_COST_LEVEL])) {
            return;
        }
        $level = (float) $streamState[FinancialConstants::STATE_INPUT_COST_LEVEL];
        $recovery = (float) ($streamState[FinancialConstants::STATE_INPUT_COST_RECOVERY] ?? 0.0);
        $ctx->kpis['input_cost_level'] = $level;
        $ctx->kpis['input_cost_recovery'] = $recovery;
        if (isset($streamState[FinancialConstants::STATE_INPUT_COST_RECOVERED_SHARE])) {
            $ctx->kpis['input_cost_recovered_share'] = (float) $streamState[FinancialConstants::STATE_INPUT_COST_RECOVERED_SHARE];
        }
        $model = $ctx->strategy;
        if (isset($streamState[FinancialConstants::STATE_INPUT_COST_DRAG_BASE])) {
            // The drag the cost ratio carried, inside the same bound resolveInputCostDrag() puts on it.
            $drag = (float) $streamState[FinancialConstants::STATE_INPUT_COST_DRAG_BASE] * ($level - $recovery);
            $bound = $model::class . '::MAX_INPUT_COST_DRAG';
            $ctx->kpis['input_cost_drag'] = defined($bound) ? max(-(float) constant($bound), min((float) constant($bound), $drag)) : $drag;
        }

        if (!method_exists($model, 'getInputCostExposures') || !method_exists($model, 'resolveInputPriceDeviations')) {
            return;
        }
        if (method_exists($model, 'attributeInputCostDrag')) {
            foreach ($model->attributeInputCostDrag($streamState) as $channel => $part) {
                $ctx->kpis['input_drag_' . $channel] = $part;
            }
        }
        $gaps = $model->resolveInputPriceDeviations($ctx->macroState);
        foreach ($model->getInputCostExposures() as $channel => $share) {
            if ((float) $share > 0.0 && isset($gaps[$channel])) {
                $ctx->kpis['input_gap_' . $channel] = (float) $gaps[$channel];
            }
        }
    }

    private function calculateExpectedVsActualFinancials(EarningsSimulationContext $ctx): void
    {
        // The firm and the draws the stream physics runs on, kept so its macro drivers can be measured on them.
        $streamBasis = clone $ctx->stock;
        // Industry-wide regimes (a price war every carrier fights) as the report opens. The replays below read the
        // same opening state; a regime this report started is booked once, from the real call, before they run.
        $industryRegimes = $this->industryShareLedger?->openIndustryRegimes($ctx->stock, $ctx->tickCount, $ctx->ticksPerYear);
        $ctx->strategy->useIndustryRegimes($industryRegimes);
        try {
            $this->mathUtility->beginDrawLog();
            $actuals = $ctx->strategy->computeActualFinancials(
                $ctx->stock,
                $ctx->expectedRevenue,
                $ctx->realizedVariableMargin,
                $ctx->fixedCosts,
                $ctx->baselineVol,
                $ctx->macroState,
                $this->mathUtility,
                $ctx->calendarQuarter,
            );
            if ($industryRegimes !== null) {
                $this->industryShareLedger?->commitIndustryRegimes($ctx->stock, $industryRegimes, $ctx->tickCount, $ctx->ticksPerYear);
            }
            $draws = $this->mathUtility->endDrawLog();
            $this->attributeMacroDrivers($ctx, $streamBasis, $draws, $actuals->streamRevenue);
            $revenueWithoutSector = $this->industryShareLedger !== null
                ? $ctx->strategy->measureRevenueWithoutSectorFactor($streamBasis, $draws, $ctx->expectedRevenue, $ctx->realizedVariableMargin, $ctx->fixedCosts, $ctx->baselineVol, $ctx->macroState)
                : null;
            // The same quarter as analysts could expect it before the report: averaged over the firm's private draws,
            // on the revenue and margin it was expected to run at, with the industry's announced regimes still in view.
            $ctx->publicActuals = $ctx->strategy->measurePublicFinancials($streamBasis, $ctx->expectedRevenueBeforeShocks, $ctx->publicVariableMargin, $ctx->fixedCosts, $ctx->baselineVol, $ctx->macroState, $ctx->calendarQuarter, crc32($ctx->stock->getTicker() . ':' . $ctx->tickCount));
        } finally {
            $ctx->strategy->useIndustryRegimes(null);
        }
        $ctx->actualRevenue = $actuals->actualRevenue;
        $ctx->actualVariableCosts = $actuals->actualVariableCosts;
        // A segment entered in play earns its share of the quarter as a line of its own, carved from the model's.
        $streamRevenue = SegmentBooks::carveEnteredLines($ctx->stock->getEarningsMomentumZ() ?? [], $actuals->streamRevenue);

        // What this firm just took from (or ceded to) the industry: the part of the surprise that is its
        // OWN. Macro and rival effects were already inside expected revenue; the sector factor was not, and
        // every peer in the sector drew it too — booked as a gain, a sector-wide good quarter would have each
        // firm "taking" from the others. So the gain is what the quarter would have beaten expectation by had
        // the sector drawn nothing, re-run on the quarter's own draws.
        if ($this->industryShareLedger !== null && $ctx->expectedRevenue > 0.0) {
            $firmSurprise = (($revenueWithoutSector ?? $ctx->actualRevenue) - $ctx->expectedRevenue) / $ctx->expectedRevenue;
            if ($ctx->segments === []) {
                $this->industryShareLedger->recordIdiosyncraticGain($ctx->stock, $ctx->actualRevenue * 4.0, $firmSurprise, $ctx->addressableShare, $ctx->tickCount, [
                    'margin' => $ctx->stableMargin,
                    'turnover' => $ctx->assetTurnover * self::TTM_QUARTERS,
                ]);
            } else {
                // Plant kept by segment: each segment's industry books that segment's sales alone, so the company's
                // revenue is counted once across its industries, and the gain it took there, its own lines' sales over
                // its share of the plan, less the sector's draw every peer took too.
                $segmentShares = SegmentOutlook::shares($ctx->stock->getTicker(), $streamRevenue, true);
                $sectorDraw = ($ctx->actualRevenue - ($revenueWithoutSector ?? $ctx->actualRevenue)) / $ctx->expectedRevenue;
                $momentum = $ctx->stock->getEarningsMomentumZ() ?? [];
                foreach ($ctx->segments as $segment => $books) {
                    $segmentRevenue = $ctx->actualRevenue * ($segmentShares[$segment] ?? 0.0);
                    $planned = $ctx->expectedRevenue * (float) ($momentum[SegmentBooks::STATE_TARGET . $segment] ?? 0.0);
                    $gain = $planned > 0.0 ? ($segmentRevenue / $planned) - 1.0 - $sectorDraw : $firmSurprise;
                    $this->industryShareLedger->recordSegmentGain($ctx->stock, $segment, $segmentRevenue * 4.0, $gain, $books['market'], $ctx->tickCount, [
                        'margin' => $books['margin'],
                        'turnover' => $books['turnover'],
                    ]);
                }
            }
        }

        $coverage = $ctx->strategy->getCoverageProfile($ctx->stock);
        $seasonalRatio = $ctx->seasonalFactor / max(0.01, $ctx->priorSeasonalFactor);
        // The base the LAST consensus was struck against, still the opening state here: the stream map on
        // the stock is only replaced once the report is booked below.
        $priorExpectedRevenue = (float) (($ctx->stock->getEarningsMomentumZ() ?? [])[self::STATE_LAST_EXPECTED_REVENUE] ?? 0.0);
        $consensus = $this->marketConsensusEngine->generateConsensus(
            $actuals,
            $ctx->publicActuals ?? $actuals,
            $coverage,
            $this->mathUtility,
            $ctx->stock,
            $ctx->macroState->marketVolatilityEma,
            $ctx->expectedRevenueBeforeShocks,
            $ctx->strategy->projectInputCostDragChange($ctx->stock, $ctx->macroState) ?? 0.0,
            $seasonalRatio,
            $priorExpectedRevenue,
        );
        $ctx->analystExpectedRevenue = $consensus->analystExpectedRevenue;
        $ctx->analystExpectedVariableCosts = $consensus->analystExpectedVariableCosts;
        // The earnings estimate is built on the unbiased revenue; its own walk-down is struck on EPS below.
        $analystRevenueForEarnings = $consensus->analystUnbiasedRevenue > 0.0 ? $consensus->analystUnbiasedRevenue : $consensus->analystExpectedRevenue;

        // Depreciation is the most forecastable line on the income statement — it follows a schedule the
        // firm has already disclosed — so analysts get it right and it is not a source of surprise; a producer's
        // charge follows output, and only the part its own shocks move can surprise. A lender's
        // provision is the same kind of line: the through-the-cycle charge on a disclosed book is forecast,
        // and only the cyclical excess the sector physics adds on top of it can surprise.
        $ctx->expectedCreditLossProvision = $this->creditLosses->resolveExpectedCreditLossProvision($ctx);
        $expectedEbit = $analystRevenueForEarnings - $ctx->fixedCosts - $ctx->analystExpectedVariableCosts
            - $ctx->expectedDepreciation - $ctx->expectedCreditLossProvision;
        $ctx->expectedEbit = max(-$ctx->structuralRevenue * self::MAX_EBIT_LOSS_RATIO, $expectedEbit);

        // Operating costs are the cash cost base; EBITDA sits above the depreciation line and EBIT below it.
        $ctx->operatingCosts = $ctx->actualVariableCosts + $ctx->fixedCosts;
        $ctx->ebitda = $ctx->actualRevenue - $ctx->operatingCosts;
        $ctx->ebit = $ctx->ebitda - $ctx->quarterlyDepreciation;
        // A one-off charge the physics booked (a recall, a fine) is operating expense the quarter it lands and, like
        // restructuring, a special item: no analyst forecasts it, and the run rate and the Street figure leave it out.
        $ctx->eventCharge = $actuals->specialCharge;
        $ctx->ebitda -= $ctx->eventCharge;
        $ctx->ebit -= $ctx->eventCharge;

        $ctx->eventType = $actuals->eventType;
        $ctx->eventContext = $actuals->eventContext;
        $streamState = $actuals->streamZ;
        // The base this quarter's consensus was struck against, so the next one can roll its anchor
        // forward with the firm's growth instead of freezing it at today's dollar size.
        $streamState[self::STATE_LAST_EXPECTED_REVENUE] = $ctx->expectedRevenueBeforeShocks;
        // The price level the plant capacity was last struck against, and the surprise it carries.
        $streamState[OperatingCapacityEngine::STATE_LAST_DEFLATOR] = max(0.01, $ctx->macroState->gdpDeflator);
        $streamState[OperatingCapacityEngine::STATE_PRICE_LEVEL_SURPRISE] = $ctx->priceLevelSurprise;
        // A company in several markets files each segment's share of the quarter's revenue, which its next report
        // blends their outlooks at.
        foreach (SegmentOutlook::shares($ctx->stock->getTicker(), $streamRevenue, SegmentBooks::plant($ctx->stock->getEarningsMomentumZ() ?? []) !== null) as $segment => $share) {
            $streamState[SegmentOutlook::STATE_SHARE . $segment] = $share;
        }
        // State the physics does not own (the fixed-asset ledger's capital-goods vintage, rolled forward after the cash
        // flows; a distress episode the divestiture engine opened) is carried until its owner removes it. A physics key
        // it stopped registering is gone, so a list it shortens does not come back.
        foreach ($ctx->stock->getEarningsMomentumZ() ?? [] as $key => $value) {
            if (str_starts_with($key, self::CARRIED_STATE_PREFIX) && !array_key_exists($key, $streamState)) {
                $streamState[$key] = $value;
            }
        }
        $ctx->stock->setEarningsMomentumZ($streamState);
        $ctx->streamRevenue = $streamRevenue;
        $ctx->scheduledCapex = max(0.0, $actuals->scheduledCapex);
        $ctx->kpis = $actuals->kpis;
        $ctx->kpis['rival_share_drain'] = $ctx->rivalShareDrain;
        $ctx->kpis['own_price_volume_shift'] = $ctx->ownPriceVolumeShift;
        $ctx->kpis['industry_price_level'] = $ctx->industryPriceLevel;
        $ctx->kpis['idle_plant_share'] = 1.0 - $ctx->plantLoad;
        $ctx->kpis['industry_outlook'] = $ctx->industryOutlook;
        $ctx->kpis['price_revenue'] = $actuals->priceRevenue;
        $this->recordCostKpis($ctx, $streamState);
        $ctx->creditLossProvision = $actuals->creditLossProvision;
        $ctx->netChargeOffs = max(0.0, $actuals->netChargeOffs);
        $ctx->netInterestSqueeze = $actuals->netInterestSqueeze;
        $ctx->plantImpairment = $actuals->plantImpairment;

        // Stock-based compensation (ASC 718) is already inside the operating cost base: it changes no margin.
        // What is settled in new shares is non-cash (added back to FCF below), but only up to the shares a
        // board can grant: shareholders and proxy advisers cap the annual burn rate (ISS benchmarks), so a
        // firm whose price has fallen pays the rest of the same pay bill in cash rather than printing stock.
        $stockCompensationExpense = max(0.0, $ctx->actualRevenue) * $ctx->strategy->getStockCompensationIntensity($ctx->stock);
        $burnRateCap = self::EQUITY_BURN_RATE_CAP_BY_SECTOR[(string) $ctx->stock->getSector()] ?? self::DEFAULT_EQUITY_BURN_RATE_CAP;
        $grantableValue = $burnRateCap * $ctx->dt * max(0.0, $ctx->sharesOutstanding) * max(0.0, (float) $ctx->stock->getPrice());
        $ctx->stockCompensation = min($stockCompensationExpense, $grantableValue);
        $ctx->kpis['stock_compensation'] = $stockCompensationExpense;
    }

    private function calculateInterestAndRunRates(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;

        // Seasonally Adjusted Annual Rate (SAAR) Metrics:
        // Reported quarterly revenue and EBIT oscillate with operational seasonality.
        // Annual-basis run-rates (total revenue, the reported margin) read the seasonally
        // adjusted quarter rather than interpreting a seasonal trough/peak as a permanent
        // structural shift; credit (ICR, debt health, the rating) reads the trailing year.
        $ctx->seasonallyAdjustedRevenue = $ctx->actualRevenue / max(0.01, $ctx->seasonalFactor);
        $realizedCostRatio = $ctx->actualRevenue > 0.0
            ? ($ctx->actualVariableCosts / $ctx->actualRevenue)
            : $ctx->realizedVariableMargin;
        // Deseasonalized run-rate EBIT: reconstructed from revenue and cost ratios with the recurring charges (credit
        // losses, working capital provisions) deducted and the special items not: a restructuring or a plant write-down
        // is charged once, to equity, and a run rate that carried it would capitalize it into the return fair value
        // reads.
        $ctx->specialCharges = $ctx->restructuringCharge + $ctx->plantImpairment + $ctx->eventCharge;
        $ctx->seasonallyAdjustedEbit = ($ctx->seasonallyAdjustedRevenue * (1.0 - $realizedCostRatio)) - ($ctx->fixedCosts / $ctx->seasonalFixedCostFactor) - $ctx->quarterlyDepreciation - ($ctx->impairmentCharges - $ctx->plantImpairment);
        $ctx->structuralOperatingMargin = $ctx->seasonallyAdjustedEbit / max(1.0, $ctx->seasonallyAdjustedRevenue);

        // Persist the margin the firm actually earned. The stock's operatingMargin is the slow structural
        // parameter that only asset reinvestment moves, so solvency tests reading it price a collapse in
        // realized profitability quarters late.
        $stock->setReportedOperatingMargin($ctx->structuralOperatingMargin);

        // Lenders underwrite coverage and leverage on the last twelve months, where the seasons and one
        // quarter's charges wash out. The first report seeds the window from the deseasonalized quarter, as
        // the net income history is seeded, so a new listing is not judged on whichever season it opens in.
        $operatingHistory = $stock->getQuarterlyOperatingHistory() ?? [];
        if (count($operatingHistory) < self::TTM_QUARTERS) {
            $operatingHistory = array_fill(0, self::TTM_QUARTERS, ['revenue' => $ctx->seasonallyAdjustedRevenue, 'ebit' => $ctx->seasonallyAdjustedEbit]);
        }
        // Credit-agreement EBITDA is struck before restructuring and non-cash write-downs, so the special items are
        // added back to what the lenders read.
        $operatingHistory[] = ['revenue' => $ctx->actualRevenue, 'ebit' => $ctx->ebit + $ctx->specialCharges];
        $stock->setQuarterlyOperatingHistory(array_values(array_slice($operatingHistory, -self::TTM_QUARTERS)));

        $annualSaarRevenue = TimeSeries::calculateSeasonallyAdjustedAnnualRate($ctx->actualRevenue, $ctx->seasonalFactor, 4);
        $stock->setTotalRevenue((string) $annualSaarRevenue);

        // Leases follow the capacity the firm runs, not the quarter's demand: struck on structural revenue less plant
        // idled in a glut, signed as the plant grows and run off only as they expire.
        $stock->setLeaseLiability(CorporateMetrics::rollLeaseLiability(
            $stock->getLeaseLiability(),
            $ctx->structuralRevenue * $ctx->plantLoad * self::TTM_QUARTERS,
            $ctx->strategy->getLeaseIntensity($stock),
            $ctx->dt,
        ));

        $saExpectedRevenue = $ctx->expectedRevenue / max(0.01, $ctx->seasonalFactor);
        $saExpectedEbit = ($saExpectedRevenue * (1.0 - ($ctx->baselineVariableMargin * $ctx->franchiseCostScale))) - ($ctx->fixedCosts / $ctx->seasonalFixedCostFactor) - $ctx->quarterlyDepreciation - $ctx->expectedCreditLossProvision;
        $saExpectedMargin = $saExpectedEbit / max(1.0, $saExpectedRevenue);
        $expectedDebtMetrics = $this->debtEngine->calculateInterestExpense($stock, $ctx->macroState, $saExpectedRevenue * 4.0, $saExpectedMargin);
        $ctx->expectedInterestExpense = $expectedDebtMetrics->interestExpense / 4.0;

        $ctx->trueOperatingMargin = $ctx->ebit / max(1.0, $ctx->actualRevenue);
        // The rating review reads the same twelve months a lender does; only its Altman cap uses the operating figures.
        $trailing = $this->debtEngine->resolveTrailingOperatingBasis($stock);
        $ctx->debtMetrics = $this->debtEngine->reviewRatingAndPriceDebt($stock, $ctx->macroState, $trailing['revenue'] ?? $annualSaarRevenue, $trailing['margin'] ?? $ctx->structuralOperatingMargin);

        $annualInterestExpense = $ctx->debtMetrics->interestExpense;
        $ctx->quarterlyInterestExpense = $annualInterestExpense / 4.0;

        $stock->setHistoricalFixedRate((string) $ctx->debtMetrics->historicalFixedRate);

        // Pass the realized wholesale rate (dynamic Merton/BGG spread already applied) so strategies whose
        // earning assets reprice off their own funding cost book income consistent with the expense side
        // computed just above, rather than a stale calm-market benchmark.
        $annualInterestIncome = $ctx->strategy->calculateInterestIncome($stock, $ctx->macroState, $ctx->debtMetrics->wholesaleRate);
        // The equity sleeve's expected return gives way to the dividends the board actually paid it; its price move
        // went to book value at the mark.
        if ($ctx->equitySleeveDividendReturn !== null) {
            $sleeve = $ctx->strategy->resolveEquitySleeve($stock, $ctx->macroState);
            $annualInterestIncome += $sleeve['value'] * ($ctx->equitySleeveDividendReturn - $sleeve['expected_return']);
        }
        $ctx->quarterlyInterestIncome = $annualInterestIncome / 4.0;
        if ($ctx->equitySleeveMark !== 0.0) {
            $stock->setCorporateTreasury((string) max(0.0, (float) $stock->getCorporateTreasury() + $ctx->equitySleeveMark));
        }
    }

    private function reconcileTaxesAndNetIncome(EarningsSimulationContext $ctx): void
    {
        $this->incomeTax->bookIncomeTax($ctx);
        $stock = $ctx->stock;

        // Analysts walk the EPS estimate down to a figure the firm can beat (Richardson, Teoh & Wysocki 2004;
        // Matsumoto 2002), by as much as the firm's own record says it can miss by. Struck on revenue, so the cushion
        // neither scales with operating leverage nor vanishes as earnings cross zero.
        $record = $stock->getEarningsMomentumZ() ?? [];
        $surpriseVariance = MarketConsensusEngine::surpriseVariance((float) ($record[self::STATE_SURPRISE_SQUARES] ?? 0.0), (float) ($record[self::STATE_SURPRISE_WEIGHT] ?? 0.0));
        $ctx->consensusWalkDown = MarketConsensusEngine::epsWalkDown($ctx->analystExpectedRevenue, $surpriseVariance);
        $ctx->earningsSurpriseSd = sqrt(max(0.0, $surpriseVariance)) * max(0.0, $ctx->analystExpectedRevenue);
        $ctx->expectedQuarterlyNetIncome -= $ctx->consensusWalkDown;

        // A warning already moved the market and the estimate: analysts have taken the guided shortfall
        // out of their number, so the report confirms it rather than delivering it. Cleared here because
        // the quarter it referred to is the one now being reported.
        $ctx->expectedQuarterlyNetIncome -= $stock->getPreAnnouncedShortfall();
        $stock->setPreAnnouncedShortfall(0.0);
        $stock->setPreAnnouncedEconomicShortfall(0.0);

        $ctx->reportedExpectedNetIncome = $ctx->expectedQuarterlyNetIncome;
        $ctx->reportedActualNetIncome = $ctx->actualQuarterlyNetIncome;

        if ($ctx->strategy->reportsFundsFromOperations()) {
            $ctx->reportedExpectedNetIncome += $ctx->quarterlyDepreciation;
            $ctx->reportedActualNetIncome += $ctx->quarterlyDepreciation;
        }

        $ctx->health = $this->debtEngine->analyzeTrailingDebtHealth($stock, $ctx->macroState);
        $ctx->truePostTaxReturn = $ctx->strategy->updateDynamicRoic(
            $stock,
            $ctx->actualQuarterlyNetIncome,
            $ctx->investedCapital,
            $ctx->seasonallyAdjustedEbit,
            $ctx->corporateTaxRate,
            $ctx->health->wacc,
            $ctx->health->costOfEquity,
            $ctx->macroState,
            $ctx->quarterlyDepreciation,
            $ctx->specialCharges * (1.0 - $ctx->corporateTaxRate),
        );

        // The firm's long-run return, its operating return through the cycle.
        $priorLongRun = $stock->getLongRunReturn();
        $stock->setLongRunReturn((string) TimeSeries::ewmaLevel(
            $priorLongRun !== null ? (float) $priorLongRun : null,
            $ctx->strategy->getTrueReturn($stock),
            FinancialConstants::QUARTER_YEARS,
            self::LONG_RUN_RETURN_EMA_YEARS,
        ));
        $this->fileValuationReturns($stock, $ctx->strategy, FirmEconomics::franchiseReturn(
            $ctx->franchiseCostSaving * self::TTM_QUARTERS,
            $ctx->corporateTaxRate,
            $ctx->strategy->getEvaluationCapital((float) $stock->getTotalEquity(), $ctx->investedCapital),
        ));

        $this->testGoodwillForImpairment($ctx);

        // Street earnings (Bradshaw & Sloan 2002) leave out what consensus never forecasts and the firm reports as
        // non-recurring: restructuring and write-downs after tax, and a goodwill impairment, which is not deductible.
        $ctx->specialItems = ($ctx->specialCharges * (1.0 - $ctx->corporateTaxRate)) + $ctx->goodwillImpairment;
    }

    /**
     * Annual goodwill impairment test (ASC 350 as simplified by ASU 2017-04), run in the fiscal fourth quarter: one
     * step, the carrying amount of the reporting unit less its fair value, capped at the goodwill it carries. The firm
     * is its own reporting unit and its fair value is the one the market strikes on its books (MarketEngine), which
     * discounts expected returns with their persistence rather than capitalizing one trailing year forever, so a
     * cyclical dip in returns does not write off the acquisition. The charge is non-cash: it hits reported (GAAP)
     * earnings, equity and the goodwill balance, never free cash flow, and analysts do not forecast it.
     */
    private function testGoodwillForImpairment(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        $goodwill = (float) $stock->getGoodwill();
        if ($goodwill <= 0.0 || $ctx->fiscalQuarter !== self::FISCAL_YEAR_END_QUARTER || $ctx->fairValueBeforeReport <= 0.0) {
            return;
        }

        $fairValue = $ctx->fairValueBeforeReport * max(0.0, $ctx->sharesOutstanding);
        $impairment = min($goodwill, max(0.0, (float) $stock->getTotalEquity() - $fairValue));
        if ($impairment < $goodwill * self::MIN_GOODWILL_IMPAIRMENT_FRACTION) {
            return;
        }

        // Equity takes the full charge, even below zero: a floor here would leave goodwill written off on the
        // asset side and the equity that carried it still standing, and the sheet would no longer balance.
        $stock->setGoodwill((string) ($goodwill - $impairment));
        // The firm is one reporting unit, so the goodwill paid for each business bought into a new industry is written
        // down in the same proportion.
        $stock->setEarningsMomentumZ(SegmentBooks::keepGoodwill($stock->getEarningsMomentumZ() ?? [], null, 1.0 - ($impairment / $goodwill)) + ($stock->getEarningsMomentumZ() ?? []));
        $stock->setTotalEquity((string) ((float) $stock->getTotalEquity() - $impairment));
        $stock->setRetainedEarnings((string) ((float) $stock->getRetainedEarnings() - $impairment));

        $ctx->goodwillImpairment = $impairment;
        $ctx->reportedActualNetIncome -= $impairment;
    }

    /**
     * Steers the headline toward consensus with accruals, and pays back what earlier quarters borrowed.
     *
     * Burgstahler & Dichev (1997) found reported earnings are not smoothly distributed: there is a hole
     * just below zero and just below the prior year's figure, and a spike just above, because management
     * closes small shortfalls rather than print a miss. Degeorge, Patel & Zeckhauser (1999) add the
     * analyst forecast as the third threshold, which is the one modelled here.
     *
     * Only SMALL gaps get closed. Accruals are a timing reclassification — revenue recognized a quarter
     * early, a provision left light — so there is a limit to what they can cover, and a firm facing a
     * genuine collapse takes the miss. What is borrowed accumulates in a bank that unwinds against future
     * quarters (Dechow & Dichev 2002): every managed beat is a debt against a later print.
     *
     * The entry moves REPORTED earnings only. Cash is untouched, and the economic figures (net income
     * driving ROIC, equity and free cash flow) stay on the true number — which is precisely why the
     * Sloan (1996) accruals penalty in the fair-value multiple (Valuation) can punish it: the gap between
     * what a firm reports and the cash it produced is the whole signal.
     */
    private function manageReportedEarnings(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        // The firm's own propensity, as the Securities Commission's enforcement leans on it.
        $propensity = min(1.0, $ctx->strategy->getEarningsManagementPropensity($stock) * $ctx->macroState->earningsManagementScale);
        $bank = $stock->getManagedAccrualBank();

        // Last quarter's borrowing comes due first: the reversal is booked before management looks at the
        // gap, so a firm that papered over one quarter starts the next one in a hole.
        $reversal = $bank * self::EARNINGS_MANAGEMENT_REVERSAL_RATE;
        $bank -= $reversal;
        $ctx->managedAccrual = -$reversal;

        // Management steers the Street figure, the one consensus is struck against, not the GAAP line below it.
        $consensus = $ctx->reportedExpectedNetIncome;
        $shortfall = $consensus - ($ctx->reportedActualNetIncome + $ctx->managedAccrual + $ctx->specialItems);

        $shares = max(1.0, $ctx->sharesOutstanding);
        // A gap is near against the noise the firm is known for: a cent a share is nothing to a firm earning ten
        // dollars and a quarter's profit to one earning ten cents.
        $reachableGap = self::EARNINGS_MANAGEMENT_MAX_GAP_SURPRISE_SDS * $ctx->earningsSurpriseSd;
        if ($propensity > 0.0 && $shortfall > 0.0 && abs($consensus) > 0.0 && $shortfall < $reachableGap) {
            // Only a near miss is worth closing, and the nearer the likelier: the accrual a gap needs costs more
            // to book and to unwind the wider it is, so the odds fall to nothing at the edge of the reachable gap.
            $odds = $propensity * (1.0 - ($shortfall / $reachableGap));

            if ($this->mathUtility->checkProbability($odds)) {
                // Land on the line or a cent above it, where the reported distribution's excess mass sits.
                $target = $shortfall + ($this->mathUtility->generateUniformBetween(0.0, self::EARNINGS_MANAGEMENT_BEAT_CUSHION_PER_SHARE) * $shares);
                $headroom = max(0.0, ($this->resolveTotalAssets($ctx) * self::EARNINGS_MANAGEMENT_MAX_BANK_RATIO) - $bank);
                $borrowed = min($target, $headroom);

                $ctx->managedAccrual += $borrowed;
                $bank += $borrowed;
            }
        }

        $stock->setManagedAccrualBank(max(0.0, $bank));
        $ctx->reportedActualNetIncome += $ctx->managedAccrual;
        // A managed accrual hides in an operating line (a light provision, revenue booked early), so the filed
        // statement carries it before tax, with the tax it bears deferred (ASC 740): pretax income less tax still files
        // this net income.
        $ctx->managedAccrualPretax = $ctx->managedAccrual / (1.0 - $ctx->corporateTaxRate);
    }

    /**
     * Total assets from the balance sheet where one is open. Until a firm's first ledger exists, equity
     * plus funding is total assets by identity.
     */
    private function resolveTotalAssets(EarningsSimulationContext $ctx): float
    {
        $stock = $ctx->stock;
        $totalAssets = $stock->hasBalanceSheetLedger()
            ? $stock->getTotalAssets($this->corporateMetrics->resolveLeaseLiability($stock, (float) $stock->getTotalRevenue(), $ctx->strategy->getLeaseIntensity($stock)))
            : (float) $stock->getTotalEquity() + (float) $stock->getTotalDebt();

        return max(FinancialConstants::MIN_OPERATING_BASE_CASH, $totalAssets);
    }

    /**
     * The surprise the Street sizes the firm's next walk-down from: Street earnings before management's accrual against
     * the unbiased estimate, on revenue, so the record is what the business delivers rather than what was steered.
     */
    private function recordEarningsSurprise(EarningsSimulationContext $ctx): void
    {
        if ($ctx->analystExpectedRevenue <= 0.0) {
            return;
        }

        $streetEarnings = $ctx->reportedActualNetIncome - $ctx->managedAccrual + $ctx->specialItems;
        $unbiasedEstimate = $ctx->reportedExpectedNetIncome + $ctx->consensusWalkDown;
        $state = $ctx->stock->getEarningsMomentumZ() ?? [];
        [$state[self::STATE_SURPRISE_SQUARES], $state[self::STATE_SURPRISE_WEIGHT]] = MarketConsensusEngine::recordSurprise(
            (float) ($state[self::STATE_SURPRISE_SQUARES] ?? 0.0),
            (float) ($state[self::STATE_SURPRISE_WEIGHT] ?? 0.0),
            ($streetEarnings - $unbiasedEstimate) / $ctx->analystExpectedRevenue,
            $ctx->dt,
        );
        $ctx->stock->setEarningsMomentumZ($state);
    }

    private function calculateEPSAndSurprise(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        $shares = max(1.0, $ctx->sharesOutstanding);

        // Quarterly GAAP EPS, and the Street EPS the surprise is struck on: consensus forecasts neither the seasons
        // away nor the special items in, so a write-down is reported, not missed.
        $ctx->actualQuarterlyEps = $ctx->reportedActualNetIncome / $shares;
        $ctx->streetQuarterlyEps = ($ctx->reportedActualNetIncome + $ctx->specialItems) / $shares;
        $ctx->expectedQuarterlyEps = $ctx->reportedExpectedNetIncome / $shares;
        $ctx->surpriseAmountQuarterly = $ctx->streetQuarterlyEps - $ctx->expectedQuarterlyEps;
        $this->recordEarningsSurprise($ctx);

        // Seasonally Adjusted Annual EPS for balance sheet and valuation (Kalman TTM EPS)
        $saQuarterlyNetIncome = $ctx->seasonallyAdjustedNetIncome;
        if ($ctx->strategy->reportsFundsFromOperations()) {
            $saQuarterlyNetIncome += $ctx->quarterlyDepreciation;
        }
        $saAnnualEpsRaw = ($saQuarterlyNetIncome * 4.0) / $shares;
        $ctx->actualAnnualEpsRaw = $saAnnualEpsRaw;

        // Trailing-twelve-month net income is the sum of the last four reported quarters.
        $history = $stock->getQuarterlyNetIncomeHistory() ?? [];
        if (count($history) < self::TTM_QUARTERS) {
            // First report: seed from the deseasonalized run-rate so the opening trailing figure does not
            // inherit the seasonality of whichever quarter happens to report first.
            $history = array_fill(0, self::TTM_QUARTERS, $saQuarterlyNetIncome);
        }

        $history[] = $ctx->reportedActualNetIncome;
        $history = array_map('floatval', array_slice($history, -self::TTM_QUARTERS));
        $stock->setQuarterlyNetIncomeHistory(array_values($history));

        $trailingNetIncome = max(
            -self::MAX_ABSOLUTE_NET_INCOME,
            min(self::MAX_ABSOLUTE_NET_INCOME, array_sum($history)),
        );
        $stock->setTotalNetIncome((string) $trailingNetIncome);
    }

    /**
     * The board's capital allocation on the quarter's earnings and free cash flow (CapitalAllocationEngine): dividends,
     * buybacks, issuance, expansion and debt, booked to the cash-flow statement at the cash that moved; then the
     * quarter's equity compensation vests in new shares.
     */
    private function allocateCapital(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        $currentPrice = (float) $stock->getPrice();
        $debtBeforeAllocation = (float) $stock->getWholesaleDebt();
        $revolverBeforeAllocation = (float) $stock->getRevolverDrawn();
        $depositsBeforeAllocation = (float) $stock->getCustomerDeposits();
        $ctx->allocation = $this->capitalAllocationEngine->allocateCapital(
            $stock,
            $ctx->actualAnnualEpsRaw,
            $ctx->freeCashFlowPerShare,
            $currentPrice,
            $ctx->sharesOutstanding,
            $ctx->macroState,
            $ctx->actualQuarterlyNetIncome,
            $ctx->stockCompensation,
            $ctx->openingCapitalRatio,
            $ctx->expansionBudget,
        );

        $stock->setSharesOutstanding((string) $ctx->allocation['new_shares']);

        $organicCapex = $ctx->allocation['organic_capex'] ?? 0.0;

        // Cash-flow statement signs classify the life-cycle stage (Dickinson 2011): net investment includes
        // organic expansion, net financing is debt raised plus shares issued net of dividends and buybacks.
        // For a lender the expansion is loans originated, and assets sold to raise cash come back through
        // investing at the price they fetched; the loss on them is not cash and goes to equity directly.
        $assetSaleProceeds = (float) ($ctx->allocation['asset_sale_proceeds'] ?? 0.0);
        $ctx->investingCashFlow -= $organicCapex;
        $ctx->investingCashFlow += $assetSaleProceeds;
        $ctx->netLoanOriginations += (float) ($ctx->allocation['loan_originations'] ?? 0.0) - $assetSaleProceeds;
        $ctx->assetSaleLoss = (float) ($ctx->allocation['asset_sale_loss'] ?? 0.0);
        // Every financing flow is booked at the cash that actually moved, not the share count change at the screen
        // price (an emergency raise goes out at a discount).
        // Deposits taken or withdrawn are financing for a bank (ASC 230), the same as notes issued or repaid, and
        // so is a draw on the committed line or its sweep (ASC 230-10-45-14): a maturity the revolver took out
        // nets to zero across the two balances rather than reading as a repayment.
        $ctx->financingCashFlow = ((float) $stock->getWholesaleDebt() - $debtBeforeAllocation)
            + ((float) $stock->getRevolverDrawn() - $revolverBeforeAllocation)
            + ((float) $stock->getCustomerDeposits() - $depositsBeforeAllocation)
            + (float) ($ctx->allocation['equity_raised'] ?? 0.0)
            - (float) ($ctx->allocation['total_cash_spent'] ?? 0.0)
            - (float) ($ctx->allocation['total_paid'] ?? 0.0);
        $ctx->lifecycleStage = \App\Data\Company\LifecycleStage::fromCashFlowSigns(
            $ctx->operatingCashFlow > 0.0,
            $ctx->investingCashFlow > 0.0,
            $ctx->financingCashFlow > 0.0,
        );
        $stock->setLifecycleStage($ctx->lifecycleStage);

        // Settle this quarter's stock-based compensation in new shares (dilution), a non-cash, non-financing flow.
        if ($ctx->stockCompensation > 0.0 && $currentPrice > 0.0) {
            $vestedShares = $ctx->stockCompensation / $currentPrice;
            $stock->setSharesOutstanding((string) ((float) $stock->getSharesOutstanding() + $vestedShares));
            // What vests gets sold: the supply reaches the tape through the flow channel over the quarter.
            $stock->addCorporateFlowBacklog(-$vestedShares);
        }
    }

    /**
     * The free cash flow the filing reports, net of the expansion the allocation funded, and the Sloan accruals ratio
     * of reported earnings against operating cash.
     */
    private function reportFreeCashFlow(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        $organicCapex = $ctx->allocation['organic_capex'] ?? 0.0;
        $annualFcfPerShare = $ctx->freeCashFlowPerShare / max(0.001, $ctx->dt);
        $actualAnnualCapEx = $ctx->operatingCapex / max(0.001, $ctx->dt);

        $reportedOrganicCapex = $ctx->strategy->allowsPhysicalOrganicCapex() ? $organicCapex : 0.0;
        $annualizedOrganicCapex = $reportedOrganicCapex / max(0.001, $ctx->dt);
        $organicCapexPerShare = $ctx->sharesOutstanding > 0 ? ($annualizedOrganicCapex / $ctx->sharesOutstanding) : 0.0;

        $trueAnnualFcfPerShare = $annualFcfPerShare - $organicCapexPerShare;
        $stock->setFreeCashFlowPerShare((string) $trueAnnualFcfPerShare);

        $ctx->totalReportedCapex = ($actualAnnualCapEx * $ctx->dt) + $reportedOrganicCapex;
        $ctx->trueQuarterlyFcf = ($trueAnnualFcfPerShare * $ctx->sharesOutstanding) * $ctx->dt;

        // Sloan accruals ratio: difference between reported net income and operating cash flow, scaled by total assets.
        $totalAssets = $this->resolveTotalAssets($ctx);
        // Sloan measures REPORTED earnings against operating cash, so the managed entry belongs in the
        // numerator: steering the headline with accruals is exactly the low-quality earnings the anomaly
        // detects, and it prices itself through the consensus discount without any further wiring.
        $quarterlyAccruals = ($ctx->actualQuarterlyNetIncome + $ctx->managedAccrual) - $ctx->operatingCashFlow;
        $accrualsRatio = ($quarterlyAccruals / max(0.001, $ctx->dt)) / $totalAssets;
        $stock->setAccrualsRatio($accrualsRatio);
    }

    /**
     * The price gap a report earns: the immediate share of the news in its log fair-value revision (DellaVigna &
     * Pollet 2009); the rest reaches the price through the pull, which is the post-announcement drift. The news is
     * the revision less the quarter's expected return at the cost of equity, which the price has already earned
     * through its drift, as a surprise is struck against consensus. The dividend is added back, because paying it
     * lowers book just as the ex-date drop lowers the price.
     *
     * @param float $fairValueBefore Fair value per share on the books before the report.
     * @param float $fairValueAfter  Fair value per share on the books it filed.
     * @param float $dividendPaid    Dividend per share paid on the report.
     * @param float $costOfEquity    The annual return the fair value was expected to earn over the quarter.
     */
    public static function reportPriceGap(float $fairValueBefore, float $fairValueAfter, float $dividendPaid, float $costOfEquity): float
    {
        if ($fairValueBefore <= 0.0 || $fairValueAfter + $dividendPaid <= 0.0) {
            return 0.0;
        }

        $news = log(($fairValueAfter + $dividendPaid) / $fairValueBefore) - ($costOfEquity * FinancialConstants::QUARTER_YEARS);

        return exp(self::REPORT_IMMEDIATE_RESPONSE_SHARE * $news) - 1.0;
    }

    /**
     * Fair value per share on the books as they stand, at this tick's macro: the market's own strike, with the cost
     * of equity it was discounted at.
     *
     * @return array{0: float, 1: float}
     */
    private function strikeFairValue(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $health = $this->debtEngine->analyzeDebtHealth($stock, $macroState);
        $strike = $this->marketEngine->strikeFairValue(MarketPricingContext::forStock($stock, $macroState, $health, $this->anchorStakes));

        return [$strike['perceived_fair_value'], $health->costOfEquity];
    }

    /**
     * The report's price move: the immediate share of its fair-value revision plus any announcement with a
     * measured return, booked as a scheduled jump into the name's announcement variance.
     */
    private function executeAnnouncementReturn(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;

        $currentPrice = (float) $stock->getPrice();

        $ctx->priceGapPct = self::reportPriceGap(
            $ctx->fairValueBeforeReport,
            $this->strikeFairValue($stock, $ctx->macroState)[0],
            (float) ($ctx->allocation['dividend_paid'] ?? 0.0),
            $ctx->costOfEquityBeforeReport,
        );
        $ctx->totalShockPct = $ctx->priceGapPct;
        $ctx->corporateActionDescriptions = "";
        $ctx->corporateActions = [];

        $customEventLore = $ctx->eventType !== null ? $this->narrativeEngine->generateLore($ctx->eventType, $ctx->eventContext) : null;
        if ($customEventLore) {
            $ctx->corporateActionDescriptions .= "\n• " . $customEventLore;
            $ctx->corporateActions[] = ['kind' => 'note', 'text' => $customEventLore];
        }

        if ($ctx->goodwillImpairment > 0.0) {
            $impairmentLore = $this->narrativeEngine->generateLore(\App\Service\Event\ShockEvent::GOODWILL_IMPAIRMENT, [
                'amount' => $ctx->goodwillImpairment,
            ]);
            if ($impairmentLore) {
                $ctx->corporateActionDescriptions .= "\n• " . $impairmentLore;
                $ctx->corporateActions[] = ['kind' => 'note', 'text' => $impairmentLore];
            }
        }

        if (!empty($ctx->allocation['events'])) {
            foreach ($ctx->allocation['events'] as $subEvent) {
                if (isset($subEvent['event_type'])) {
                    $desc = $this->narrativeEngine->generateLore($subEvent['event_type'], $subEvent['context'] ?? []);
                } else {
                    $desc = $subEvent['description'] ?? '';
                }
                $ctx->corporateActionDescriptions .= "\n• " . $desc;
                $ctx->corporateActions[] = $subEvent['fact'] ?? ['kind' => 'note', 'text' => $desc];
                // Only an announcement with a measured return carries one; distress is already in fair value.
                $ctx->totalShockPct += (($subEvent['shock'] ?? 0.0) / 100.0);
            }
        }

        $ctx->totalShockPct = max(-self::MAX_QUARTERLY_PRICE_CIRCUIT_BREAKER, min(self::MAX_QUARTERLY_PRICE_CIRCUIT_BREAKER, $ctx->totalShockPct));

        // A firm with no shares outstanding never reaches the capital allocator, so the allocation array can be empty.
        $exDivPrice = ($currentPrice * (1.0 + $ctx->totalShockPct)) - (float) ($ctx->allocation['dividend_paid'] ?? 0.0);
        $newPrice = max(0.01, $exDivPrice);
        $stock->setPrice(number_format($newPrice, 8, '.', ''));

        // The dividend is not a return: the holder has the cash. The announcement return is the move alone.
        $this->recordAnnouncementVariance($stock, log(1.0 + $ctx->totalShockPct), true);
    }

    /** @return list<array<string, mixed>> */
    private function publishEventAndReport(EarningsSimulationContext $ctx): array
    {
        $stock = $ctx->stock;

        $ctx->wacc = $ctx->strategy->getHurdleRate($ctx->health);
        $capital = $ctx->strategy->getPhysicalCapital($stock);
        $ctx->quarterlyEconomicProfit = ($capital * ($ctx->truePostTaxReturn - $ctx->wacc)) / 4.0;

        $evaString = WireFormat::money($ctx->quarterlyEconomicProfit, true) . ' EVA';

        $money = static fn(float $eps): string => $eps < 0 ? '-$' . number_format(abs($eps), 2) : '$' . number_format($eps, 2);
        // The beat or miss is on adjusted earnings; when special items separate the two, the reported figure follows.
        $formattedEps = round($ctx->streetQuarterlyEps, 2) !== round($ctx->actualQuarterlyEps, 2)
            ? $money($ctx->streetQuarterlyEps) . ' adjusted, ' . $money($ctx->actualQuarterlyEps) . ' reported'
            : $money($ctx->actualQuarterlyEps);
        $roundedSurprise = round(abs($ctx->surpriseAmountQuarterly), 2);
        $formattedSurprise = '$' . number_format($roundedSurprise, 2);

        if ($roundedSurprise >= 0.01 && $ctx->surpriseAmountQuarterly > 0.0) {
            $description = "Q-Earnings: {$formattedEps} (Beat expectations by {$formattedSurprise} | {$evaString}).";
        } elseif ($roundedSurprise >= 0.01 && $ctx->surpriseAmountQuarterly < 0.0) {
            $description = "Q-Earnings: {$formattedEps} (Missed expectations by {$formattedSurprise} | {$evaString}).";
        } else {
            $description = "Q-Earnings: {$formattedEps} (Met expectations exactly | {$evaString}).";
        }

        $description .= $ctx->corporateActionDescriptions;

        // The card is built from these: the beat or miss is on adjusted earnings, and a reported figure that special
        // items set apart follows it.
        $facts = [
            'eps' => round($ctx->streetQuarterlyEps, 2),
            'surprise' => round($ctx->surpriseAmountQuarterly, 2),
            'economicProfit' => $ctx->quarterlyEconomicProfit,
            'actions' => $ctx->corporateActions,
        ];
        if (round($ctx->streetQuarterlyEps, 2) !== round($ctx->actualQuarterlyEps, 2)) {
            $facts['reportedEps'] = round($ctx->actualQuarterlyEps, 2);
        }

        $earningsEvent = $this->marketEvent->publish($stock, 'EARNINGS', $description, $ctx->totalShockPct * 100, $facts);

        // The cash that left the price. The tracker measures the tick's return across the ex-dividend
        // drop, and a holder who was paid the dividend did not lose it.
        $earningsEvent['dividend_per_share'] = (float) ($ctx->allocation['dividend_paid'] ?? 0.0);

        $events = [$earningsEvent];
        // A move into a new industry is its own story: the plant the company commits, where, and how far from home.
        if ($ctx->marketEntry !== null) {
            $entry = $ctx->marketEntry;
            $business = lcfirst(SegmentBooks::label($stock, $entry['segment']));
            $events[] = $this->marketEvent->publish(
                $stock,
                'MARKET ENTRY',
                "{$stock->getName()} is moving into {$business}, building " . WireFormat::money($entry['capex']) . " of plant to enter the {$entry['industry']} industry.",
                0.0,
                ['industry' => $entry['industry'], 'business' => $business, 'capex' => $entry['capex']],
                $entry['capex'] / max(1.0, (float) $stock->getPrice() * $ctx->sharesOutstanding),
            );
        }

        $this->eventDispatcher->dispatch(new EarningsReportedEvent($ctx));

        return $events;
    }

    /**
     * Books an announcement return into the name's annualized announcement variance, which the price process
     * gives back from its diffusion (MarketEngine). A report closes the quarter and decays the estimate one
     * report interval. A warning between reports is added at the weight that, once the report has decayed it,
     * counts it the same as the report's own move, so the estimate settles on reports per year times the
     * expected sum of a quarter's squared announcement returns.
     */
    private function recordAnnouncementVariance(Stock $stock, float $logReturn, bool $closesQuarter): void
    {
        $prior = (float) ($stock->getAnnouncementVarianceEma() ?? 0.0);

        if ($closesQuarter) {
            $next = TimeSeries::ewmaAnnualizedVariance($prior, $logReturn, FinancialConstants::QUARTER_YEARS, self::ANNOUNCEMENT_VARIANCE_EMA_YEARS);
        } else {
            $weight = exp(FinancialConstants::QUARTER_YEARS / self::ANNOUNCEMENT_VARIANCE_EMA_YEARS) - 1.0;
            $next = max(0.0, $prior) + ($weight * ($logReturn * $logReturn) / FinancialConstants::QUARTER_YEARS);
        }

        $stock->setAnnouncementVarianceEma($next);
    }
}
