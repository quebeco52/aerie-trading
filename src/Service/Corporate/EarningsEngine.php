<?php

declare(strict_types=1);

namespace App\Service\Corporate;

use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;
use App\Service\Event\NarrativeEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Market\MarketConsensusEngine;
use App\DTO\EarningsSimulationContext;
use App\Service\Event\EarningsReportedEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Handles the simulation of quarterly earnings reports.
 * Models revenue, operating leverage, analyst consensus, and corporate saturation.
 */
class EarningsEngine
{
    // --- Capacity & Utilization ---
    /** Absolute minimum capacity utilization (10%) to prevent negative revenue on dead companies. */
    public const MIN_CAPACITY_UTILIZATION = 0.10;

    // --- Margins & Volatility ---
    /** Max quarterly asset turnover to prevent revenue hyperinflation. */
    public const MAX_QUARTERLY_ASSET_TURNOVER = 3.0;

    /** Cyclical shift coefficient for variable margins based on output gap. */
    public const CYCLICAL_MARGIN_SHIFT_COEFFICIENT = 0.15;

    /** Coefficient for margin volatility relative to baseline stock volatility. */
    public const MARGIN_VOLATILITY_COEFFICIENT = 0.15;

    /** Failsafe max expected EBIT loss relative to structural revenue. */
    public const MAX_EBIT_LOSS_RATIO = 0.50;

    // --- Valuations & Bounds ---
    /** Minimum valuation premium relative to market PE (floor). */
    public const VALUATION_PREMIUM_MIN = 0.5;

    /** Maximum valuation premium relative to market PE (ceiling). */
    public const VALUATION_PREMIUM_MAX = 3.0;

    /** Fallback surprise percentage when expected EPS is zero. */
    public const ZERO_BASE_SURPRISE_PCT = 0.10;

    /** Default time step in years for quarterly reports. */
    public const QUARTERLY_TIME_STEP = 0.25;

    /** Multiplier applied to baseline volatility to derive base idiosyncratic revenue volatility. */
    public const IDIOSYNCRATIC_REV_VOL_RATIO = 0.25;

    // --- Capacity & Seasonality Limits ---
    /** Hard ceiling on capacity utilization to bound physical operations and depreciation. */
    public const MAX_CAPACITY_UTILIZATION = 1.50;
    /** TAM headroom multiplier allowing seasonal/cyclical volume surges above structural capacity. */
    public const EXPECTED_REVENUE_TAM_HEADROOM = 1.50;

    // --- Cost Convexity & Overtime ---
    /** Capacity utilization threshold above which convex overtime cost penalties begin. */
    public const CAPACITY_OVERTIME_THRESHOLD = 1.00;
    /** Degree of power-law convexity for operating costs when capacity exceeds 100%. */
    public const CAPACITY_OVERTIME_CONVEXITY = 1.50;
    /** Scalar scaling the convex overtime penalty applied to variable cost ratio. */
    public const CAPACITY_OVERTIME_SCALAR = 0.60;

    // --- Earnings Reporting Season ---
    /** Fraction of quarter that passes before the earnings reporting season opens (~14 ticks). */
    public const EARNINGS_REPORTING_LAG_RATIO = 0.22;
    /** Duration of the clustered earnings reporting window as a fraction of the quarter (~25 ticks). */
    public const EARNINGS_SEASON_LENGTH_RATIO = 0.40;

    // --- SUE Dispersion ---
    /** Minimum analyst estimate dispersion floor to avoid division by near-zero in SUE. */
    public const MIN_ESTIMATE_DISPERSION = 0.02;

