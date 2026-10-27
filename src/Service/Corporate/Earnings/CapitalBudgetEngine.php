<?php

declare(strict_types=1);

namespace App\Service\Corporate\Earnings;

use App\Data\Company\IndustryAdjacency;
use App\Data\Company\Sectors;
use App\Data\Company\Segments;
use App\DTO\EarningsSimulationContext;
use App\Service\Corporate\CapExEngine;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Corporate\Segment\EntryMarkets;
use App\Service\Corporate\Segment\SegmentBookOpener;
use App\Service\Corporate\Segment\SegmentBooks;
use App\Service\Corporate\Segment\SegmentEntry;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\Valuation;

/**
 * The quarter's capital budget: maintenance capex at replacement cost, growth capex through the NPV hurdle and the
 * flexible accelerator, the outlays the sector physics schedules, all bounded by internal funds when lenders have
 * closed the door, and the operating and free cash flow that leaves.
 */
final class CapitalBudgetEngine
{
    // --- Capacity Investment ---
    /**
     * Share of the log gap between demand-implied and installed capital a firm closes each year: 25%, a half-life of
     * about 2.4 years. An error-correction investment equation (Bloom, Bond & Van Reenen 2007) closes only ~6% a year
     * through its long-run term and does most of its first-year building through a sales-growth term; plant here has
     * no such term, so its response to a lasting demand shift is folded into this one speed.
     */
    public const CAPITAL_ERROR_CORRECTION_SPEED = 0.25;
    /**
     * Carried stream-state key: log of the plant the firm's expected market calls for over the plant it runs, filed at
     * each report for the market's valuation of the catch-up (Valuation::capacityCatchUpGrowth()). A shortfall files
     * as none while new plant would not earn its cost, since the firm will not build it.
     */
    public const STATE_CAPACITY_GAP = EarningsEngine::CARRIED_STATE_PREFIX . 'capacity_gap';
    /**
     * Carried stream-state key: for a company that keeps plant by segment, its segments' plant to spare, filed apart
     * from the shortfalls in STATE_CAPACITY_GAP because spare plant is only grown into, at trend: netted, a segment
     * that waits would cancel one that builds. Each part is valued on its own (Valuation::capacityCatchUpGrowth() is
     * linear on each side of zero).
     */
    public const STATE_CAPACITY_SPARE = EarningsEngine::CARRIED_STATE_PREFIX . 'capacity_spare';
    /** Least the capex cycle scales every capex line to: half of plan in the deepest trough. */
    public const MIN_CAPEX_CYCLE_MODIFIER = 0.50;
    /** Most the capex cycle scales every capex line to: one and a half times plan at the peak. */
    public const MAX_CAPEX_CYCLE_MODIFIER = 1.50;
    /**
     * Least share of its maintenance capex a firm cut off from borrowing keeps spending (20%): upkeep deferred further
     * would idle the plant.
     */
    public const MIN_DISTRESSED_MAINTENANCE_SHARE = 0.20;

    public function __construct(
        private readonly CapExEngine $capExEngine,
        private readonly CorporateMetrics $corporateMetrics,
        private readonly FixedAssetLedger $plant,
        private readonly WorkingCapitalLedger $workingCapital,
        /** Zero-sum industry share ledger; null (unit tests, harnesses) leaves the accelerator on trend demand. */
        private readonly ?IndustryShareLedger $industryShareLedger = null,
    ) {}

