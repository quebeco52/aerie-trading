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
    
    // Capacity & Revenue
    public float $capacityUtilization = 0.0;
    public float $structuralRevenue = 0.0;
    public float $expectedRevenue = 0.0;
    public float $fixedCosts = 0.0;
    public float $baselineVariableMargin = 0.0;
    
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
