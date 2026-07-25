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

/**
 * Handles the simulation of quarterly earnings reports.
 * Models revenue, operating leverage, analyst consensus, and corporate saturation.
 */
class EarningsEngine
{
    // --- Capacity & Utilization ---
    /** Absolute minimum capacity utilization (10%) to prevent negative revenue on dead companies. */
    public const MIN_CAPACITY_UTILIZATION = 0.10;

    /** Threshold (105%) beyond which assets suffer accelerated wear and tear. */
    public const CAPACITY_STRAIN_THRESHOLD = 1.05;

    /** Multiplier for accelerated depreciation when operating above the strain threshold. */
    public const WEAR_AND_TEAR_STRAIN_MULTIPLIER = 1.5;

    // --- Margins & Volatility ---
    /** Max quarterly asset turnover to prevent revenue hyperinflation. */
    public const MAX_QUARTERLY_ASSET_TURNOVER = 3.0;

    /** Cyclical shift coefficient for variable margins based on output gap. */
    public const CYCLICAL_MARGIN_SHIFT_COEFFICIENT = 0.15;

    /** Coefficient for margin volatility relative to baseline stock volatility. */
    public const MARGIN_VOLATILITY_COEFFICIENT = 0.15;

    /** Failsafe max expected EBIT loss relative to structural revenue. */
    public const MAX_EBIT_LOSS_RATIO = 0.50;

    /**
     * Constructor.
     *
     * @param MarketEventPublisher $marketEvent Publisher for all market events, news headlines, and shocks.
     * @param MathUtility $mathUtility Utility for advanced mathematical operations (e.g., generating standard normal distribution).
     */
    public function __construct(
        private \Doctrine\ORM\EntityManagerInterface $entityManager,
        private MarketEventPublisher $marketEvent,
        private CapitalAllocationEngine $capitalAllocationEngine,
        private DebtEngine $debtEngine,
        private MathUtility $mathUtility,
        private CorporateMetrics $corporateMetrics,
        private NarrativeEngine $narrativeEngine,
        private MarketConsensusEngine $marketConsensusEngine
    ) {}

    public function calculate(Stock $stock, \App\DTO\MacroStateDTO $macroState, int $tickCount = 0, int $ticksPerYear = 252): ?array
    {
        if (!$this->checkReportingEligibility($stock, $tickCount, $ticksPerYear)) {
            return null;
        }

        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        $isFinancial = \App\Data\Sectors::isFinancial($businessModel);
        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);

        $ctx = new EarningsSimulationContext(
            $stock,
            $macroState,
            $strategy,
            $businessModel,
            $isFinancial,
            0.25
        );

        $this->initializeContext($ctx);
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
        $archetypeStrategy = \App\Data\CeoArchetypes::getStrategy($stock->getCeoArchetype());

        $annualTurnover = max(0.01, $ctx->baselineRoic) / ($ctx->stableMargin * (1.0 - $ctx->corporateTaxRate));
        $assetTurnover = min(self::MAX_QUARTERLY_ASSET_TURNOVER, $annualTurnover / 4.0);

        $macroPhysics = $strategy->getMacroPhysics($stock, $macroState);
        $macroDemandShift = $macroPhysics['macro_demand_shift'];
        $pricingPowerMultiplier = $macroPhysics['pricing_power_multiplier'];

        $revenueVol = $ctx->baselineVol * 0.25;
        $revenueVol = $archetypeStrategy->modifyIdiosyncraticVol($revenueVol);
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
        $ctx->structuralRevenue = max(1.0, abs($ctx->investedCapital) * $assetTurnover * $pricingPowerMultiplier);
        $ctx->expectedRevenue = $ctx->structuralRevenue * $ctx->capacityUtilization;

        $fixedCostRatio = (float) $stock->getFixedCostRatio();
        $fixedCostRatio = $archetypeStrategy->modifyFixedCostRatio($fixedCostRatio);
        $structuralCosts = $ctx->structuralRevenue * (1.0 - $ctx->stableMargin);
        $ctx->fixedCosts = $structuralCosts * $fixedCostRatio;

