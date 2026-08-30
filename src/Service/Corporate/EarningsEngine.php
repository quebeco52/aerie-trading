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

    private function checkReportingEligibility(Stock $stock, int $tickCount, int $ticksPerYear): bool
    {
        $ticksPerQuarter = (int) ($ticksPerYear / 4);
        $ticksPerSeason = $ticksPerQuarter;
        $currentQuarterTick = $tickCount % $ticksPerQuarter;

        if ($currentQuarterTick > $ticksPerSeason) {
            return false;
        }

        $reportingTick = abs(crc32($stock->getTicker())) % max(1, $ticksPerSeason);
        return $currentQuarterTick === $reportingTick;
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

        $priceJumpIntensity = (float) ($stock->getJumpIntensity() ?? 2.00);
        $priceJumpMean = (float) ($stock->getJumpMean() ?? -0.05);
        $priceJumpVol = (float) ($stock->getJumpVol() ?? 0.10);

        $jumpIntensity = $priceJumpIntensity * FinancialConstants::FUNDAMENTAL_JUMP_INTENSITY_SCALE;
        $jumpMean = $priceJumpMean * FinancialConstants::FUNDAMENTAL_JUMP_MEAN_SCALE;
        $jumpVol = $priceJumpVol * FinancialConstants::FUNDAMENTAL_JUMP_VOL_SCALE;

        $jumpData = $this->mathUtility->calculateJumpDiffusion($jumpIntensity, $jumpMean, $jumpVol, $ctx->dt);
        $jumpMagnitude = $jumpData['exponent'] ?? 0.0;

        $idiosyncraticDemandShock = $revenueVol * sqrt($ctx->dt) * $z1;
        $secularGrowthRate = $strategy->getSecularGrowthRate($stock);
        $secularDrift = $secularGrowthRate * $ctx->dt;

        $ctx->capacityUtilization = max(self::MIN_CAPACITY_UTILIZATION, 1.0 + $secularDrift + $macroDemandShift + $idiosyncraticDemandShock + $jumpMagnitude);
        $revenueGeneratingCapital = max(0.0, abs($ctx->investedCapital) - $stock->getTotalCipAmount());
        $ctx->structuralRevenue = max(1.0, $revenueGeneratingCapital * $assetTurnover * $pricingPowerMultiplier);
        $ctx->expectedRevenue = $ctx->structuralRevenue * $ctx->capacityUtilization;

        $fixedCostRatio = (float) $stock->getFixedCostRatio();
        $structuralCosts = $ctx->structuralRevenue * (1.0 - $ctx->stableMargin);
        $ctx->fixedCosts = $structuralCosts * $fixedCostRatio;

        $structuralVariableCosts = $structuralCosts - $ctx->fixedCosts;
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
        $ctx->realizedVariableMargin = min(0.99, max(0.01, $realizedVariableMargin));

        $stock->setStructuralVariableMargin($ctx->realizedVariableMargin);
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
        $consensus = $this->marketConsensusEngine->generateConsensus($actuals, $coverage, $ctx->expectedRevenue, $this->mathUtility, $ctx->stock, $ctx->macroState->marketVolatilityEma);
        $ctx->analystExpectedRevenue = $consensus->analystExpectedRevenue;
        $ctx->analystExpectedVariableCosts = $consensus->analystExpectedVariableCosts;

        $expectedEbit = $ctx->analystExpectedRevenue - $ctx->fixedCosts - $ctx->analystExpectedVariableCosts;
        $ctx->expectedEbit = max(-$ctx->structuralRevenue * self::MAX_EBIT_LOSS_RATIO, $expectedEbit);

        $ctx->operatingCosts = $ctx->actualVariableCosts + $ctx->fixedCosts;
        $ctx->ebitda = $ctx->actualRevenue - $ctx->operatingCosts;

        $ctx->primaryShockZ = $actuals->primaryShockZ;
        $ctx->eventType = $actuals->eventType;
        $ctx->eventContext = $actuals->eventContext;
        $ctx->stock->setEarningsMomentumZ($actuals->streamZ);
        $ctx->streamRevenue = $actuals->streamRevenue;
    }

    private function calculateInterestAndDepreciation(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;

        $ctx->previousQuarterlyRevenue = (float) $stock->getPreviousRevenue();
        $stock->setTotalRevenue((string) ($ctx->actualRevenue * 4.0));

        $industry = $stock->getIndustry() ?: 'General';
        $customDepreciation = (float) $stock->getDepreciationRate();
        $baseDepreciationRate = $customDepreciation > 0.0 ? $customDepreciation : $this->corporateMetrics->getIndustryDepreciationRate($industry);

        // Units of Production Depreciation Method
        // Depreciation scales directly with actual asset utilization. Extreme utilization naturally accelerates depreciation.
        $productionDepreciationRate = $baseDepreciationRate * $ctx->capacityUtilization;

        $depreciableBase = max(0.0, $ctx->strategy->getPhysicalCapital($stock) - $stock->getTotalCipAmount());
        $annualDepreciation = $depreciableBase * $productionDepreciationRate;
        $ctx->quarterlyDepreciation = $annualDepreciation / 4.0;

        // Finalize true EBIT by subtracting depreciation from EBITDA
        $ctx->ebit = $ctx->ebitda - $ctx->quarterlyDepreciation;
        $ctx->expectedEbit = max(-$ctx->structuralRevenue * self::MAX_EBIT_LOSS_RATIO, $ctx->expectedEbit - $ctx->quarterlyDepreciation);

        $expectedOperatingMargin = $ctx->expectedEbit / max(1.0, $ctx->expectedRevenue);
        $expectedDebtMetrics = $this->debtEngine->calculateInterestExpense($stock, $ctx->macroState, false, $ctx->expectedRevenue * 4.0, $expectedOperatingMargin);
        $ctx->expectedInterestExpense = $expectedDebtMetrics->interestExpense / 4.0;

        $ctx->trueOperatingMargin = $ctx->ebit / max(1.0, $ctx->actualRevenue);
        $ctx->debtMetrics = $this->debtEngine->calculateInterestExpense($stock, $ctx->macroState, true, $ctx->actualRevenue * 4.0, $ctx->trueOperatingMargin);

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

        $ctx->health = $this->debtEngine->analyzeDebtHealth($stock, $ctx->macroState, $ctx->actualRevenue * 4.0, $ctx->trueOperatingMargin);
        $ctx->truePostTaxReturn = $ctx->strategy->updateDynamicRoic(
            $stock,
            $ctx->actualQuarterlyNetIncome,
            $ctx->investedCapital,
            $ctx->ebit,
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

        $expectedAnnualEps = ($ctx->reportedExpectedNetIncome * 4.0) / $shares;
        $ctx->actualAnnualEpsRaw = ($ctx->reportedActualNetIncome * 4.0) / $shares;

        $oldEps = (float) $stock->getEarningsPerShare();
        $structuralEps = $oldEps == 0.0 ? $ctx->actualAnnualEpsRaw : $oldEps;
        $ttmEps = $this->mathUtility->calculateKalmanSmoothedEps(
            $structuralEps,
            $ctx->actualAnnualEpsRaw,
            $ctx->baselineVol,
            abs($ctx->macroState->outputGapEma)
        );

        $stock->setEarningsPerShare((string) $ttmEps);

        $expectedAnnualEpsDrifted = $expectedAnnualEps;

        $ctx->actualQuarterlyEps = $ctx->actualAnnualEpsRaw / 4.0;
        $ctx->expectedQuarterlyEps = $expectedAnnualEpsDrifted / 4.0;
        $ctx->surpriseAmountQuarterly = $ctx->actualQuarterlyEps - $ctx->expectedQuarterlyEps;

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

            $physicalCapital = $ctx->strategy->getPhysicalCapital($stock);
            $baselineIncomeForCapEx = max($physicalCapital * 0.02, max(0.0, $ctx->actualQuarterlyNetIncome));
            $actualCapEx = $baselineIncomeForCapEx * ($capExRatio * $cycleCapExModifier);

            $workingCapitalIntensity = $ctx->strategy->getWorkingCapitalIntensity($stock);
            $deltaNwc = $workingCapitalIntensity * ($ctx->actualRevenue - $ctx->previousQuarterlyRevenue);

            $fcff = $ctx->actualQuarterlyNetIncome + $ctx->quarterlyDepreciation - $deltaNwc - $actualCapEx;

            $fcfData = [
                'fcf_per_share' => $fcff / $ctx->sharesOutstanding,
                'capex' => $actualCapEx
            ];
        }

        $annualFcfPerShare = $fcfData['fcf_per_share'] * 4.0;
        $actualAnnualCapEx = $fcfData['capex'] * 4.0;

        $reinvestmentRatio = $ctx->quarterlyDepreciation > 0 ? ($fcfData['capex'] / $ctx->quarterlyDepreciation) : 1.0;
        $ctx->strategy->applyAssetDepreciationDecay($stock, $reinvestmentRatio, 0.25);

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
    }

    private function executePriceAndVolatilityShocks(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;

        // Derive a composite earnings Z-score from the blended surprise percentage.
        // Scale by baseline volatility to normalize: a 10% surprise on a 20% vol stock ≈ 0.5σ event.
        $earningsSurpriseZ = $ctx->baselineVol > 0.01
            ? $ctx->surprisePct / $ctx->baselineVol
            : $ctx->primaryShockZ;
        $this->applyVolatilityShock($stock, $earningsSurpriseZ, $ctx->baselineVol);

        $currentPrice = (float) $stock->getPrice();

        if ($ctx->actualAnnualEpsRaw > 0) {
            $currentPE = $currentPrice / $ctx->actualAnnualEpsRaw;
        } else {
            $salesPerShare = $ctx->sharesOutstanding > 0 ? ($ctx->actualRevenue * 4.0) / $ctx->sharesOutstanding : 1.0;
            $priceToSales = $salesPerShare > 0 ? $currentPrice / $salesPerShare : 1.0;
            $structuralAfterTaxMargin = max(0.01, (float) $stock->getOperatingMargin() * (1.0 - $ctx->corporateTaxRate));
            $currentPE = $priceToSales * (1.0 / $structuralAfterTaxMargin);
        }

        $valuationPremium = max(self::VALUATION_PREMIUM_MIN, min(self::VALUATION_PREMIUM_MAX, $currentPE / FinancialConstants::BASELINE_MARKET_PE));
        $beta = (float) $stock->getBeta();

        // Growth premium proxy: valuationPremium - 1.0 (so 1.0 -> 0 growth premium, 2.0 -> +1.0 growth premium)
        $growthPremium = max(0.0, $valuationPremium - 1.0);

        // Calculate ERC-driven price gap with empirical market dampening
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

        // Update previous revenue for next quarter's NWC calculation
        $stock->setPreviousRevenue((string) $ctx->actualRevenue);

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