    /**
     * The quarter's operating capital spending and the free cash flow to the firm it leaves: maintenance at the
     * replacement cost of the plant worn out, growth capex through the NPV gate, the capex the sector physics
     * schedules, and the working capital the quarter builds. The plant ledger rolls forward on what is bought outright.
     */
    public function calculateFreeCashFlow(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;

        if ($ctx->sharesOutstanding <= 0) {
            $fcfData = ['fcf_per_share' => 0.0, 'capex' => 0.0, 'direct_capex' => 0.0];
        } else {
            $cycleSignal = $ctx->strategy->getCapexCycleSignal($stock, $ctx->macroState);
            $capexCyclicality = $ctx->strategy->getCapexCyclicality();
            $cycleCapExModifier = max(self::MIN_CAPEX_CYCLE_MODIFIER, min(self::MAX_CAPEX_CYCLE_MODIFIER, 1.0 + ($cycleSignal * $capexCyclicality)));

            // Operational CapEx:
            // 1. Maintenance CapEx: replaces depreciating physical capital in ongoing operations, at the capital-goods
            //    replacement cost of the plant worn out, scaled with the capex cycle. Plant idled in an industry glut is
            //    left to wear out (Abel & Eberly 1994), and so is running plant its market no longer needs while new
            //    plant earns below its cost of capital: the firm's (resolvePlantGate()) or a segment's
            //    (resolveSegmentGates()).
            $ctx->replacementCostRatio = $this->plant->resolveReplacementCostRatio($ctx);
            $this->resolvePlantGate($ctx);
            $this->resolveSegmentGates($ctx);
            $this->resolveEarningPower($ctx);
            $maintenanceCapEx = $ctx->quarterlyDepreciation * $ctx->replacementCostRatio * $cycleCapExModifier * self::plantKept($ctx);
            if ($ctx->strategy->isFinancial()) {
                // A balance-sheet business keeps no plant ledger: what it spends to stand still (branches,
                // systems) is expensed as it is spent, so the cash out is the depreciation charge and nothing
                // is capitalized. Any other figure would leave cash without an asset or a charge behind it.
                $maintenanceCapEx = $ctx->quarterlyDepreciation;
            }

            $currentAnnualizedRevenue = $ctx->actualRevenue / max(0.001, $ctx->dt);
            $priorNwcStr = $stock->getNetWorkingCapital();
            $currentNwc = $this->workingCapital->rollForwardWorkingCapitalLedger($ctx, $currentAnnualizedRevenue);

            // Seed on first report; no spurious one-time swing. The ledger has already moved to the new
            // balances, so cash moves by exactly that much: clamping only the cash leg put the difference on
            // the balance sheet with no account behind it.
            $priorNwc = $priorNwcStr === null ? $currentNwc : (float) $priorNwcStr;
            $deltaNwc = $currentNwc - $priorNwc;
            $ctx->deltaWorkingCapital = $deltaNwc;

            // Stock-based compensation is a non-cash expense: added back to operating cash flow (ASC 718).
            // Inventory writedowns and credit-loss provisions are non-cash, exactly like depreciation and
            // equity compensation: they hit reported earnings but no money moves, so they come back here.
            $operatingCashFlow = $ctx->actualQuarterlyNetIncome + $ctx->quarterlyDepreciation + $ctx->stockCompensation
                + $ctx->inventoryWriteDown + $ctx->plantImpairment + $ctx->receivablesProvision + $ctx->creditLossProvision + $ctx->deferredTaxExpense
                - $deltaNwc;
            $internalFunds = $this->resolveInternalFunds($ctx, $operatingCashFlow);

            // A firm in breach of its maintenance covenants (coverage under the minimum, or leverage over the limit)
            // gets no new money from its lenders, so it builds only what the quarter's cash and its spare cash pay
            // for: the scheduled outlays it is committed to first, then upkeep down to the least that keeps the plant
            // running, then growth.
            $isFundingConstrained = !$ctx->strategy->isFinancial()
                && (!$ctx->health->hasLeverageHeadroom || $ctx->health->interestCoverage < $ctx->strategy->getMinIcr());
            if ($isFundingConstrained) {
                $maintenanceCapEx = self::fundingConstrainedMaintenance($maintenanceCapEx, $ctx->scheduledCapex, $internalFunds);
            }
            $ctx->maintenanceCapex = $maintenanceCapEx;

            // 2. Growth CapEx: fundamental reinvestment planned on normalized earnings power, gated by the NPV
            //    hurdle and bounded by the internal funds the maintenance spend leaves (and, constrained, the
            //    scheduled spend too).
            $committedSpend = $maintenanceCapEx + ($isFundingConstrained ? $ctx->scheduledCapex : 0.0);
            $growthCapEx = $this->calculateGrowthCapEx($ctx, $cycleCapExModifier, $maintenanceCapEx, max(0.0, $internalFunds - $committedSpend));
            // A company with cash its own plans leave, and lenders still willing, may start building into an industry
            // next to its own; the plant is queued as construction (enterMarket()).
            $ventureCapEx = $isFundingConstrained || $ctx->health->wantsToPaydownDebt
                ? 0.0
                : $this->enterMarket($ctx, $internalFunds - $committedSpend - $ctx->scheduledCapex - $growthCapEx - ($ctx->expansionBudget ?? 0.0));

            // 3. Scheduled CapEx: outlays the sector physics itself commits (spectrum licences, grid rebuilds).
            //    Committed, so no funding gate cuts it; a cash shortfall is the treasury's problem. The asset is
            //    not productive on day one, so it is queued as construction-in-progress.
            $deploysIntoEarningAssets = $ctx->strategy->isFinancial() && $stock->hasEarningAssetLedger();
            if ($ctx->scheduledCapex > 0.0 && !$deploysIntoEarningAssets) {
                $this->capExEngine->allocateGrowthCapEx($stock, $ctx->scheduledCapex);
            }

            // A lender's growth spend is loans made, not plant built: it lands on the earning-asset ledger
            // the day the cash leaves, with no construction lag.
            if ($deploysIntoEarningAssets && ($growthCapEx + $ctx->scheduledCapex) > 0.0) {
                $deployed = $growthCapEx + $ctx->scheduledCapex;
                $stock->setEarningAssets((string) ((float) $stock->getEarningAssets() + $deployed));
                $ctx->netLoanOriginations += $deployed;
            }

            $actualCapEx = $maintenanceCapEx + $growthCapEx + $ctx->scheduledCapex + $ventureCapEx;

            $fcff = $operatingCashFlow - $actualCapEx;
            $ctx->operatingCashFlow = $operatingCashFlow;
            $ctx->investingCashFlow = -$actualCapEx;

            $fcfData = [
                'fcf_per_share' => $fcff / $ctx->sharesOutstanding,
                'capex' => $actualCapEx,
                // Only spend that buys an asset outright lands in PP&E this quarter. Scheduled CapEx is
                // queued as construction in progress and reaches the ledger when it is placed in service,
                // so counting it here as well would capitalize the same dollar twice.
                'direct_capex' => $maintenanceCapEx + $growthCapEx,
            ];
        }

        $ctx->freeCashFlowPerShare = $fcfData['fcf_per_share'];
        $ctx->operatingCapex = $fcfData['capex'];

        // Replacement CapEx is the bar the firm has to clear to stand still, so the reinvestment ratio is
        // measured against the depreciation charge restated at today's prices, not the historical-cost charge, on the
        // plant in use. A venture into a new industry is not reinvestment in this business.
        $replacementDepreciation = $ctx->quarterlyDepreciation * $ctx->replacementCostRatio * self::plantKept($ctx);
        $reinvestmentRatio = $replacementDepreciation > 0 ? (($fcfData['capex'] - ($ctx->marketEntry['capex'] ?? 0.0)) / $replacementDepreciation) : 1.0;
        $ctx->strategy->recordReinvestmentRatio($stock, $reinvestmentRatio, $ctx->dt);

        $this->plant->rollForwardFixedAssetLedger($ctx, $fcfData['direct_capex']);
    }

