<?php

declare(strict_types=1);

namespace App\Service\Corporate\Earnings;

use App\DTO\EarningsSimulationContext;
use App\Service\Corporate\CapExEngine;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\Industry\IndustryShareLedger;
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
     * What new plant earns at the price its industry pays today: the firm's return plus the after-tax margin that price
     * adds over the balanced one on each dollar of capital it turns over (a shortage's premium; a glut's cut takes it
     * off). Plant that keeps the firm's share of a growing market does not move that price.
     */
    public static function returnOnNewPlant(EarningsSimulationContext $ctx): float
    {
        return $ctx->baselineRoic
            + ((1.0 - $ctx->corporateTaxRate) * $ctx->assetTurnover * EarningsEngine::TTM_QUARTERS * ($ctx->industryPriceLevel - 1.0));
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
