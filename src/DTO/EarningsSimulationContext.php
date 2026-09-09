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
        public readonly float $dt = 0.25
    ) {}

    public float $investedCapital = 0.0;
    public float $corporateTaxRate = 0.0;
    public float $baselineRoic = 0.0;
    public float $baselineVol = 0.0;
    public float $sharesOutstanding = 0.0;
    public float $stableMargin = 0.0;
    public bool $isFinancial = false;
    public float $estimateDispersion = 0.06;
    public float $seasonalFactor = 1.0;
    public float $priorSeasonalFactor = 1.0;
    public float $seasonallyAdjustedRevenue = 0.0;
    public float $seasonallyAdjustedEbit = 0.0;
    public float $structuralOperatingMargin = 0.0;
    /** Calendar quarter (0-3) this report covers; drives seasonality. */
    public int $calendarQuarter = 0;
    /** Fiscal quarter (0-3) after the ticker's fiscal-year offset; drives annual events like the impairment test. */
    public int $fiscalQuarter = 0;
    public int $tickCount = 0;
    public int $ticksPerYear = 252;
    
    // Capacity & Revenue
    public float $capacityUtilization = 0.0;
    public float $structuralRevenue = 0.0;
    public float $expectedRevenue = 0.0;
    public float $fixedCosts = 0.0;
    public float $baselineVariableMargin = 0.0;
    /** Depreciation the firm would charge running its plant at exactly structural capacity, carved out of the cost base. */
    public float $structuralDepreciation = 0.0;
    /** Construction in progress placed in service this quarter; leaves CIP and enters gross PP&E. */
    public float $completedCip = 0.0;
    /** Current capital-goods price level over the vintage the plant was bought at; 1.0 when prices have not moved. */
    public float $replacementCostRatio = 1.0;
    /** Lower-of-cost-or-NRV writedown on unsold inventory this quarter (ASC 330), non-cash. */
    public float $inventoryWriteDown = 0.0;
    /** Expected credit loss provision against trade receivables this quarter (ASC 326), non-cash; negative when released. */
    public float $receivablesProvision = 0.0;
    /** Change in net working capital this quarter; a build consumes cash, a release frees it. */
    public float $deltaWorkingCapital = 0.0;
    /** Portion of the quarter's tax expense postponed by accelerated tax depreciation (ASC 740), non-cash. */
    public float $deferredTaxExpense = 0.0;
    /** Tax that actually left the company this quarter; total expense less the deferred portion. */
    public float $cashTaxPaid = 0.0;
    
    // Variables
    public float $realizedVariableMargin = 0.0;
    
    // Analyst
    public float $analystExpectedRevenue = 0.0;
    public float $analystExpectedVariableCosts = 0.0;
    public float $expectedEbit = 0.0;
    
    // Actuals
    public float $actualRevenue = 0.0;
    public float $previousQuarterlyRevenue = 0.0;
    public float $actualVariableCosts = 0.0;
    public float $ebitda = 0.0;
    public float $ebit = 0.0;
    public float $primaryShockZ = 0.0;
    public ?string $eventType = null;
    public array $eventContext = [];
    public array $streamRevenue = [];
    /** Mandatory CapEx committed by the sector physics this quarter (spectrum, grid rebuild), in dollars. */
    public float $scheduledCapex = 0.0;
    /** Fraction of this quarter's demand taken by (negative) or ceded from (positive) same-industry rivals' idiosyncratic gains. */
    public float $rivalShareDrain = 0.0;
    /** Volume shift from the firm's own real price change (own-price elasticity times price growth above expected inflation). */
    public float $ownPriceVolumeShift = 0.0;
    /** Quarterly stock-based compensation (ASC 718): non-cash expense inside the cost base, settled in shares. */
    public float $stockCompensation = 0.0;
    /** Goodwill written down this quarter under the annual impairment test (ASC 350), non-cash. */
    public float $goodwillImpairment = 0.0;
    /** Provision for credit losses on the earning-asset book charged this quarter, net of any release (financials). */
    public float $creditLossProvision = 0.0;
    /** Earning assets written off against the allowance this quarter (financials). */
    public float $netChargeOffs = 0.0;
    /** Cash deployed into new earning assets less assets sold (financials). */
    public float $netLoanOriginations = 0.0;
    /** Loss realized on earning assets sold below carrying value, booked to equity as other comprehensive loss. */
    public float $assetSaleLoss = 0.0;
    /** Quarterly net cash from operations (net income + D&A - working capital build). */
    public float $operatingCashFlow = 0.0;
    /** Quarterly net cash from investing (negative = net investment). */
    public float $investingCashFlow = 0.0;
    /** Quarterly net cash from financing (positive = net capital raised). */
    public float $financingCashFlow = 0.0;
    /** Dickinson life-cycle stage classified from this quarter's cash-flow signs. */
    public ?\App\Data\LifecycleStage $lifecycleStage = null;
    /** @var array<string, float> Reported operating KPIs emitted by the sector physics. */
    public array $kpis = [];

    // Interest & Depreciation
    public float $expectedInterestExpense = 0.0;
    public float $quarterlyInterestExpense = 0.0;
    public float $quarterlyInterestIncome = 0.0;
    public float $quarterlyDepreciation = 0.0;
    public float $trueOperatingMargin = 0.0;
    public float $operatingCosts = 0.0;
    public ?\App\DTO\DebtMetricsDTO $debtMetrics = null;

    // Net Income
    public float $preTaxIncome = 0.0;
    public float $taxPaid = 0.0;
    public float $expectedQuarterlyNetIncome = 0.0;
    public float $actualQuarterlyNetIncome = 0.0;
    public float $reportedExpectedNetIncome = 0.0;
    public float $reportedActualNetIncome = 0.0;
    public float $truePostTaxReturn = 0.0;

    // EPS & Gap
    public float $actualAnnualEpsRaw = 0.0;
    public float $actualQuarterlyEps = 0.0;
    public float $expectedQuarterlyEps = 0.0;
    public float $surpriseAmountQuarterly = 0.0;
    public float $surprisePct = 0.0;
    public float $priceGapPct = 0.0;

    // FCF & Capital
    public array $allocation = [];
    public float $totalReportedCapex = 0.0;
    public float $trueQuarterlyFcf = 0.0;
    
    // Market Event
    public float $totalShockPct = 0.0;
    public string $corporateActionDescriptions = "";
    
    // Health & Value
    public ?\App\DTO\DebtHealthDTO $health = null;
    public float $wacc = 0.0;
    public float $quarterlyEconomicProfit = 0.0;
}