    /**
     * Growth CapEx builds the plant demand calls for (resolveExpansionBudget(), the flexible accelerator): the firm
     * keeps its share of a growing market and closes part of any gap between the plant that share implies and the
     * plant it runs, so a firm that has fallen behind a market short of plant catches up and one ahead of it waits.
     * Two gates apply:
     *  1. NPV rule: no growth investment while new plant would earn less than the firm's true cost of capital at the
     *     price the industry pays today (returnOnNewPlant()), whoever runs it.
     *  2. Funding constraint: spend is bounded by the internal funds the quarter's other plant spend leaves.
     * What the accelerator still allows after this spend is left on the context for the treasury.
     *
     * A lender has no plant to plan: its loan book grows by the reinvestment identity (Damodaran), its capexRatio of
     * normalized earnings power (structural ROIC x invested capital), so a boom quarter does not trigger a splurge.
     */
    private function calculateGrowthCapEx(EarningsSimulationContext $ctx, float $cycleCapExModifier, float $maintenanceCapEx, float $fundingCapacity): float
    {
        $stock = $ctx->stock;
        $manager = $stock->getManagementProfile();
        $hurdleRate = $ctx->strategy->getHurdleRate($ctx->health);
        if ($ctx->segments !== []) {
            return $this->calculateSegmentGrowthCapEx($ctx, $cycleCapExModifier, $maintenanceCapEx, $fundingCapacity, $manager->growthHurdle($hurdleRate, false));
        }

        $expansionBudget = $this->resolveExpansionBudget($ctx, $cycleCapExModifier, $maintenanceCapEx);
        $ctx->expansionBudget = $expansionBudget;

        if ($expansionBudget === null) {
            // Jensen (1986): the agency cost of free cash flow is accepting projects that do not clear the cost of
            // capital. A loan book is sized by management's appetite, so the style bends the hurdle it applies and an
            // empire builder keeps lending through returns a disciplined board would refuse.
            $reinvestmentRate = max(0.0, (float) $stock->getCapexRatio()) * $manager->reinvestmentBias();
            if ($reinvestmentRate <= 0.0 || $ctx->baselineRoic < $manager->growthHurdle($hurdleRate, true)) {
                return 0.0;
            }

            return min($ctx->baselineRoic * abs($ctx->investedCapital) / 4.0 * $reinvestmentRate * $cycleCapExModifier, $fundingCapacity);
        }

        if (self::returnOnNewPlant($ctx) < $manager->growthHurdle($hurdleRate, false)) {
            // New plant would not earn its cost, so none is built, by the treasury either: a shortfall is not closed by
            // building, and the market prices no catch-up for it, only the plant to spare that demand grows into.
            $ctx->expansionBudget = 0.0;
            $state = $stock->getEarningsMomentumZ() ?? [];
            $state[self::STATE_CAPACITY_GAP] = min(0.0, (float) ($state[self::STATE_CAPACITY_GAP] ?? 0.0));
            $stock->setEarningsMomentumZ($state);

            return 0.0;
        }

        $growthCapEx = min($expansionBudget, $fundingCapacity);
        $ctx->expansionBudget = max(0.0, $expansionBudget - $growthCapEx);

        return $growthCapEx;
    }

    /**
     * Growth capex for a company that keeps plant by segment (SegmentBooks): each segment runs the accelerator toward
     * its own market (its industry's growth and its own record's gap) and the gate at the price its own industry pays
     * (resolveSegmentGates()), so a segment in a glut waits while one short of plant builds. A by-product segment
     * builds with the main segment's plant. Internal funds go to the plans that earn most first, as far as management
     * lets capital follow return (SegmentBooks::allotByReturn()), and what each plan still allows is left for the
     * treasury. The catch-up the market prices is the segments' shortfalls and their plant to spare, each weighed by
     * the segment's share of the revenue and filed apart.
     */
    private function calculateSegmentGrowthCapEx(EarningsSimulationContext $ctx, float $cycleCapExModifier, float $maintenanceCapEx, float $fundingCapacity, float $growthHurdle): float
    {
        $stock = $ctx->stock;
        $this->resolveSegmentGates($ctx, $growthHurdle);
        $industries = SegmentBooks::industries($stock);
        $joint = SegmentBooks::jointSegments($stock);
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $inflation = max(0.0, $ctx->macroState->inflationEma);
        $ctx->segmentPlantBase = $stock->getGrossPpe() !== null ? max(0.0, $stock->getNetPpe()) : abs($ctx->investedCapital);

        $budgets = [];
        $gaps = [];
        foreach ($ctx->segments as $segment => $books) {
            if (in_array($segment, $joint, true)) {
                continue;
            }
            $strategy = $segment === Segments::MAIN ? $ctx->strategy : Sectors::strategyFor($industries[$segment] ?? '');
            $capitalGap = $books['capital_gap'] ?? 0.0;
            $gaps[$segment] = $books['gap'] ?? 0.0;
            $plant = $ctx->segmentPlantBase * $books['plant'];

            if (!($books['builds'] ?? true)) {
                // As for the company on one plant: no shortfall is closed by building, and none is priced.
                $gaps[$segment] = min(0.0, $gaps[$segment]);
                $budgets[$segment] = 0.0;
            } else {
                $trendNominalGrowth = max(0.0, $strategy->getFadedSecularGrowthRate($stock, $ctx->macroState->totalTime) + $inflation);
                $growth = Valuation::flexibleAcceleratorGrowth($trendNominalGrowth, $capitalGap, self::CAPITAL_ERROR_CORRECTION_SPEED);
                $budgets[$segment] = max(0.0, ($plant * $growth * $ctx->dt * $cycleCapExModifier * $books['load']) - ($plant * (1.0 - $books['load']))
                    + self::pathPlant($ctx, $segment, $books, $plant * exp($capitalGap)));
            }
        }
        $main = $ctx->segments[Segments::MAIN] ?? null;
        foreach ($joint as $segment) {
            $mainPlant = $main['plant'] ?? 0.0;
            $budgets[$segment] = $mainPlant > 0.0 ? ($budgets[Segments::MAIN] ?? 0.0) * ($ctx->segments[$segment]['plant'] ?? 0.0) / $mainPlant : 0.0;
            $gaps[$segment] = $gaps[Segments::MAIN] ?? 0.0;
        }

        $shortfall = 0.0;
        $spare = 0.0;
        foreach ($gaps as $segment => $gap) {
            $weight = (float) ($momentum[SegmentBooks::STATE_TARGET . $segment] ?? $ctx->segments[$segment]['plant'] ?? 0.0);
            $shortfall += $weight * max(0.0, $gap);
            $spare += $weight * min(0.0, $gap);
        }
        $stock->setEarningsMomentumZ([self::STATE_CAPACITY_GAP => $shortfall, self::STATE_CAPACITY_SPARE => $spare] + $momentum);

        // The replacement-cost slice of upkeep already restated part of the plant: each plan gives up its share of it.
        $planned = array_sum($budgets);
        $budget = max(0.0, $planned - self::revaluationBooked($ctx, $maintenanceCapEx));
        $plans = array_map(static fn(float $plan): float => $planned > 0.0 ? $plan * $budget / $planned : 0.0, $budgets);
        $growthCapEx = min($budget, $fundingCapacity);
        $ctx->expansionBudget = max(0.0, $budget - $growthCapEx);
        // A by-product's plan rides the main plan: they are funded as one and split as they were planned.
        $groupOf = static fn(string $segment): string => in_array($segment, $joint, true) ? Segments::MAIN : $segment;
        $groupPlans = [];
        foreach ($plans as $segment => $plan) {
            $groupPlans[$groupOf($segment)] = ($groupPlans[$groupOf($segment)] ?? 0.0) + $plan;
        }
        $returns = array_map(static fn(array $books): float => $books['return'] ?? 0.0, $ctx->segments);
        $groupAllotted = SegmentBooks::allotByReturn($groupPlans, $returns, $growthCapEx, $stock->getManagementProfile()->weakSegmentSupport());
        foreach ($ctx->segments as $segment => $books) {
            $group = $groupOf($segment);
            $allotted = ($groupPlans[$group] ?? 0.0) > 0.0 ? ($groupAllotted[$group] ?? 0.0) * ($plans[$segment] ?? 0.0) / $groupPlans[$group] : 0.0;
            $books['growth'] = $allotted;
            $books['leftover'] = max(0.0, ($plans[$segment] ?? 0.0) - $allotted);
            $ctx->segments[$segment] = $books;
        }

        return $growthCapEx;
    }

