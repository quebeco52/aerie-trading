<?php

declare(strict_types=1);

namespace App\Service\Corporate\Earnings;

use App\Data\Company\Sectors;
use App\Data\Company\Segments;
use App\DTO\EarningsSimulationContext;
use App\Service\Corporate\CapExEngine;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Corporate\Segment\SegmentBooks;
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
     * Share of the log gap between demand-implied and installed capital a firm closes each year: 6.2% (Bloom, Bond &
     * Van Reenen 2007, UK company panel, error-correction term).
     */
    public const CAPITAL_ERROR_CORRECTION_SPEED = 0.062;
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
            //    left to wear out (Abel & Eberly 1994).
            $ctx->replacementCostRatio = $this->plant->resolveReplacementCostRatio($ctx);
            $maintenanceCapEx = $ctx->quarterlyDepreciation * $ctx->replacementCostRatio * $cycleCapExModifier * self::plantInUse($ctx);
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

            $actualCapEx = $maintenanceCapEx + $growthCapEx + $ctx->scheduledCapex;

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
        // plant in use.
        $replacementDepreciation = $ctx->quarterlyDepreciation * $ctx->replacementCostRatio * self::plantInUse($ctx);
        $reinvestmentRatio = $replacementDepreciation > 0 ? ($fcfData['capex'] / $replacementDepreciation) : 1.0;
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
            // New plant would not earn its cost, so none is built: a shortfall is not closed by building, and the
            // market prices no catch-up for it, only the plant to spare that demand grows into.
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
     * its own market (its industry's growth and its own record's gap) and the gate at the price its own industry pays,
     * so a segment in a glut waits while one short of plant builds. A by-product segment builds with the main segment's
     * plant. The segments' plans share the internal funds pro rata, and what each still allows is left for the
     * treasury. The catch-up the market prices is the segments' shortfalls and their plant to spare, each weighed by
     * the segment's share of the revenue and filed apart.
     */
    private function calculateSegmentGrowthCapEx(EarningsSimulationContext $ctx, float $cycleCapExModifier, float $maintenanceCapEx, float $fundingCapacity, float $growthHurdle): float
    {
        $stock = $ctx->stock;
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
            $capitalGap = $this->industryShareLedger?->resolveSegmentCapacityGap($stock, $segment, $ctx->macroState, IndustryShareLedger::secularExcessGrowth($strategy, $stock)) ?? 0.0;
            $gaps[$segment] = $capitalGap - log(max(OperatingCapacityEngine::MIN_CAPACITY_UTILIZATION, $books['load']));
            $plant = $ctx->segmentPlantBase * $books['plant'];

            if (self::returnOnPlantAt($ctx, $books['price']) < $growthHurdle) {
                // As for the company on one plant: no shortfall is closed by building, and none is priced.
                $gaps[$segment] = min(0.0, $gaps[$segment]);
                $budgets[$segment] = 0.0;
            } else {
                $trendNominalGrowth = max(0.0, $strategy->getFadedSecularGrowthRate($stock, $ctx->macroState->totalTime) + $inflation);
                $growth = Valuation::flexibleAcceleratorGrowth($trendNominalGrowth, $capitalGap, self::CAPITAL_ERROR_CORRECTION_SPEED);
                $budgets[$segment] = max(0.0, ($plant * $growth * $ctx->dt * $cycleCapExModifier * $books['load']) - ($plant * (1.0 - $books['load'])));
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

        $planned = array_sum($budgets);
        $budget = max(0.0, $planned - max(0.0, $maintenanceCapEx - $ctx->quarterlyDepreciation));
        $growthCapEx = min($budget, $fundingCapacity);
        $ctx->expansionBudget = max(0.0, $budget - $growthCapEx);
        foreach ($ctx->segments as $segment => $books) {
            $share = $planned > 0.0 ? ($budgets[$segment] ?? 0.0) / $planned : 0.0;
            $books['growth'] = $growthCapEx * $share;
            $books['leftover'] = $ctx->expansionBudget * $share;
            $ctx->segments[$segment] = $books;
        }

        return $growthCapEx;
    }

    /**
     * The plant shares of a company that keeps plant by segment, rolled after the quarter: each segment adds the growth
     * capex it was allotted and its part of what the treasury built on top, in proportion to what its plan still
     * allowed; the outlays the main model schedules go to the main segment and the by-products that ride its plant; and
     * each loses the wear on its idle plant (SegmentBooks::rollPlant()), a by-product segment on the main segment's.
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
            $loads[$segment] = in_array($segment, $joint, true) ? ($ctx->segments[Segments::MAIN]['load'] ?? 1.0) : $books['load'];
        }
        $plant = SegmentBooks::rollPlant(
            array_map(static fn(array $books): float => $books['plant'], $ctx->segments),
            $ctx->segmentPlantBase,
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

    /** returnOnNewPlant() at a given industry price: one segment's, for a company that keeps plant by segment. */
    private static function returnOnPlantAt(EarningsSimulationContext $ctx, float $industryPriceLevel): float
    {
        return $ctx->baselineRoic
            + ((1.0 - $ctx->corporateTaxRate) * $ctx->assetTurnover * EarningsEngine::TTM_QUARTERS * ($industryPriceLevel - 1.0));
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

        $revaluationAlreadyBooked = max(0.0, $maintenanceCapEx - $ctx->quarterlyDepreciation);
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