        $structuralVariableCosts = $structuralCosts - $ctx->fixedCosts;
        $ctx->baselineVariableMargin = $structuralVariableCosts / $ctx->structuralRevenue;
    }

    private function processVariableMargins(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        $strategy = $ctx->strategy;
        $archetypeStrategy = \App\Data\CeoArchetypes::getStrategy($stock->getCeoArchetype());

        $kappa = $strategy->getMarginReversionSpeed();
        $outputGap = $ctx->macroState->outputGapEma;
        $beta = (float) $stock->getBeta();

        $evaluationCapital = $ctx->isFinancial ? (float) $stock->getTotalEquity() : $ctx->investedCapital;
        $saturationCostPenalty = $this->corporateMetrics->calculateMarketSaturationPenalty($stock, $evaluationCapital, $ctx->macroState);

        $cyclicalMarginShift = $outputGap * $beta * self::CYCLICAL_MARGIN_SHIFT_COEFFICIENT;
        $dynamicVariableTheta = min(0.99, max(0.01, $ctx->baselineVariableMargin + $saturationCostPenalty - $cyclicalMarginShift));
        $dynamicVariableTheta = $archetypeStrategy->modifyVariableMarginTheta($dynamicVariableTheta);

        $z2 = $this->mathUtility->generateStandardNormal();
        $marginVol = $ctx->baselineVol * self::MARGIN_VOLATILITY_COEFFICIENT;

        if ($stock->getStructuralVariableMargin() !== null) {
            $currentVariableMargin = max(0.01, min(0.99, (float) $stock->getStructuralVariableMargin()));
        } else {
            $fixedCostRatio = (float) $stock->getFixedCostRatio();
            $fixedCostRatio = $archetypeStrategy->modifyFixedCostRatio($fixedCostRatio);
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

        $coverage = $ctx->strategy->getCoverageProfile();
        $consensus = $this->marketConsensusEngine->generateConsensus($actuals, $coverage, $ctx->expectedRevenue, $this->mathUtility, $ctx->stock);
        $ctx->analystExpectedRevenue = $consensus->analystExpectedRevenue;
        $ctx->analystExpectedVariableCosts = $consensus->analystExpectedVariableCosts;

        $expectedEbit = $ctx->analystExpectedRevenue - $ctx->fixedCosts - $ctx->analystExpectedVariableCosts;
        $ctx->expectedEbit = max(-$ctx->structuralRevenue * self::MAX_EBIT_LOSS_RATIO, $expectedEbit);

        $ctx->ebit = $ctx->actualRevenue - $ctx->fixedCosts - $ctx->actualVariableCosts;

        $ctx->primaryShockZ = $actuals->primaryShockZ;
        $ctx->eventType = $actuals->eventType;
        $ctx->eventContext = $actuals->eventContext;
    }

    private function calculateInterestAndDepreciation(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        
        $expectedOperatingMargin = $ctx->expectedEbit / max(1.0, $ctx->expectedRevenue);
        $expectedDebtMetrics = $this->debtEngine->calculateInterestExpense($stock, $ctx->macroState, false, $ctx->expectedRevenue * 4.0, $expectedOperatingMargin);
        $ctx->expectedInterestExpense = $expectedDebtMetrics->interestExpense / 4.0;

        $ctx->previousQuarterlyRevenue = (float) $stock->getTotalRevenue() / 4.0;
        $stock->setTotalRevenue((string) ($ctx->actualRevenue * 4.0));

        $ctx->trueOperatingMargin = $ctx->ebit / max(1.0, $ctx->actualRevenue);
        $ctx->debtMetrics = $this->debtEngine->calculateInterestExpense($stock, $ctx->macroState, true, $ctx->actualRevenue * 4.0, $ctx->trueOperatingMargin);

        $annualInterestExpense = $ctx->debtMetrics->interestExpense;
        $ctx->quarterlyInterestExpense = $annualInterestExpense / 4.0;
        // Note: we can't mutate debtMetrics since it's readonly. The DB will store $ctx->quarterlyInterestExpense.

        $stock->setHistoricalFixedRate((string) $ctx->debtMetrics->historicalFixedRate);

        $annualInterestIncome = $ctx->strategy->calculateInterestIncome($stock, $ctx->macroState, $this->mathUtility);
        $ctx->quarterlyInterestIncome = $annualInterestIncome / 4.0;

        $industry = $stock->getIndustry() ?: 'General';
        $customDepreciation = (float) $stock->getDepreciationRate();
        $baseDepreciationRate = $customDepreciation > 0.0 ? $customDepreciation : $this->corporateMetrics->getIndustryDepreciationRate($industry);

        $capacityStrain = max(0.0, $ctx->capacityUtilization - self::CAPACITY_STRAIN_THRESHOLD);
        $wearAndTearMultiplier = 1.0 + ($capacityStrain * self::WEAR_AND_TEAR_STRAIN_MULTIPLIER);

        $annualDepreciation = $ctx->investedCapital * ($baseDepreciationRate * $wearAndTearMultiplier);
        $ctx->quarterlyDepreciation = $annualDepreciation / 4.0;
    }

    private function reconcileTaxesAndNetIncome(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        $expectedEbt = $ctx->expectedEbit - $ctx->expectedInterestExpense + $ctx->quarterlyInterestIncome;
        $actualEbt = $ctx->ebit - $ctx->quarterlyInterestExpense + $ctx->quarterlyInterestIncome;

        $nol = (float) $stock->getNetOperatingLoss();

        if ($actualEbt > 0 && $nol > 0) {
            $shielded = min($actualEbt, $nol);
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
            $expectedShielded = min($expectedEbt, $nol);
            $expectedTaxable = $expectedEbt - $expectedShielded;
            $ctx->expectedQuarterlyNetIncome = $expectedEbt - ($expectedTaxable * $ctx->corporateTaxRate);
        } elseif ($expectedEbt < 0) {
            $ctx->expectedQuarterlyNetIncome = $expectedEbt;
        } else {
            $ctx->expectedQuarterlyNetIncome = $expectedEbt * (1.0 - $ctx->corporateTaxRate);
        }

        $ctx->reportedExpectedNetIncome = $ctx->expectedQuarterlyNetIncome;
        $ctx->reportedActualNetIncome = $ctx->actualQuarterlyNetIncome;

        if ($ctx->businessModel === 'reit') {
            $ctx->reportedExpectedNetIncome += $ctx->quarterlyDepreciation;
            $ctx->reportedActualNetIncome += $ctx->quarterlyDepreciation;
        }

        $ctx->health = $this->debtEngine->analyzeDebtHealth($stock, $ctx->macroState, $ctx->actualRevenue * 4.0, $ctx->trueOperatingMargin);
        $waccBaseline = $ctx->isFinancial ? ($ctx->health->costOfEquity ?? 0.10) : ($ctx->health->wacc ?? 0.08);

        $ctx->truePostTaxReturn = $ctx->strategy->updateDynamicRoic($stock, $ctx->actualQuarterlyNetIncome, $ctx->investedCapital, $ctx->ebit, $ctx->corporateTaxRate, $waccBaseline);
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

        $epsSurprisePct = abs($ctx->expectedQuarterlyEps) > 0.01
            ? $ctx->surpriseAmountQuarterly / abs($ctx->expectedQuarterlyEps)
            : ($ctx->surpriseAmountQuarterly > 0 ? 0.10 : ($ctx->surpriseAmountQuarterly < 0 ? -0.10 : 0.0));

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

            $physicalCapital = $ctx->isFinancial ? (float) $stock->getTotalEquity() : $stock->getInvestedCapital();
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
        $reportedOrganicCapex = $ctx->isFinancial ? 0.0 : $organicCapex;
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
        $this->applyVolatilityShock($stock, $ctx->primaryShockZ, $ctx->baselineVol);

        $currentPrice = (float) $stock->getPrice();

        if ($ctx->actualAnnualEpsRaw > 0) {
            $currentPE = $currentPrice / $ctx->actualAnnualEpsRaw;
        } else {
            $salesPerShare = $ctx->sharesOutstanding > 0 ? ($ctx->actualRevenue * 4.0) / $ctx->sharesOutstanding : 1.0;
            $priceToSales = $salesPerShare > 0 ? $currentPrice / $salesPerShare : 1.0;
            $structuralAfterTaxMargin = max(0.01, (float) $stock->getOperatingMargin() * (1.0 - $ctx->corporateTaxRate));
            $currentPE = $priceToSales * (1.0 / $structuralAfterTaxMargin);
        }

        $valuationPremium = max(0.5, min(3.0, $currentPE / FinancialConstants::BASELINE_MARKET_PE));
        $beta = (float) $stock->getBeta();

        if ($ctx->surprisePct < 0) {
            $priceGapPct = $ctx->surprisePct * FinancialConstants::PRICE_GAP_DAMPENING * sqrt($valuationPremium) * max(0.8, $beta);
        } else {
            $priceGapPct = $ctx->surprisePct * FinancialConstants::PRICE_GAP_DAMPENING * (1.0 / sqrt($valuationPremium));
        }

        $ctx->priceGapPct = max(-FinancialConstants::MAX_PRICE_GAP, min(FinancialConstants::MAX_PRICE_GAP, $priceGapPct));
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
        $equity = (float) $stock->getTotalEquity();

        if ($ctx->isFinancial) {
            $costOfEquity = $ctx->health->costOfEquity ?? 0.10;
            $smoothedReturn = (float) $stock->getRoeTtm();
            if ($smoothedReturn === 0.0) {
                $smoothedReturn = $ctx->truePostTaxReturn;
            }
            $ctx->annualEconomicProfit = $equity * ($smoothedReturn - $costOfEquity);
            $ctx->wacc = $costOfEquity;
        } else {
            $ctx->wacc = $ctx->health->wacc ?? 0.08;
            $smoothedReturn = (float) $stock->getRoicTtm();
            if ($smoothedReturn === 0.0) {
                $smoothedReturn = $ctx->truePostTaxReturn;
            }
            $ctx->annualEconomicProfit = $ctx->investedCapital * ($smoothedReturn - $ctx->wacc);
        }

        $evaAbs = abs($ctx->annualEconomicProfit);
        $formattedEva = $evaAbs >= 1_000_000_000
            ? '$' . number_format($evaAbs / 1_000_000_000, 2) . 'B'
            : '$' . number_format($evaAbs / 1_000_000, 2) . 'M';

        $evaString = $ctx->annualEconomicProfit >= 0 ? "+{$formattedEva} EVA" : "-{$formattedEva} EVA";

        $formattedEps = $ctx->actualQuarterlyEps < 0 ? '-$' . number_format(abs($ctx->actualQuarterlyEps), 2) : '$' . number_format($ctx->actualQuarterlyEps, 2);
        $formattedSurprise = '$' . number_format(abs($ctx->surpriseAmountQuarterly), 2);

        if ($ctx->surpriseAmountQuarterly > 0.0) {
            $description = "Q-Earnings: {$formattedEps} (Beat expectations by {$formattedSurprise} | {$evaString}).";
        } elseif ($ctx->surpriseAmountQuarterly < 0.0) {
            $description = "Q-Earnings: {$formattedEps} (Missed expectations by {$formattedSurprise} | {$evaString}).";
        } else {
            $description = "Q-Earnings: {$formattedEps} (Met expectations exactly | {$evaString}).";
        }

        $description .= $ctx->corporateActionDescriptions;

        $earningsEvent = $this->marketEvent->publish($stock, 'EARNINGS', $description, $ctx->totalShockPct * 100);

        $this->buildCorporateReport(
            $stock,
            $ctx->actualRevenue,
            $ctx->reportedActualNetIncome,
            $ctx->trueOperatingMargin,
            $ctx->debtMetrics,
            $ctx->quarterlyInterestIncome,
            $ctx->totalReportedCapex,
            $ctx->trueQuarterlyFcf,
            $ctx->truePostTaxReturn,
            $ctx->wacc,
            $ctx->annualEconomicProfit,
            $ctx->allocation,
            $ctx->health,
            $ctx->businessModel
        );

        return [$earningsEvent];
    }

    private function applyVolatilityShock(Stock $stock, float $revenueZ, float $baselineVol): void
    {
        $currentVol = (float) $stock->getCurrentVolatility();
        $zScore = abs($revenueZ);

        if ($zScore > FinancialConstants::SURPRISE_Z_SCORE_THRESHOLD) {
            $shockFactor = $revenueZ < 0
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

    private function buildCorporateReport(
        Stock $stock,
        float $revenue,
        float $netIncome,
        float $operatingMargin,
        \App\DTO\DebtMetricsDTO $debtMetrics,
        float $interestIncome,
        float $capex,
        float $freeCashFlow,
        float $roic,
        float $wacc,
        float $eva,
        array $allocation,
        \App\DTO\DebtHealthDTO $health,
        string $businessModel
    ): void {
        $report = new \App\Entity\CorporateReport();
        $report->setStock($stock);
        $report->setRecordedAt(new \DateTime());

        $report->setRevenue((string) $revenue);
        $report->setNetIncome((string) $netIncome);
        $report->setOperatingMargin((string) $operatingMargin);

        $report->setInterestExpense((string) ($debtMetrics->interestExpense / 4.0)); // Quarterly report
        $report->setInterestIncome((string) $interestIncome);
        $report->setBlendedRate((string) $debtMetrics->blendedRate);
        $report->setDynamicSpread((string) $debtMetrics->dynamicSpread);

        $report->setCapitalExpenditures((string) $capex);
        $report->setFreeCashFlow((string) $freeCashFlow);
        $report->setEquity($stock->getTotalEquity());
        $report->setTotalDebt($stock->getTotalDebt());
        $report->setTreasury($stock->getCorporateTreasury());

        $report->setRoic((string) $roic);
        $report->setShares((string) $stock->getSharesOutstanding());
        $report->setWacc((string) $wacc);
        $report->setEva((string) $eva);
        $report->setDividendPaid((string) $allocation['total_paid']);
        $report->setStockBuybacks((string) $allocation['total_cash_spent']);
        $report->setCashYield((string) $health->cashYield);
        $report->setDepositApy(isset($allocation['bank_apy']) ? (string) $allocation['bank_apy'] : null);

        $finalEquity = (float) $stock->getTotalEquity();
        $finalTotalDebt = (float) $stock->getTotalDebt();

        $roe = $finalEquity > 0 ? ($netIncome / $finalEquity) * 4.0 : 0.0;
        $report->setReturnOnEquity((string) $roe);
        $report->setCostOfEquity((string) ($health->costOfEquity ?? 0.10));

        $capitalRatio = ($finalEquity + $finalTotalDebt) > 0 ? ($finalEquity / ($finalEquity + $finalTotalDebt)) : 1.0;
        $report->setCapitalRatio((string) $capitalRatio);

        if (\App\Data\Sectors::isFinancial($businessModel) || (float) $stock->getCustomerDeposits() > 0) {
            $customerDeposits = (float) $stock->getCustomerDeposits();
            $depositRatio = $finalTotalDebt > 0 ? ($customerDeposits / $finalTotalDebt) : 0.0;
            $report->setCustomerDepositRatio((string) $depositRatio);
        }

        $this->entityManager->persist($report);
    }
}