    /**
     * A company with spare cash may start building a business in an industry next to one it knows (SegmentEntry):
     * rarely, at its manager's appetite for deals, and only where the venture is worth more than its cost once its
     * learning is paid for. It is valued at the return the industry's incumbents earn on their plant, less what it
     * cannot copy of their margin, at the hurdle its manager applies; the market is drawn in proportion to what each is
     * worth weighed by how close it is, as a company searches near home first (Cyert & March 1963), and the venture
     * must fit in the room the industry's fringe holds. A holding company buys businesses and builds none, and a
     * company takes on one new industry at a time, building or buying (SegmentBooks::isDiversifying()). Draws are keyed
     * (MathUtility::hashedUniform()), so nothing is taken from the stream every other draw follows.
     *
     * @param float $spareFunds Internal funds left after the company's own plans.
     * @return float The capex the venture commits this quarter, queued as construction.
     */
    private function enterMarket(EarningsSimulationContext $ctx, float $spareFunds): float
    {
        $stock = $ctx->stock;
        $ledger = $this->industryShareLedger;
        $strategy = $ctx->strategy;
        if ($ledger === null || $strategy->isFinancial() || !$strategy->allowsPhysicalOrganicCapex() || $stock->getGrossPpe() === null || !SegmentBooks::canEnter($stock)
            || IndustryAdjacency::isHoldingCompany((string) $stock->getIndustry()) || $ctx->macroState->totalTime < SegmentEntry::FIRST_ENTRY_YEARS
            || SegmentBooks::isDiversifying($stock)) {
            return 0.0;
        }
        $plantBase = max(0.0, $stock->getNetPpe());
        $manager = $stock->getManagementProfile();
        $salt = $ledger->runSalt();
        if ($plantBase <= 0.0 || $spareFunds <= 0.0
            || MathUtility::hashedUniform($salt, "entry:{$stock->getTicker()}:{$ctx->tickCount}") >= SegmentEntry::entryChance($manager->acquisitionBias(), $ctx->dt)) {
            return 0.0;
        }

        $hurdle = $manager->appliedHurdle($strategy->getHurdleRate($ctx->health));
        $longRunGrowth = MacroConstants::TREND_REAL_GROWTH + max(0.0, $ctx->macroState->inflationEma);
        $plans = [];
        foreach ((new EntryMarkets($ledger))->survey($stock, $ctx->macroState, $ctx->tickCount, $ctx->ticksPerYear) as $target => $market) {
            $construction = SegmentEntry::ventureConstruction($plantBase, $market['market'], $market['turnover']);
            if ($construction <= 0.0 || $construction > $spareFunds || $construction * $market['turnover'] / $market['market'] > $market['room']) {
                continue;
            }
            $margin = SegmentEntry::attainedMargin($market['margin'], $market['closeness']);
            $gap = SegmentEntry::learningGap($market['closeness']);
            $learnedReturn = (1.0 - $ctx->corporateTaxRate) * $market['turnover'] * $margin;
            $value = SegmentEntry::value($learnedReturn, $hurdle, $longRunGrowth, $market['excess'], $learnedReturn * $gap);
            if ($value > 0.0) {
                $plans[$target] = ['value' => $value, 'closeness' => $market['closeness'], 'construction' => $construction, 'margin' => $margin, 'turnover' => $market['turnover'], 'gap' => $gap, 'market' => $market['market'], 'room' => $market['room'], 'typical' => $market['typical']];
            }
        }
        $target = SegmentEntry::choose(array_map(static fn(array $plan): float => $plan['value'] * $plan['closeness'], $plans), MathUtility::hashedUniform($salt, "entry-market:{$stock->getTicker()}:{$ctx->tickCount}"));
        if ($target === null) {
            return 0.0;
        }

        $this->startVenture($ctx, $target, $plans[$target], $plantBase);

        return $plans[$target]['construction'];
    }