    /**
     * Constructor.
     *
     * @param MarketEventPublisher $marketEvent Publisher for all market events, news headlines, and shocks.
     * @param MathUtility $mathUtility Utility for advanced mathematical operations (e.g., generating standard normal distribution).
     */
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private MarketEventPublisher $marketEvent,
        private CapitalAllocationEngine $capitalAllocationEngine,
        private DebtEngine $debtEngine,
        private CapExEngine $capExEngine,
        private MathUtility $mathUtility,
        private CorporateMetrics $corporateMetrics,
        private NarrativeEngine $narrativeEngine,
        private MarketConsensusEngine $marketConsensusEngine
    ) {}

    public function calculate(Stock $stock, \App\DTO\MacroStateDTO $macroState, int $tickCount = 0, int $ticksPerYear = 252): ?array
    {
        if ($stock->isBankrupt() || !$this->checkReportingEligibility($stock, $tickCount, $ticksPerYear)) {
            return null;
        }

        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);

        $ctx = new EarningsSimulationContext(
            $stock,
            $macroState,
            $strategy,
            $businessModel,
            self::QUARTERLY_TIME_STEP
        );
        $ctx->tickCount = $tickCount;
        $ctx->ticksPerYear = $ticksPerYear;

        $this->initializeContext($ctx);
        $this->capExEngine->processCipQueue($ctx->stock);
        $this->generateCapacityAndRevenue($ctx);
        $this->processVariableMargins($ctx);
        $this->calculateExpectedVsActualFinancials($ctx);
        $this->calculateInterestAndDepreciation($ctx);
        $this->reconcileTaxesAndNetIncome($ctx);
        $this->calculateEPSAndSurprise($ctx);
        $this->calculateFreeCashFlow($ctx);
        $this->executePriceAndVolatilityShocks($ctx);

        return $this->publishEventAndReport($ctx);
    }

    /**
     * Resolves the deterministic quarter tick on which a ticker reports earnings.
     * Clustered inside the post-quarter reporting season window: [lag, lag + season).
     */
    public static function resolveReportingTick(string $ticker, int $ticksPerYear): int
    {
        $ticksPerQuarter = max(1, (int) ($ticksPerYear / 4));
        $lagTicks    = (int) round($ticksPerQuarter * self::EARNINGS_REPORTING_LAG_RATIO);
        $seasonTicks = max(1, (int) round($ticksPerQuarter * self::EARNINGS_SEASON_LENGTH_RATIO));

        return $lagTicks + (abs(crc32($ticker)) % $seasonTicks);
    }

    private function checkReportingEligibility(Stock $stock, int $tickCount, int $ticksPerYear): bool
    {
        $ticksPerQuarter = max(1, (int) ($ticksPerYear / 4));
        $currentQuarterTick = $tickCount % $ticksPerQuarter;
        $reportingTick = self::resolveReportingTick($stock->getTicker(), $ticksPerYear);

        return $currentQuarterTick === $reportingTick;
    }

    private function initializeContext(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        $ctx->baselineVol = (float) $stock->getVolatility();
        $ctx->sharesOutstanding = (float) $stock->getSharesOutstanding();
        $ctx->stableMargin = max(0.01, (float) $stock->getOperatingMargin());
        $ctx->previousQuarterlyRevenue = (float) $stock->getPreviousRevenue() / 4.0;

        $targetMetrics = $ctx->strategy->getTargetMetrics($stock, $ctx->macroState, $this->mathUtility);
        $ctx->investedCapital = $targetMetrics['invested_capital'];
        $ctx->baselineRoic = $targetMetrics['baseline_roic'];

        $macroTaxRate = $ctx->macroState->corporateTaxRate;
        $ctx->corporateTaxRate = $ctx->strategy->getEffectiveTaxRate($macroTaxRate);
    }

    private function generateCapacityAndRevenue(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        $strategy = $ctx->strategy;
        $macroState = $ctx->macroState;

        $annualTurnover = max(0.01, $ctx->baselineRoic) / ($ctx->stableMargin * (1.0 - $ctx->corporateTaxRate));
        $assetTurnover = min(self::MAX_QUARTERLY_ASSET_TURNOVER, $annualTurnover / 4.0);

        $macroPhysics = $strategy->getMacroPhysics($stock, $macroState);
        $macroDemandShift = $macroPhysics['macro_demand_shift'];
        $pricingPowerMultiplier = $macroPhysics['pricing_power_multiplier'];

        $revenueVol = $ctx->baselineVol * self::IDIOSYNCRATIC_REV_VOL_RATIO;
        $z1 = $this->mathUtility->generateStandardNormal();

        $ticksPerQuarter = max(1, (int) ($ctx->ticksPerYear / 4));
        $ctx->fiscalQuarter = intdiv($ctx->tickCount, $ticksPerQuarter) % 4;
        $factors = $strategy->getSeasonalityFactors();
        $ctx->seasonalFactor = $factors[$ctx->fiscalQuarter] ?? 1.0;
        $ctx->priorSeasonalFactor = $factors[($ctx->fiscalQuarter + 3) % 4] ?? 1.0;

        $priceJumpIntensity = (float) ($stock->getJumpIntensity() ?? 2.00);
        $priceJumpVol = (float) ($stock->getJumpVol() ?? 0.10);

        $jumpIntensity = $priceJumpIntensity * FinancialConstants::FUNDAMENTAL_JUMP_INTENSITY_SCALE;
        $jumpVol = $priceJumpVol * FinancialConstants::FUNDAMENTAL_JUMP_VOL_SCALE;
        $jumpMean = -$priceJumpVol * FinancialConstants::FUNDAMENTAL_JUMP_MEAN_SCALE;

        $jumpData = $this->mathUtility->calculateJumpDiffusion($jumpIntensity, $jumpMean, $jumpVol, $ctx->dt);
        $jumpMagnitude = $jumpData['exponent'] ?? 0.0;

        $idiosyncraticDemandShock = $revenueVol * sqrt($ctx->dt) * $z1;
        $secularGrowthRate = $strategy->getSecularGrowthRate($stock);
        $secularDrift = $secularGrowthRate * $ctx->dt;

        $rawUtilization = $ctx->seasonalFactor * (1.0 + $secularDrift + $macroDemandShift + $idiosyncraticDemandShock + $jumpMagnitude);
        $ctx->capacityUtilization = max(self::MIN_CAPACITY_UTILIZATION, min(self::MAX_CAPACITY_UTILIZATION, $rawUtilization));
        $maxCipDeduction = abs($ctx->investedCapital) * FinancialConstants::MAX_CIP_CAPITAL_DEDUCTION_RATIO;
        $effectiveCip = min($maxCipDeduction, $stock->getTotalCipAmount());
        $revenueGeneratingCapital = max(abs($ctx->investedCapital) * (1.0 - FinancialConstants::MAX_CIP_CAPITAL_DEDUCTION_RATIO), abs($ctx->investedCapital) - $effectiveCip);

        $dynamicSam = FinancialConstants::BASELINE_SECTOR_TAM * $macroState->nominalGdpIndex * (float) ($stock->getSamRatio() ?? 1.0);
        $maxSectorCapacity = $dynamicSam * FinancialConstants::MAX_SECTOR_TAM_CAPACITY_RATIO;
        
        $structuralRevenue = max(1.0, $revenueGeneratingCapital * $assetTurnover * $pricingPowerMultiplier);
        
        if (!$strategy->isFinancial()) {
            $ctx->structuralRevenue = min($maxSectorCapacity, $structuralRevenue);
            $ctx->expectedRevenue = min($maxSectorCapacity * self::EXPECTED_REVENUE_TAM_HEADROOM, $ctx->structuralRevenue * $ctx->capacityUtilization);
        } else {
            $maxFinancialCapacity = $dynamicSam * FinancialConstants::MAX_FINANCIAL_SECTOR_TAM_CAPACITY_RATIO;
            $ctx->structuralRevenue = min($maxFinancialCapacity, $structuralRevenue);
            $ctx->expectedRevenue = min($maxFinancialCapacity * self::EXPECTED_REVENUE_TAM_HEADROOM, $ctx->structuralRevenue * $ctx->capacityUtilization);
        }

        $fixedCostRatio = (float) $stock->getFixedCostRatio();
        $structuralCosts = $ctx->structuralRevenue * (1.0 - $ctx->stableMargin);

        // Beveridge Wage-Price Spiral SG&A Squeeze:
        // When labor tightness causes wage growth above trend (3.5%), corporate overhead/SG&A fixed costs
        // inflate, squeezing margins for firms that cannot pass costs through via pricing power.
        $excessWageGrowth = max(0.0, $macroState->wageGrowth - (MacroEngine::TFP_DRIFT + MacroEngine::TARGET_INFLATION));
        $wageInflationFactor = 1.0 + ($excessWageGrowth / max(0.5, $pricingPowerMultiplier));
        $ctx->fixedCosts = $structuralCosts * $fixedCostRatio * $wageInflationFactor;

        $structuralVariableCosts = $structuralCosts - ($structuralCosts * $fixedCostRatio);
        $ctx->baselineVariableMargin = $structuralVariableCosts / $ctx->structuralRevenue;
    }

    private function processVariableMargins(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        $strategy = $ctx->strategy;

        $kappa = $strategy->getMarginReversionSpeed();
        $outputGap = $ctx->macroState->outputGapEma;
        $beta = (float) $stock->getBeta();

        $cyclicalMarginShift = $outputGap * $beta * self::CYCLICAL_MARGIN_SHIFT_COEFFICIENT;
        $dynamicVariableTheta = min(0.99, max(0.01, $ctx->baselineVariableMargin - $cyclicalMarginShift));

        $z2 = $this->mathUtility->generateStandardNormal();
        $marginVol = $ctx->baselineVol * self::MARGIN_VOLATILITY_COEFFICIENT;

        if ($stock->getStructuralVariableMargin() !== null) {
            $currentVariableMargin = max(0.01, min(0.99, (float) $stock->getStructuralVariableMargin()));
        } else {
            $fixedCostRatio = (float) $stock->getFixedCostRatio();
            $currentVariableMargin = max(0.01, min(0.99, (1.0 - $ctx->stableMargin) * (1.0 - $fixedCostRatio)));
        }

        $realizedVariableMargin = $this->mathUtility->calculateCIR($currentVariableMargin, $kappa, $dynamicVariableTheta, $marginVol, $ctx->dt, $z2);

        $stock->setStructuralVariableMargin($realizedVariableMargin); // Save true state before asymmetric stickiness noise

        // Asymmetric Cost Stickiness (Anderson, Banker, & Janakiraman 2003):
        // Operating costs contract sluggishly when revenue drops, squeezing variable margins during contractions.
        $priorRevenue = $ctx->previousQuarterlyRevenue > 0.0 ? $ctx->previousQuarterlyRevenue : $ctx->expectedRevenue;
        $currentDeseasonalized = $ctx->expectedRevenue / max(0.01, $ctx->seasonalFactor);
        $priorDeseasonalized   = $priorRevenue        / max(0.01, $ctx->priorSeasonalFactor);
        $revenueLogChange = max(-0.50, min(0.50, log(max(0.01, $currentDeseasonalized / max(1.0, $priorDeseasonalized)))));
        $stickyVariableMargin = $this->mathUtility->calculateAsymmetricCostStickiness($realizedVariableMargin, $revenueLogChange);

        $overtimePremium = $this->mathUtility->calculateConvexPenalty(
            $ctx->capacityUtilization - self::CAPACITY_OVERTIME_THRESHOLD,
            self::CAPACITY_OVERTIME_CONVEXITY,
            self::CAPACITY_OVERTIME_SCALAR
        );

        $ctx->realizedVariableMargin = min(0.99, max(0.01, $stickyVariableMargin + $overtimePremium));
    }

    private function calculateExpectedVsActualFinancials(EarningsSimulationContext $ctx): void
    {
        $actuals = $ctx->strategy->computeActualFinancials(
            $ctx->stock,
            $ctx->expectedRevenue,
            $ctx->realizedVariableMargin,
            $ctx->fixedCosts,
            $ctx->baselineVol,
            $ctx->macroState,
            $this->mathUtility
        );
        $ctx->actualRevenue = $actuals->actualRevenue;
        $ctx->actualVariableCosts = $actuals->actualVariableCosts;

        $coverage = $ctx->strategy->getCoverageProfile($ctx->stock);
        $seasonalRatio = $ctx->seasonalFactor / max(0.01, $ctx->priorSeasonalFactor);
        $consensus = $this->marketConsensusEngine->generateConsensus(
            $actuals,
            $coverage,
            $ctx->expectedRevenue,
            $this->mathUtility,
            $ctx->stock,
            $ctx->macroState->marketVolatilityEma,
            $ctx->realizedVariableMargin,
            $seasonalRatio
        );
        $ctx->analystExpectedRevenue = $consensus->analystExpectedRevenue;
        $ctx->analystExpectedVariableCosts = $consensus->analystExpectedVariableCosts;
        $ctx->estimateDispersion = $consensus->estimateDispersion;

        $expectedEbit = $ctx->analystExpectedRevenue - $ctx->fixedCosts - $ctx->analystExpectedVariableCosts;
        $ctx->expectedEbit = max(-$ctx->structuralRevenue * self::MAX_EBIT_LOSS_RATIO, $expectedEbit);

        $ctx->operatingCosts = $ctx->actualVariableCosts + $ctx->fixedCosts;
        $ctx->ebit = $ctx->actualRevenue - $ctx->operatingCosts;

        $ctx->primaryShockZ = $actuals->primaryShockZ;
        $ctx->eventType = $actuals->eventType;
        $ctx->eventContext = $actuals->eventContext;
        $ctx->stock->setEarningsMomentumZ($actuals->streamZ);
        $ctx->streamRevenue = $actuals->streamRevenue;
    }

    private function calculateInterestAndDepreciation(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;

        // Seasonally Adjusted Annual Rate (SAAR) Metrics:
        // Reported quarterly revenue and EBIT oscillate with operational seasonality.
        // Annual-basis metrics (total revenue run-rate, borrowing rate, ICR, debt health)
        // must read the seasonally adjusted run-rate rather than interpreting a seasonal
        // trough/peak as a permanent structural shift.
        $ctx->seasonallyAdjustedRevenue = $ctx->actualRevenue / max(0.01, $ctx->seasonalFactor);
        $realizedCostRatio = $ctx->actualRevenue > 0.0
            ? ($ctx->actualVariableCosts / $ctx->actualRevenue)
            : $ctx->realizedVariableMargin;
        $ctx->seasonallyAdjustedEbit = ($ctx->seasonallyAdjustedRevenue * (1.0 - $realizedCostRatio)) - $ctx->fixedCosts;
        $ctx->structuralOperatingMargin = $ctx->seasonallyAdjustedEbit / max(1.0, $ctx->seasonallyAdjustedRevenue);

        $annualSaarRevenue = MathUtility::calculateSeasonallyAdjustedAnnualRate($ctx->actualRevenue, $ctx->seasonalFactor, 4);
        $stock->setTotalRevenue((string) $annualSaarRevenue);

        $industry = $stock->getIndustry() ?: 'General';
        $customDepreciation = (float) $stock->getDepreciationRate();
        $baseDepreciationRate = $customDepreciation > 0.0 ? $customDepreciation : $this->corporateMetrics->getIndustryDepreciationRate($industry);

        // Units of Production Depreciation Method
        // Depreciation scales directly with actual asset utilization. Extreme utilization naturally accelerates depreciation.
        $productionDepreciationRate = $baseDepreciationRate * $ctx->capacityUtilization;

        $physicalCapital = $ctx->strategy->getPhysicalCapital($stock);
        $maxPhysicalCipDeduction = max(0.0, $physicalCapital) * FinancialConstants::MAX_CIP_CAPITAL_DEDUCTION_RATIO;
        $effectivePhysicalCip = min($maxPhysicalCipDeduction, $stock->getTotalCipAmount());
        $depreciableBase = max(max(0.0, $physicalCapital) * (1.0 - FinancialConstants::MAX_CIP_CAPITAL_DEDUCTION_RATIO), $physicalCapital - $effectivePhysicalCip);
        $annualDepreciation = $depreciableBase * $productionDepreciationRate;
        $ctx->quarterlyDepreciation = $annualDepreciation / 4.0;

        // Reconstruct EBITDA (EBITDA = GAAP EBIT + Depreciation) for FCF, FFO, and reporting
        $ctx->ebitda = $ctx->ebit + $ctx->quarterlyDepreciation;

        $saExpectedRevenue = $ctx->expectedRevenue / max(0.01, $ctx->seasonalFactor);
        $saExpectedEbit = ($saExpectedRevenue * (1.0 - $ctx->baselineVariableMargin)) - $ctx->fixedCosts;
        $saExpectedMargin = $saExpectedEbit / max(1.0, $saExpectedRevenue);
        $expectedDebtMetrics = $this->debtEngine->calculateInterestExpense($stock, $ctx->macroState, false, $saExpectedRevenue * 4.0, $saExpectedMargin);
        $ctx->expectedInterestExpense = $expectedDebtMetrics->interestExpense / 4.0;

        $ctx->trueOperatingMargin = $ctx->ebit / max(1.0, $ctx->actualRevenue);
        $ctx->debtMetrics = $this->debtEngine->calculateInterestExpense($stock, $ctx->macroState, true, $annualSaarRevenue, $ctx->structuralOperatingMargin);

        $annualInterestExpense = $ctx->debtMetrics->interestExpense;
        $ctx->quarterlyInterestExpense = $annualInterestExpense / 4.0;
        // Note: we can't mutate debtMetrics since it's readonly. The DB will store $ctx->quarterlyInterestExpense.

        $stock->setHistoricalFixedRate((string) $ctx->debtMetrics->historicalFixedRate);

        $annualInterestIncome = $ctx->strategy->calculateInterestIncome($stock, $ctx->macroState, $this->mathUtility);
        $ctx->quarterlyInterestIncome = $annualInterestIncome / 4.0;
    }

    private function reconcileTaxesAndNetIncome(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        $expectedEbt = $ctx->expectedEbit - $ctx->expectedInterestExpense + $ctx->quarterlyInterestIncome;
        $actualEbt = $ctx->ebit - $ctx->quarterlyInterestExpense + $ctx->quarterlyInterestIncome;

        $nol = (float) $stock->getNetOperatingLoss();

        if ($actualEbt > 0 && $nol > 0) {
            $maxShield = $actualEbt * FinancialConstants::NOL_MAX_SHIELD_RATIO;
            $shielded = min($maxShield, $nol);
            $taxableIncome = $actualEbt - $shielded;
            $stock->setNetOperatingLoss((string) ($nol - $shielded));
            $ctx->actualQuarterlyNetIncome = $actualEbt - ($taxableIncome * $ctx->corporateTaxRate);
        } elseif ($actualEbt < 0) {
            $stock->setNetOperatingLoss((string) ($nol + abs($actualEbt)));
            $ctx->actualQuarterlyNetIncome = $actualEbt;
        } else {
            $ctx->actualQuarterlyNetIncome = $actualEbt * (1.0 - $ctx->corporateTaxRate);
        }

        if ($expectedEbt > 0 && $nol > 0) {
            $expectedMaxShield = $expectedEbt * FinancialConstants::NOL_MAX_SHIELD_RATIO;
            $expectedShielded = min($expectedMaxShield, $nol);
            $expectedTaxable = $expectedEbt - $expectedShielded;
            $ctx->expectedQuarterlyNetIncome = $expectedEbt - ($expectedTaxable * $ctx->corporateTaxRate);
        } elseif ($expectedEbt < 0) {
            $ctx->expectedQuarterlyNetIncome = $expectedEbt;
        } else {
            $ctx->expectedQuarterlyNetIncome = $expectedEbt * (1.0 - $ctx->corporateTaxRate);
        }

        $ctx->preTaxIncome = $actualEbt;
        $ctx->taxPaid = $actualEbt - $ctx->actualQuarterlyNetIncome;

        $ctx->reportedExpectedNetIncome = $ctx->expectedQuarterlyNetIncome;
        $ctx->reportedActualNetIncome = $ctx->actualQuarterlyNetIncome;

        if ($ctx->businessModel === 'reit') {
            $ctx->reportedExpectedNetIncome += $ctx->quarterlyDepreciation;
            $ctx->reportedActualNetIncome += $ctx->quarterlyDepreciation;
        }

        $annualSaarRevenue = MathUtility::calculateSeasonallyAdjustedAnnualRate($ctx->actualRevenue, $ctx->seasonalFactor, 4);
        $ctx->health = $this->debtEngine->analyzeDebtHealth($stock, $ctx->macroState, $annualSaarRevenue, $ctx->structuralOperatingMargin);
        $ctx->truePostTaxReturn = $ctx->strategy->updateDynamicRoic(
            $stock,
            $ctx->actualQuarterlyNetIncome,
            $ctx->investedCapital,
            $ctx->seasonallyAdjustedEbit,
            $ctx->corporateTaxRate,
            $ctx->health->wacc ?? 0.08,
            $ctx->health->costOfEquity ?? 0.10,
            $ctx->macroState
        );
    }

    private function calculateEPSAndSurprise(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        $shares = max(1.0, $ctx->sharesOutstanding);

        // Quarterly reported EPS for analyst surprise calculation (matches analyst consensus which already anticipates seasonality)
        $ctx->actualQuarterlyEps = $ctx->reportedActualNetIncome / $shares;
        $ctx->expectedQuarterlyEps = $ctx->reportedExpectedNetIncome / $shares;
        $ctx->surpriseAmountQuarterly = $ctx->actualQuarterlyEps - $ctx->expectedQuarterlyEps;

        // Seasonally Adjusted Annual EPS for balance sheet and valuation (Kalman TTM EPS)
        $saEbt = $ctx->seasonallyAdjustedEbit - $ctx->quarterlyInterestExpense + $ctx->quarterlyInterestIncome;
        $saQuarterlyNetIncome = $saEbt > 0.0 ? $saEbt * (1.0 - $ctx->corporateTaxRate) : $saEbt;
        if ($ctx->businessModel === 'reit') {
            $saQuarterlyNetIncome += $ctx->quarterlyDepreciation;
        }
        $saAnnualEpsRaw = ($saQuarterlyNetIncome * 4.0) / $shares;
        $ctx->actualAnnualEpsRaw = $saAnnualEpsRaw;

        $oldEps = (float) $stock->getEarningsPerShare();
        $structuralEps = $oldEps == 0.0 ? $ctx->actualAnnualEpsRaw : $oldEps;
        $ttmEps = $this->mathUtility->calculateKalmanSmoothedEps(
            $structuralEps,
            $ctx->actualAnnualEpsRaw,
            $ctx->baselineVol,
            abs($ctx->macroState->outputGapEma)
        );

        $stock->setEarningsPerShare((string) $ttmEps);

        $rawEpsSurprise = abs($ctx->expectedQuarterlyEps) > 0.01
            ? $ctx->surpriseAmountQuarterly / abs($ctx->expectedQuarterlyEps)
            : ($ctx->surpriseAmountQuarterly > 0 ? self::ZERO_BASE_SURPRISE_PCT : ($ctx->surpriseAmountQuarterly < 0 ? -self::ZERO_BASE_SURPRISE_PCT : 0.0));
        $epsSurprisePct = max(-1.0, min(1.0, $rawEpsSurprise));

        $revenueSurprisePct = abs($ctx->analystExpectedRevenue) > 1.0
            ? ($ctx->actualRevenue - $ctx->analystExpectedRevenue) / abs($ctx->analystExpectedRevenue)
            : 0.0;

        $blendWeights = $ctx->strategy->getSurpriseBlendWeights();
        $epsWeight = $blendWeights['eps_weight'];
        $revWeight = $blendWeights['revenue_weight'];
        $ctx->surprisePct = ($revenueSurprisePct * $revWeight) + ($epsSurprisePct * $epsWeight);
    }

    private function calculateFreeCashFlow(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;

        if ($ctx->sharesOutstanding <= 0) {
            $fcfData = ['fcf_per_share' => 0.0, 'capex' => 0.0];
        } else {
            $capExRatio = (float) $stock->getCapexRatio();
            $outputGap = $ctx->macroState->outputGapEma;
            $capexCyclicality = $ctx->strategy->getCapexCyclicality();
            $cycleCapExModifier = max(0.50, min(1.50, 1.00 + ($outputGap * $capexCyclicality)));

            // Operational CapEx:
            // 1. Maintenance CapEx: replaces depreciating physical capital in ongoing operations.
            //    Scales with macro cyclicality and drops during severe financial distress.
            $solvencyFactor = $ctx->actualQuarterlyNetIncome > 0
                ? 1.0
                : max(0.20, 1.0 + ($ctx->actualQuarterlyNetIncome / max(1.0, abs($ctx->investedCapital))));
            $maintenanceCapEx = $ctx->quarterlyDepreciation * $cycleCapExModifier * $solvencyFactor;

            // 2. Growth CapEx: funded from operational profits scaled by capexRatio.
            $growthCapEx = max(0.0, $ctx->actualQuarterlyNetIncome) * ($capExRatio * $cycleCapExModifier);

            $actualCapEx = $maintenanceCapEx + $growthCapEx;

            $baseWorkingCapitalIntensity = $ctx->strategy->getWorkingCapitalIntensity($stock);
            $deseasonalizedUtilization = $ctx->capacityUtilization / max(0.01, $ctx->seasonalFactor);
            $dynamicWorkingCapitalIntensity = $this->mathUtility->calculateDynamicWorkingCapitalIntensity(
                baselineIntensity: $baseWorkingCapitalIntensity,
                creditSpread: $ctx->macroState->macroCreditSpreadEma,
                capacityUtilization: $deseasonalizedUtilization,
                interbankLiquiditySpread: $ctx->macroState->interbankLiquiditySpreadEma
            );
            $currentAnnualizedRevenue = $ctx->actualRevenue / max(0.001, $ctx->dt);

            $currentNwc = $dynamicWorkingCapitalIntensity * $currentAnnualizedRevenue;
            $priorNwcStr = $stock->getNetWorkingCapital();
            if ($priorNwcStr === null) {
                $priorNwc = $currentNwc; // Seed on first report; no spurious one-time swing
            } else {
                $priorNwc = (float) $priorNwcStr;
            }
            $rawDeltaNwc = $currentNwc - $priorNwc;
            $maxNwcSwing = $currentAnnualizedRevenue * 0.25; // Clamp single-quarter NWC swing to at most 1 quarter of revenue
            $deltaNwc = max(-$maxNwcSwing, min($maxNwcSwing, $rawDeltaNwc));
            $stock->setNetWorkingCapital((string) $currentNwc);

            $fcff = $ctx->actualQuarterlyNetIncome + $ctx->quarterlyDepreciation - $deltaNwc - $actualCapEx;

            $fcfData = [
                'fcf_per_share' => $fcff / $ctx->sharesOutstanding,
                'capex' => $actualCapEx
            ];
        }

        $annualFcfPerShare = $fcfData['fcf_per_share'] / max(0.001, $ctx->dt);
        $actualAnnualCapEx = $fcfData['capex'] / max(0.001, $ctx->dt);

        $reinvestmentRatio = $ctx->quarterlyDepreciation > 0 ? ($fcfData['capex'] / $ctx->quarterlyDepreciation) : 1.0;
        $ctx->strategy->applyAssetDepreciationDecay($stock, $reinvestmentRatio, $ctx->dt);

        $currentPrice = (float) $stock->getPrice();
        $ctx->allocation = $this->capitalAllocationEngine->allocateCapital(
            $stock,
            $ctx->actualAnnualEpsRaw,
            $fcfData['fcf_per_share'],
            $currentPrice,
            $ctx->sharesOutstanding,
            $ctx->macroState,
            $ctx->actualQuarterlyNetIncome
        );

        $stock->setSharesOutstanding((string) $ctx->allocation['new_shares']);

        $organicCapex = $ctx->allocation['organic_capex'] ?? 0.0;
        $reportedOrganicCapex = $ctx->strategy->allowsPhysicalOrganicCapex() ? $organicCapex : 0.0;
        $annualizedOrganicCapex = $reportedOrganicCapex * 4.0;
        $organicCapexPerShare = $ctx->sharesOutstanding > 0 ? ($annualizedOrganicCapex / $ctx->sharesOutstanding) : 0.0;

        $trueAnnualFcfPerShare = $annualFcfPerShare - $organicCapexPerShare;
        $stock->setFreeCashFlowPerShare((string) $trueAnnualFcfPerShare);

        $ctx->totalReportedCapex = ($actualAnnualCapEx / 4.0) + $reportedOrganicCapex;
        $ctx->trueQuarterlyFcf = ($trueAnnualFcfPerShare * $ctx->sharesOutstanding) / 4.0;

        // Sloan (1996) Accruals Anomaly: Accruals = (Net Income - FCF) / Total Assets
        $totalAssets = max(FinancialConstants::MIN_OPERATING_BASE_CASH, (float) $stock->getTotalEquity() + (float) $stock->getTotalDebt());
        $quarterlyAccruals = $ctx->actualQuarterlyNetIncome - $ctx->trueQuarterlyFcf;
        $accrualsRatio = ($quarterlyAccruals * 4.0) / $totalAssets;
        $stock->setAccrualsRatio($accrualsRatio);
    }

    private function executePriceAndVolatilityShocks(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;

        // Derive a composite earnings Z-score from the blended surprise percentage.
        // Standardized by analyst estimate dispersion (SUE): a 6% surprise with σ=0.06 is a 1.0σ event.
        $dispersion = max(self::MIN_ESTIMATE_DISPERSION, $ctx->estimateDispersion);
        $earningsSurpriseZ = $ctx->surprisePct / $dispersion;
        $this->applyVolatilityShock($stock, $earningsSurpriseZ, $ctx->baselineVol);

        $currentPrice = (float) $stock->getPrice();

        if ($ctx->actualAnnualEpsRaw > 0) {
            $currentPE = $currentPrice / $ctx->actualAnnualEpsRaw;
        } else {
            $annualSaarRevenue = MathUtility::calculateSeasonallyAdjustedAnnualRate($ctx->actualRevenue, $ctx->seasonalFactor, 4);
            $salesPerShare = $ctx->sharesOutstanding > 0 ? $annualSaarRevenue / $ctx->sharesOutstanding : 1.0;
            $priceToSales = $salesPerShare > 0 ? $currentPrice / $salesPerShare : 1.0;
            $structuralAfterTaxMargin = max(0.01, (float) $stock->getOperatingMargin() * (1.0 - $ctx->corporateTaxRate));
            $currentPE = $priceToSales * (1.0 / $structuralAfterTaxMargin);
        }

        $valuationPremium = max(self::VALUATION_PREMIUM_MIN, min(self::VALUATION_PREMIUM_MAX, $currentPE / FinancialConstants::BASELINE_MARKET_PE));
        $beta = (float) $stock->getBeta();

        // Growth premium proxy: valuationPremium - 1.0 (so 1.0 -> 0 growth premium, 2.0 -> +1.0 growth premium)
        $growthPremium = max(0.0, $valuationPremium - 1.0);

        // Calculate ERC-driven price gap with empirical market dampening.
        // Note: Raw surprisePct is intentionally passed to preserve calibrated PRICE_GAP_DAMPENING.
        $priceGapPct = $this->mathUtility->calculateEarningsResponseCoefficient(
            $ctx->surprisePct,
            $beta,
            $growthPremium
        );

        $dampedPriceGap = $priceGapPct * FinancialConstants::PRICE_GAP_DAMPENING;
        $ctx->priceGapPct = max(-FinancialConstants::MAX_PRICE_GAP, min(FinancialConstants::MAX_PRICE_GAP, $dampedPriceGap));
        $ctx->totalShockPct = $ctx->priceGapPct;
        $ctx->corporateActionDescriptions = "";

        $customEventLore = $ctx->eventType !== null ? $this->narrativeEngine->generateLore($ctx->eventType, $ctx->eventContext) : null;
        if ($customEventLore) {
            $ctx->corporateActionDescriptions .= "\n• " . $customEventLore;
        }

        if (!empty($ctx->allocation['events'])) {
            foreach ($ctx->allocation['events'] as $subEvent) {
                if (isset($subEvent['event_type'])) {
                    $desc = $this->narrativeEngine->generateLore($subEvent['event_type'], $subEvent['context'] ?? []);
                } else {
                    $desc = $subEvent['description'] ?? '';
                }
                $ctx->corporateActionDescriptions .= "\n• " . $desc;
                $ctx->totalShockPct += ($subEvent['shock'] / 100.0);
            }
        }

        $ctx->totalShockPct = max(-FinancialConstants::MAX_QUARTERLY_PRICE_CIRCUIT_BREAKER, min(FinancialConstants::MAX_QUARTERLY_PRICE_CIRCUIT_BREAKER, $ctx->totalShockPct));

        $exDivPrice = ($currentPrice * (1.0 + $ctx->totalShockPct)) - $ctx->allocation['dividend_paid'];
        $newPrice = max(0.01, $exDivPrice);
        $stock->setPrice(number_format($newPrice, 8, '.', ''));
    }

    private function publishEventAndReport(EarningsSimulationContext $ctx): array
    {
        $stock = $ctx->stock;

        $ctx->wacc = $ctx->strategy->getHurdleRate($ctx->health);
        $capital = $ctx->strategy->getPhysicalCapital($stock);
        $ctx->quarterlyEconomicProfit = ($capital * ($ctx->truePostTaxReturn - $ctx->wacc)) / 4.0;

        $evaAbs = abs($ctx->quarterlyEconomicProfit);
        $formattedEva = $evaAbs >= 1_000_000_000
            ? '$' . number_format($evaAbs / 1_000_000_000, 2) . 'B'
            : '$' . number_format($evaAbs / 1_000_000, 2) . 'M';

        $evaString = $ctx->quarterlyEconomicProfit >= 0 ? "+{$formattedEva} EVA" : "-{$formattedEva} EVA";

        $formattedEps = $ctx->actualQuarterlyEps < 0 ? '-$' . number_format(abs($ctx->actualQuarterlyEps), 2) : '$' . number_format($ctx->actualQuarterlyEps, 2);
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

        $earningsEvent = $this->marketEvent->publish($stock, 'EARNINGS', $description, $ctx->totalShockPct * 100);

        // Update previous revenue for next quarter's NWC calculation (annualized)
        $stock->setPreviousRevenue((string) ($ctx->actualRevenue * 4.0));

        $this->eventDispatcher->dispatch(new EarningsReportedEvent($ctx));

        return [$earningsEvent];
    }

    private function applyVolatilityShock(Stock $stock, float $earningsZ, float $baselineVol): void
    {
        $currentVol = (float) $stock->getCurrentVolatility();
        $zScore = abs($earningsZ);

        if ($zScore > FinancialConstants::SURPRISE_Z_SCORE_THRESHOLD) {
            $shockFactor = $earningsZ < 0
                ? FinancialConstants::VOLATILITY_SHOCK_FACTOR * FinancialConstants::NEGATIVE_SURPRISE_VOL_MULTIPLIER
                : FinancialConstants::VOLATILITY_SHOCK_FACTOR;

            $shockMultiplier = 1.0 + (($zScore - 1.0) * $shockFactor);
            $newVol = min($currentVol * $shockMultiplier, $baselineVol * FinancialConstants::MAX_VOLATILITY_MULTIPLIER);
            $stock->setCurrentVolatility((string) $newVol);
        } elseif ($zScore < FinancialConstants::BORING_Z_SCORE_THRESHOLD && $currentVol > $baselineVol) {
            $newVol = $currentVol - (($currentVol - $baselineVol) * FinancialConstants::VOLATILITY_COOLING_FACTOR);
            $stock->setCurrentVolatility((string) max($newVol, $baselineVol));
        }
    }
}