    /**
     * Books a venture into a new industry: the company's books open if it kept none (SegmentBookOpener::
     * openForEntry()), the venture's plant is queued as construction and booked as building (SegmentBooks::
     * startVenture()), at the share of the industry's market its plant will hold, and the industry's ledger anchors it
     * at that plant, which adds to supply as it comes on line (IndustryShareLedger::openVentureRecord()). It means to
     * grow to the share a typical incumbent holds there, as far as the room allows and it can manage
     * (SegmentEntry::ventureTarget()); what it will earn once learned is drawn now and shown only as it learns
     * (SegmentEntry::outcome()).
     *
     * @param array{value: float, closeness: float, construction: float, margin: float, turnover: float, gap: float, market: float, room: float, typical: float} $plan
     */
    private function startVenture(EarningsSimulationContext $ctx, string $industry, array $plan, float $plantBase): void
    {
        $stock = $ctx->stock;
        $segment = Segments::enteredKey($industry);
        $construction = $plan['construction'];
        (new SegmentBookOpener($this->corporateMetrics, $this->industryShareLedger))->openForEntry($stock, $ctx->strategy, $ctx->macroState, $ctx->tickCount, $ctx->ticksPerYear);
        // Filed first, so the books know the industry it is entering before its market is struck, and its market grows
        // with that industry's outlook as the next report files it.
        $stock->setEarningsMomentumZ([SegmentBooks::STATE_PENDING . $segment => $construction] + ($stock->getEarningsMomentumZ() ?? []));
        $momentum = [SegmentBooks::STATE_OUTLOOK . $segment => $this->industryShareLedger?->resolveSegmentBeliefs($stock)[$segment] ?? 0.0] + ($stock->getEarningsMomentumZ() ?? []);
        $stock->setEarningsMomentumZ($momentum);

        $years = $ctx->macroState->totalTime;
        $firmShare = $this->corporateMetrics->calculateAddressableShare($stock, $ctx->strategy->getEvaluationCapital((float) $stock->getTotalEquity(), $stock->getInvestedCapital()), $ctx->macroState);
        $revenue = $construction * $plan['turnover'];
        // A venture holds what its plant sells of the market its industry's firms sell into, not the share its parent
        // holds of its own.
        $marketShare = SegmentBooks::entryMarketShare($revenue, $plan['market'], 0.0);
        // Plant shares are of the capital the company's plant sells on, as its revenue is.
        $capital = $ctx->revenueGeneratingCapital > 0.0 ? $ctx->revenueGeneratingCapital : $plantBase;
        $stock->setEarningsMomentumZ(SegmentBooks::startVenture(
            $segment,
            $construction,
            $construction / ($capital + $construction),
            CorporateMetrics::secularMarketGrowth($stock, $years),
            SegmentBooks::marketGrowths($stock, $years)[$segment] ?? 1.0,
            $firmShare,
            $marketShare,
            [
                'margin' => $plan['margin'],
                'turnover' => $plan['turnover'],
                'learning' => $plan['gap'],
                'outcome' => SegmentEntry::outcome($plan['closeness'], MathUtility::hashedUniform($this->industryShareLedger?->runSalt() ?? 0, "entry-outcome:{$stock->getTicker()}:{$ctx->tickCount}")),
                'target' => SegmentEntry::ventureTarget($marketShare, $plan['typical'], $plan['room']),
                'start' => $years,
            ],
        ) + $momentum);
        $this->capExEngine->allocateGrowthCapEx($stock, $construction);
        $this->industryShareLedger?->openVentureRecord($stock, $segment, $revenue, $marketShare, $ctx->macroState, $ctx->tickCount);
        $ctx->marketEntry = ['industry' => $industry, 'segment' => $segment, 'capex' => $construction, 'closeness' => $plan['closeness']];
    }

    /**
     * The part of the quarter's upkeep that restated plant at today's prices rather than replacing it at its booked
     * cost: the price level's slice of what upkeep spent, whatever share of the plant it kept. Growth spend nets it
     * out, since it already carried part of the price level onto the plant ledger.
     */
    private static function revaluationBooked(EarningsSimulationContext $ctx, float $maintenanceCapEx): float
    {
        return $ctx->replacementCostRatio > 0.0 ? max(0.0, $maintenanceCapEx * (1.0 - (1.0 / $ctx->replacementCostRatio))) : 0.0;
    }

    /**
     * Each segment's gate, once a report, for the upkeep, the growth plan and the deals that read it: the return its
     * new plant earns at its industry's price today against the true hurdle, and its gap to its market against the
     * plant in use. A segment that clears the hurdle replaces all the wear on its running plant; one that does not
     * replaces only the wear on the plant its market still needs, so a weak segment ahead of its market shrinks back
     * toward it by depreciation, and as its supply falls its price and return recover (Maksimovic & Phillips 2002:
     * diversified firms shrink their less productive segments). Management's support for weak segments keeps that share
     * of the rest (ManagementProfile::weakSegmentSupport()). A by-product follows the main segment. Whether each
     * segment builds is filed for the deals between reports.
     *
     * A business entered in play that has shown what it earns and still earns less on its plant at a balanced price
     * than its manager's hurdle is put up for sale whole (DivestitureEngine), as firms sell the businesses they entered
     * that did not work out (Kaplan & Weisbach 1992): one built, once it has learned enough to turn a profit; one
     * bought as a going concern, once integrated.
     */
    private function resolveSegmentGates(EarningsSimulationContext $ctx, ?float $growthHurdle = null): void
    {
        if ($ctx->segments === [] || isset($ctx->segments[array_key_first($ctx->segments)]['builds'])) {
            return;
        }

        $stock = $ctx->stock;
        $industries = SegmentBooks::industries($stock);
        $joint = SegmentBooks::jointSegments($stock);
        $growthHurdle ??= $stock->getManagementProfile()->growthHurdle($ctx->strategy->getHurdleRate($ctx->health), false);
        $support = $stock->getManagementProfile()->weakSegmentSupport();
        $wears = !$ctx->strategy->depreciatesByUnitsOfProduction();
        $pending = SegmentBooks::pending($stock->getEarningsMomentumZ() ?? []);
        $plantBase = $stock->getGrossPpe() !== null ? max(0.0, $stock->getNetPpe()) : abs($ctx->investedCapital);
        $filed = [];
        foreach ([...array_diff(array_keys($ctx->segments), $joint), ...array_intersect(array_keys($ctx->segments), $joint)] as $segment) {
            $books = $ctx->segments[$segment];
            if (in_array($segment, $joint, true)) {
                $main = $ctx->segments[Segments::MAIN] ?? [];
                $books = ['capital_gap' => $main['capital_gap'] ?? 0.0, 'gap' => $main['gap'] ?? 0.0, 'return' => $main['return'] ?? 0.0, 'builds' => $main['builds'] ?? true, 'replaced' => $main['replaced'] ?? 1.0] + $books;
            } else {
                $strategy = $segment === Segments::MAIN ? $ctx->strategy : Sectors::strategyFor($industries[$segment] ?? '');
                $capitalGap = $this->industryShareLedger?->resolveSegmentCapacityGap($stock, $segment, $ctx->macroState, IndustryShareLedger::secularExcessGrowth($strategy, $stock)) ?? 0.0;
                // Plant a venture still has under construction is plant it has for its market, so it is not built
                // twice.
                $installed = $books['plant'] * $plantBase;
                if (($pending[$segment] ?? 0.0) > 0.0 && $installed > 0.0) {
                    $capitalGap -= log1p($pending[$segment] / $installed);
                }
                $books['capital_gap'] = $capitalGap;
                $books['gap'] = $capitalGap - log(max(OperatingCapacityEngine::MIN_CAPACITY_UTILIZATION, $books['load']));
                $books['return'] = self::segmentReturnOnPlant($ctx, $books);
                $books['builds'] = $books['return'] >= $growthHurdle;
                $books['replaced'] = $books['builds'] || !$wears ? 1.0 : $support + ((1.0 - $support) * min(1.0, exp($books['gap'])));
            }
            $ctx->segments[$segment] = $books;
            $filed[SegmentBooks::STATE_BUILDS . $segment] = $books['builds'] ? 1.0 : 0.0;
        }

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $learning = SegmentBooks::learningGaps($momentum);
        $appliedHurdle = $stock->getManagementProfile()->appliedHurdle($ctx->strategy->getHurdleRate($ctx->health));
        foreach ($ctx->segments as $segment => $books) {
            $givenUp = isset($momentum[SegmentBooks::STATE_VENTURE_ABANDONED . $segment]);
            if (Segments::enteredIndustry($segment) === null || (!$givenUp && (1.0 - $ctx->corporateTaxRate) * $books['turnover'] * $books['margin'] >= $appliedHurdle)) {
                continue;
            }
            $shown = $givenUp || (SegmentBooks::isBuilt($momentum, $segment) ? ($learning[$segment] ?? 0.0) < 1.0 : SegmentBooks::isIntegrated($momentum, $segment));
            if ($shown && !SegmentBooks::isForSale($momentum, $segment)) {
                $filed[SegmentBooks::STATE_FOR_SALE . $segment] = $ctx->macroState->totalTime;
            }
        }
        $stock->setEarningsMomentumZ($filed + $momentum);
    }

    /**
     * Whether each business the company runs earns its cost of capital on its plant at a balanced price, its after-tax
     * margin times its turnover, filed for the deals between reports: a company none of whose businesses does buys
     * outside them (AcquisitionEngine). Read at a balanced price, not today's, as firms diversify on poor prospects at
     * home (Lang & Stulz 1994; Matsusaka 2001) and meet a glut by consolidating within it (Andrade & Stafford 2004);
     * and on the plant, not on capital that carries the goodwill of past deals. A company on one plant files its main
     * segment's.
     */
    private function resolveEarningPower(EarningsSimulationContext $ctx): void
    {
        if ($ctx->strategy->isFinancial()) {
            return;
        }

        $hurdle = $ctx->stock->getManagementProfile()->growthHurdle($ctx->strategy->getHurdleRate($ctx->health), false);
        $businesses = $ctx->segments !== [] ? $ctx->segments : [Segments::MAIN => ['margin' => $ctx->stableMargin, 'turnover' => $ctx->assetTurnover * EarningsEngine::TTM_QUARTERS]];
        $filed = [];
        foreach ($businesses as $segment => $books) {
            $filed[SegmentBooks::STATE_PAYS . $segment] = (1.0 - $ctx->corporateTaxRate) * $books['turnover'] * $books['margin'] >= $hurdle ? 1.0 : 0.0;
        }
        $ctx->stock->setEarningsMomentumZ($filed + ($ctx->stock->getEarningsMomentumZ() ?? []));
    }

    /**
     * The gate for a company on one plant, as resolveSegmentGates() sets it for a segment: while its new plant would
     * not earn the true hurdle at its industry's price today, it replaces only the wear on the running plant its market
     * still needs, so a firm ahead of its market shrinks back toward it by depreciation and its supply falls until the
     * price and its return recover. Management's support for a weak business keeps that share of the rest.
     */
    private function resolvePlantGate(EarningsSimulationContext $ctx): void
    {
        $ctx->plantReplaced = 1.0;
        $stock = $ctx->stock;
        $manager = $stock->getManagementProfile();
        if ($ctx->segments !== [] || $ctx->strategy->isFinancial() || $ctx->strategy->depreciatesByUnitsOfProduction()
            || self::returnOnNewPlant($ctx) >= $manager->growthHurdle($ctx->strategy->getHurdleRate($ctx->health), false)) {
            return;
        }

        $capitalGap = $this->industryShareLedger?->resolveTrendCapacityGap($stock, $ctx->macroState, IndustryShareLedger::secularExcessGrowth($ctx->strategy, $stock));
        if ($capitalGap !== null) {
            $support = $manager->weakSegmentSupport();
            $gap = $capitalGap - log(max(OperatingCapacityEngine::MIN_CAPACITY_UTILIZATION, $ctx->plantLoad));
            $ctx->plantReplaced = $support + ((1.0 - $support) * min(1.0, exp($gap)));
        }
    }

    /**
     * Share of the plant whose wear the firm replaces: the plant in use it keeps (plantInUse(), resolvePlantGate()), or
     * for a company that keeps plant by segment, each segment's running plant it keeps (resolveSegmentGates()).
     */
    private static function plantKept(EarningsSimulationContext $ctx): float
    {
        if ($ctx->segments === [] || $ctx->strategy->depreciatesByUnitsOfProduction()) {
            return self::plantInUse($ctx) * $ctx->plantReplaced;
        }

        $joint = SegmentBooks::jointSegments($ctx->stock);
        $kept = 0.0;
        foreach ($ctx->segments as $segment => $books) {
            $running = in_array($segment, $joint, true) ? ($ctx->segments[Segments::MAIN]['load'] ?? 1.0) : $books['load'];
            $kept += $books['plant'] * $running * ($books['replaced'] ?? 1.0);
        }

        return $kept;
    }

    /**
     * The plant a segment on a path of its own needs for the step the path takes before its next report
     * (SegmentBooks::segmentPath()): its trend plant grown from the level the ledger priced this quarter to the level
     * the quarter's physics leaves (a venture's, the level a quarter on), a revision of the belief included. Built on
     * the trend plant, not on the plant installed, so a segment behind its path does not fall further behind as the
     * path climbs, and apart from the cycle, which moves the build around a path, not the path. Nothing for any other
     * segment.
     *
     * @param array{path?: float} $books
     */
    private static function pathPlant(EarningsSimulationContext $ctx, string $segment, array $books, float $trendPlant): float
    {
        $next = SegmentBooks::segmentPath($ctx->strategy, $ctx->stock, $segment, $ctx->macroState->totalTime + $ctx->dt)['level'] ?? null;

        return isset($books['path']) && $next !== null ? $trendPlant * (exp($next - $books['path']) - 1.0) : 0.0;
    }

    /**
     * The plant shares of a company that keeps plant by segment, rolled after the quarter: each segment adds the growth
     * capex it was allotted and its part of what the treasury built on top, in proportion to what its plan still
     * allowed; the outlays the main model schedules go to the main segment and the by-products that ride its plant; and
     * each loses the wear on its idle plant and on the plant it let go (SegmentBooks::rollPlant();
     * resolveSegmentGates()), a by-product segment on the main segment's.
     */
    public function rollSegmentPlant(EarningsSimulationContext $ctx, float $treasuryGrowthCapEx): void
    {
        if ($ctx->segments === []) {
            return;
        }

        $joint = SegmentBooks::jointSegments($ctx->stock);
        $mainPlant = [];
        foreach ([Segments::MAIN, ...$joint] as $segment) {
            $mainPlant[$segment] = $ctx->segments[$segment]['plant'] ?? 0.0;
        }
        $leftover = array_sum(array_column($ctx->segments, 'leftover'));
        $growth = [];
        $loads = [];
        foreach ($ctx->segments as $segment => $books) {
            $growth[$segment] = $books['growth'] + ($leftover > 0.0 ? $treasuryGrowthCapEx * $books['leftover'] / $leftover : 0.0)
                + (isset($mainPlant[$segment]) && array_sum($mainPlant) > 0.0 ? $ctx->scheduledCapex * $mainPlant[$segment] / array_sum($mainPlant) : 0.0);
            $loads[$segment] = (in_array($segment, $joint, true) ? ($ctx->segments[Segments::MAIN]['load'] ?? 1.0) : $books['load']) * ($books['replaced'] ?? 1.0);
        }
        // The shares are of the capital the plant sells on, so what is built is weighed against that, not the plant
        // alone.
        $plant = SegmentBooks::rollPlant(
            array_map(static fn(array $books): float => $books['plant'], $ctx->segments),
            $ctx->revenueGeneratingCapital > 0.0 ? $ctx->revenueGeneratingCapital : $ctx->segmentPlantBase,
            $growth,
            $loads,
            $ctx->strategy->depreciatesByUnitsOfProduction() ? 0.0 : $ctx->quarterlyDepreciation,
        );

        $filed = [];
        foreach ($plant as $segment => $share) {
            $filed[SegmentBooks::STATE_PLANT . $segment] = $share;
        }
        $ctx->stock->setEarningsMomentumZ($filed + ($ctx->stock->getEarningsMomentumZ() ?? []));
    }

    /**
     * What new plant earns at the price its industry pays today: the firm's return plus the after-tax margin that price
     * adds over the balanced one on each dollar of capital it turns over (a shortage's premium; a glut's cut takes it
     * off). Plant that keeps the firm's share of a growing market does not move that price.
     */
    public static function returnOnNewPlant(EarningsSimulationContext $ctx): float
    {
        return self::returnOnPlantAt($ctx, $ctx->industryPriceLevel);
    }

    /** returnOnNewPlant() at a given industry price. */
    private static function returnOnPlantAt(EarningsSimulationContext $ctx, float $industryPriceLevel): float
    {
        return $ctx->baselineRoic
            + ((1.0 - $ctx->corporateTaxRate) * $ctx->assetTurnover * EarningsEngine::TTM_QUARTERS * ($industryPriceLevel - 1.0));
    }

    /**
     * returnOnNewPlant() for one segment of a company that keeps plant by segment, at its own margin, turnover and
     * industry price (FirmEconomics::returnOnSegmentPlant()).
     *
     * @param array{margin: float, turnover: float, price: float} $books
     */
    private static function segmentReturnOnPlant(EarningsSimulationContext $ctx, array $books): float
    {
        return FirmEconomics::returnOnSegmentPlant($ctx->baselineRoic, $ctx->stableMargin, $ctx->assetTurnover * EarningsEngine::TTM_QUARTERS, $books['margin'], $books['turnover'], $books['price'], $ctx->corporateTaxRate);
    }

    /**
     * The cash the firm can put into plant without borrowing: the quarter's operating cash flow less what a
     * pass-through must distribute, plus the treasury above its operating floor, or less what the treasury is short
     * of it, since a treasury below its floor is refilled before anything is built (Fazzari, Hubbard & Petersen 1988).
     */
    private function resolveInternalFunds(EarningsSimulationContext $ctx, float $operatingCashFlow): float
    {
        $stock = $ctx->stock;
        $operatingBase = $this->corporateMetrics->calculateOperatingBase((float) $stock->getTotalRevenue(), (float) $stock->getTotalEquity());
        $minOperatingCash = $ctx->strategy->calculateMinOperatingCash($operatingBase, (float) $stock->getCustomerDeposits(), (float) $stock->getWholesaleDebt());
        // What a pass-through must distribute is not the firm's to reinvest: a REIT's acquisitions are funded
        // from what the distribution rule leaves it, its spare cash and its borrowing, never from the dividend.
        $requiredDistribution = max(0.0, $ctx->actualQuarterlyNetIncome) * $ctx->strategy->getMinimumDistributionRatio();

        return $operatingCashFlow - $requiredDistribution + ((float) $stock->getCorporateTreasury() - $minOperatingCash);
    }

    /**
     * The upkeep a firm cut off from borrowing can pay for (Campello, Graham & Harvey 2010: constrained firms cut
     * investment to their own cash): what its internal funds leave after its committed outlays, never above the plan
     * and never below the share that keeps the plant running.
     */
    public static function fundingConstrainedMaintenance(float $plannedMaintenance, float $committedOutlays, float $internalFunds): float
    {
        $floor = $plannedMaintenance * self::MIN_DISTRESSED_MAINTENANCE_SHARE;

        return max($floor, min($plannedMaintenance, $internalFunds - $committedOutlays));
    }

    /**
     * Growth plant the quarter's demand calls for, by the flexible accelerator in error-correction form: the
     * plant grows with the nominal demand its sector sees and closes a share of the gap between the plant the
     * firm's share of trend demand implies and the plant it runs (Chenery 1952; speed from Bloom, Bond & Van
     * Reenen 2007). A firm that has built ahead of its market adds nothing until demand catches up; one that
     * has fallen behind builds faster. The cycle signal scales it as it scales every capex line.
     *
     * Replacement-cost maintenance has already carried part of the price level onto the plant ledger this
     * quarter (the slice it replaced dearer than it was booked), so only the remainder is growth spend. Null
     * for a lender, whose expansion is a loan book sized by its funding and capital, not plant.
     */
    private function resolveExpansionBudget(EarningsSimulationContext $ctx, float $cycleCapExModifier, float $maintenanceCapEx): ?float
    {
        if ($ctx->strategy->isFinancial()) {
            return null;
        }

        $stock = $ctx->stock;
        $trendNominalGrowth = max(0.0, $ctx->strategy->getFadedSecularGrowthRate($stock, $ctx->macroState->totalTime) + max(0.0, $ctx->macroState->inflationEma));
        $capitalGap = $this->industryShareLedger?->resolveTrendCapacityGap(
            $stock,
            $ctx->macroState,
            IndustryShareLedger::secularExcessGrowth($ctx->strategy, $stock),
        ) ?? 0.0;
        $plantGrowth = Valuation::flexibleAcceleratorGrowth($trendNominalGrowth, $capitalGap, self::CAPITAL_ERROR_CORRECTION_SPEED);
        // Plant idled in a glut comes back as demand does, so the gap the market prices is against the plant in use.
        $stock->setEarningsMomentumZ([self::STATE_CAPACITY_GAP => $capitalGap - log(max(OperatingCapacityEngine::MIN_CAPACITY_UTILIZATION, $ctx->plantLoad))] + ($stock->getEarningsMomentumZ() ?? []));

        $revaluationAlreadyBooked = self::revaluationBooked($ctx, $maintenanceCapEx);
        $plantBase = $stock->getGrossPpe() !== null ? max(0.0, $stock->getNetPpe()) : abs($ctx->investedCapital);

        // Plant idled in an industry glut meets new demand first: growth is planned on the plant in use.
        return max(0.0, ($plantBase * $plantGrowth * $ctx->dt * $cycleCapExModifier * $ctx->plantLoad) - ($plantBase * (1.0 - $ctx->plantLoad)) - $revaluationAlreadyBooked);
    }

    /**
     * Share of the plant whose wear the firm replaces: the plant in use, or all of it for a plant charged by units of
     * production, whose depreciation already follows the output it runs.
     */
    private static function plantInUse(EarningsSimulationContext $ctx): float
    {
        return $ctx->strategy->depreciatesByUnitsOfProduction() ? 1.0 : $ctx->plantLoad;
    }
}
