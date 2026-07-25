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

/**
 * Handles the simulation of quarterly earnings reports.
 * Models revenue, operating leverage, analyst consensus, and corporate saturation.
 */
class EarningsEngine
{
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

    /**
     * Calculates and processes a quarterly earnings report for a given stock.
     *
     * This method simulates the outcome of an earnings report based on a deterministic
     * schedule within the simulation's "Earnings Season". If it is the stock's turn to report, 
     * it calculates expected vs. actual earnings per share (EPS), factoring in economic cycles 
     * and statistical drift. It also adjusts the stock's volatility based on the statistical rarity 
     * (Z-Score) of the revenue shift (e.g., punishing or rewarding surprise reports).
     *
     * @param Stock $stock The stock entity to process earnings for.
     * @param \App\DTO\MacroStateDTO $macroState The current state of the macroeconomic cycle.
     * @param int $tickCount The current simulation tick, used to determine if it is earnings season.
     * @param int $ticksPerYear The total number of ticks in a simulated year.
     * @return array<string, mixed>|null  Returns the generated market event array if an earnings report occurred, otherwise null.
     */
    public function calculate(Stock $stock, \App\DTO\MacroStateDTO $macroState, int $tickCount = 0, int $ticksPerYear = 252): ?array
    {

        $ticksPerQuarter = (int) ($ticksPerYear / 4);

        // Define the season length
        $ticksPerSeason = (int) ($ticksPerQuarter * 1.0);

        // Where are we currently within the 3-month quarter?
        $currentQuarterTick = $tickCount % $ticksPerQuarter;

        // Are we outside the Earnings Season?
        if ($currentQuarterTick > $ticksPerSeason) {
            return null;
        }

        //  Assign this stock a permanent, deterministic reporting tick.
        $reportingTick = abs(crc32($stock->getTicker())) % max(1, $ticksPerSeason);

        // Is it this specific stock's exact turn to report
        if ($currentQuarterTick !== $reportingTick) {
            return null;
        }

        $baselineVol = (float) $stock->getVolatility();
        $sharesOutstanding = (float) $stock->getSharesOutstanding();

        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        $isFinancial = \App\Data\Sectors::isFinancial($businessModel);

        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
        $archetypeStrategy = \App\Data\CeoArchetypes::getStrategy($stock->getCeoArchetype());

        // STRUCTURAL COST BASE (Sticky)
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());

        $targetMetrics = $strategy->getTargetMetrics($stock, $macroState, $this->mathUtility);
        $investedCapital = $targetMetrics['invested_capital'];
        $baselineRoic = $targetMetrics['baseline_roic'];



        $macroTaxRate = $macroState->corporateTaxRate;
        $corporateTaxRate = $strategy->getEffectiveTaxRate($macroTaxRate);

        // STOCHASTIC FUNDAMENTAL PROCESSES (Paradigm 2: The Bottom-Up Approach)
        $dt = 0.25; // 1 Quarter

        // DuPont Analysis: Asset Turnover = ROIC / Margin.
        // Because Margin is pre-tax (EBIT) and ROIC is post-tax (NOPAT), we must adjust for taxes.
        // We strictly cap Asset Turnover to prevent revenue hyperinflation if margins compress.
        // The baselineRoic is Annualized, so we divide by 4.0 to get the Quarterly Asset Turnover.
        $annualTurnover = max(0.01, $baselineRoic) / ($stableMargin * (1.0 - $corporateTaxRate));
        $assetTurnover = min(3.0, $annualTurnover / 4.0); // Max 3.0x quarterly turnover (12x annually)

        // 1. CAPACITY UTILIZATION PROCESS (Bottom-Up Physical Scaling)
        // Instead of projecting historical revenue forward into the void via Geometric Brownian Motion,
        // we calculate how much of the company's literal physical infrastructure (Invested Capital)
        // is actively being utilized this quarter, allowing CapEx and M&A to instantly scale revenue.

        $macroPhysics = $strategy->getMacroPhysics($stock, $macroState);
        $macroDemandShift = $macroPhysics['macro_demand_shift'];
        $pricingPowerMultiplier = $macroPhysics['pricing_power_multiplier'];

        $revenueVol = $baselineVol * 0.25;
        $revenueVol = $archetypeStrategy->modifyIdiosyncraticVol($revenueVol);
        $z1 = $this->mathUtility->generateStandardNormal();

        // JUMP DIFFUSION (Fundamental Scale)
        // The database parameters are calibrated for stock PRICE jumps (which reflect forward-looking market panic/euphoria).
        $priceJumpIntensity = (float) ($stock->getJumpIntensity() ?? 2.00);
        $priceJumpMean = (float) ($stock->getJumpMean() ?? -0.05);
        $priceJumpVol = (float) ($stock->getJumpVol() ?? 0.10);

        $jumpIntensity = $priceJumpIntensity * FinancialConstants::FUNDAMENTAL_JUMP_INTENSITY_SCALE;
        $jumpMean = $priceJumpMean * FinancialConstants::FUNDAMENTAL_JUMP_MEAN_SCALE;
        $jumpVol = $priceJumpVol * FinancialConstants::FUNDAMENTAL_JUMP_VOL_SCALE;

        $jumpData = $this->mathUtility->calculateJumpDiffusion($jumpIntensity, $jumpMean, $jumpVol, $dt);
        $jumpMagnitude = $jumpData['exponent'] ?? 0.0;

        // The idiosyncratic demand shock specific to this company's products
        $idiosyncraticDemandShock = $revenueVol * sqrt($dt) * $z1;

        $secularGrowthRate = $strategy->getSecularGrowthRate($stock);
        $secularDrift = $secularGrowthRate * $dt;

        // Total Capacity Utilization (floored at 10% to prevent negative revenue on dead companies)
        $capacityUtilization = max(0.10, 1.0 + $secularDrift + $macroDemandShift + $idiosyncraticDemandShock + $jumpMagnitude);

        // STRUCTURAL BASELINE (100% Capacity)
        $structuralRevenue = max(1.0, abs($investedCapital) * $assetTurnover * $pricingPowerMultiplier);

        // BOTTOM-UP REVENUE GENERATION:
        $expectedRevenue = $structuralRevenue * $capacityUtilization;

        // TRUE ORGANIC OPERATING LEVERAGE
        // Fixed costs are locked to the physical structure of the firm, unaffected by short-term demand shocks.
        $fixedCostRatio = (float) $stock->getFixedCostRatio();
        $fixedCostRatio = $archetypeStrategy->modifyFixedCostRatio($fixedCostRatio);
        $structuralCosts = $structuralRevenue * (1.0 - $stableMargin);
        $fixedCosts = $structuralCosts * $fixedCostRatio;

        // The Baseline Variable Margin (Variable Costs / Revenue at 100% capacity)
        $structuralVariableCosts = $structuralCosts - $fixedCosts;
        $baselineVariableMargin = $structuralVariableCosts / $structuralRevenue;

        // 2. VARIABLE MARGIN PROCESS (Cox-Ingersoll-Ross)
        $kappa = $strategy->getMarginReversionSpeed();


        $outputGap = $macroState->outputGapEma;
        $beta = (float) $stock->getBeta();

        // The Bloat Penalty (Diseconomies of Scale): As a company saturates its market, administrative friction increases costs.
        $evaluationCapital = $isFinancial ? (float)$stock->getTotalEquity() : $investedCapital;
        $saturationCostPenalty = $this->corporateMetrics->calculateMarketSaturationPenalty($stock, $evaluationCapital, $macroState);

        $cyclicalMarginShift = $outputGap * $beta * 0.15;
        $dynamicVariableTheta = min(0.99, max(0.01, $baselineVariableMargin + $saturationCostPenalty - $cyclicalMarginShift));
        $dynamicVariableTheta = $archetypeStrategy->modifyVariableMarginTheta($dynamicVariableTheta);

        $z2 = $this->mathUtility->generateStandardNormal();
        $marginVol = $baselineVol * 0.15;

        // Use persisted structural CIR variable margin if available, otherwise initialize directly from structural baseline
        if ($stock->getStructuralVariableMargin() !== null) {
            $currentVariableMargin = max(0.01, min(0.99, (float) $stock->getStructuralVariableMargin()));
        } else {
            $currentVariableMargin = max(0.01, min(0.99, (1.0 - $stableMargin) * (1.0 - $fixedCostRatio)));
        }

        // Drift the variable efficiency using CIR
        $realizedVariableMargin = $this->mathUtility->calculateCIR($currentVariableMargin, $kappa, $dynamicVariableTheta, $marginVol, $dt, $z2);
        $realizedVariableMargin = min(0.99, max(0.01, $realizedVariableMargin));

        // Persist the pre-shock structural variable margin before transient sector physics are applied
        $stock->setStructuralVariableMargin($realizedVariableMargin);

        // APPLY THE IDIOSYNCRATIC Z-SCORE SHOCK (Physical Bottom-Up Outcomes)
        $actuals = $strategy->computeActualFinancials($stock, $expectedRevenue, $realizedVariableMargin, $fixedCosts, $baselineVol, $macroState, $this->mathUtility);
        $actualRevenue = $actuals->actualRevenue;
        $actualVariableCosts = $actuals->actualVariableCosts;

        // ANALYST VISIBILITY (FORWARD GUIDANCE)
        // Decoupled consensus generation via MarketConsensusEngine and sector coverage profile
        $coverage = $strategy->getCoverageProfile();
        $consensus = $this->marketConsensusEngine->generateConsensus($actuals, $coverage, $expectedRevenue, $this->mathUtility, $stock);
        $analystExpectedRevenue = $consensus->analystExpectedRevenue;
        $analystExpectedVariableCosts = $consensus->analystExpectedVariableCosts;

        // Calculate Expected EBIT natively using the Analyst's updated forward guidance
        $expectedEbit = $analystExpectedRevenue - $fixedCosts - $analystExpectedVariableCosts;
        // Failsafe: Prevent massive fixed costs from generating infinite negative EBIT
        $expectedEbit = max(-$structuralRevenue * 0.50, $expectedEbit);

        // Recalculate EBIT (If demand collapsed, they still paid the variable costs for unsold goods, causing a massive loss!)
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        $primaryShockZ = $actuals->primaryShockZ;
        $eventType = $actuals->eventType;
        $customEventLore = $eventType !== null ? $this->narrativeEngine->generateLore($eventType, $actuals->eventContext) : null;

        // Calculate EXPECTED Interest Expense (Pre-Shock)
        $expectedOperatingMargin = $expectedEbit / max(1.0, $expectedRevenue);
        $expectedDebtMetrics = $this->debtEngine->calculateInterestExpense($stock, $macroState, false, $expectedRevenue * 4.0, $expectedOperatingMargin);
        $expectedInterestExpense = $expectedDebtMetrics['interest_expense'];

        // Calculate ACTUAL Interest Expense (Post-Shock, Advancing Maturity)
        $previousQuarterlyRevenue = (float) $stock->getTotalRevenue() / 4.0;
        $stock->setTotalRevenue((string) ($actualRevenue * 4.0));
        // The dynamic margin is passed directly to DebtEngine
        $trueOperatingMargin = $ebit / max(1.0, $actualRevenue);

        $debtMetrics = $this->debtEngine->calculateInterestExpense($stock, $macroState, true, $actualRevenue * 4.0, $trueOperatingMargin);

        // DebtEngine returns ANNUAL interest expense. We must divide by 4 for the quarterly simulation.
        $annualInterestExpense = $debtMetrics['interest_expense'];
        $quarterlyInterestExpense = $annualInterestExpense / 4.0;
        $debtMetrics['interest_expense'] = $quarterlyInterestExpense; // Save back for the DB report

        $expectedInterestExpense = $expectedDebtMetrics['interest_expense'] / 4.0;

        $stock->setHistoricalFixedRate((string) $debtMetrics['historical_fixed_rate']);

        // Interest Income (Annualized from Business Models)
        $annualInterestIncome = $strategy->calculateInterestIncome($stock, $macroState, $this->mathUtility);
        $quarterlyInterestIncome = $annualInterestIncome / 4.0;

        // CALCULATE PHYSICAL DEPRECIATION
        $customDepreciation = (float) $stock->getDepreciationRate();
        $baseDepreciationRate = $customDepreciation > 0.0 ? $customDepreciation : $this->corporateMetrics->getIndustryDepreciationRate($industry);

        // WEAR AND TEAR PHYSICS: Running assets past 100% capacity accelerates depreciation dynamically
        $capacityStrain = max(0.0, $capacityUtilization - 1.05);
        $wearAndTearMultiplier = 1.0 + ($capacityStrain * 1.5);

        $annualDepreciation = $investedCapital * ($baseDepreciationRate * $wearAndTearMultiplier);
        $quarterlyDepreciation = $annualDepreciation / 4.0;

        $expectedEbt = $expectedEbit - $expectedInterestExpense + $quarterlyInterestIncome;
        $actualEbt = $ebit - $quarterlyInterestExpense + $quarterlyInterestIncome;


        // NET OPERATING LOSS (NOL) CARRYFORWARD
        // Companies accumulate losses and use them to shield future profits from taxes.
        // This prevents the systematic penalization of cyclical companies (Commodities, Banks)
        // that oscillate between massive profits and massive losses.
        $nol = (float) $stock->getNetOperatingLoss();

        if ($actualEbt > 0 && $nol > 0) {
            $shielded = min($actualEbt, $nol);
            $taxableIncome = $actualEbt - $shielded;
            $stock->setNetOperatingLoss((string) ($nol - $shielded));
            $actualQuarterlyNetIncome = $actualEbt - ($taxableIncome * $corporateTaxRate);
        } elseif ($actualEbt < 0) {
            $stock->setNetOperatingLoss((string) ($nol + abs($actualEbt)));
            $actualQuarterlyNetIncome = $actualEbt;
        } else {
            $actualQuarterlyNetIncome = $actualEbt * (1.0 - $corporateTaxRate);
        }

        // Expected Net Income uses the same NOL-aware logic for accurate surprise calculation
        if ($expectedEbt > 0 && $nol > 0) {
            $expectedShielded = min($expectedEbt, $nol);
            $expectedTaxable = $expectedEbt - $expectedShielded;
            $expectedQuarterlyNetIncome = $expectedEbt - ($expectedTaxable * $corporateTaxRate);
        } elseif ($expectedEbt < 0) {
            $expectedQuarterlyNetIncome = $expectedEbt;
        } else {
            $expectedQuarterlyNetIncome = $expectedEbt * (1.0 - $corporateTaxRate);
        }

        $reportedExpectedNetIncome = $expectedQuarterlyNetIncome;
        $reportedActualNetIncome = $actualQuarterlyNetIncome;

        // Wall Street evaluates REITs on Funds From Operations (FFO) rather than GAAP Net Income.
        // We add back absolute depreciation to the reported earnings figures.
        if ($businessModel === 'reit') {
            $reportedExpectedNetIncome += $quarterlyDepreciation;
            $reportedActualNetIncome += $quarterlyDepreciation;
        }

        $health = $this->debtEngine->analyzeDebtHealth($stock, $macroState, $actualRevenue * 4.0, $trueOperatingMargin);
        $waccBaseline = $isFinancial ? ($health['cost_of_equity'] ?? 0.10) : ($health['wacc'] ?? 0.08);

        // UPDATE DYNAMIC ROIC AS AN OUTCOME
        $truePostTaxReturn = $strategy->updateDynamicRoic($stock, $actualQuarterlyNetIncome, $investedCapital, $ebit, $corporateTaxRate, $waccBaseline);

        // EPS relies on ANNUAL metrics. We must multiply the Quarterly Net Income by 4.0
        $expectedAnnualEps = ($reportedExpectedNetIncome * 4.0) / max(1.0, $sharesOutstanding);
        $actualAnnualEpsRaw = ($reportedActualNetIncome * 4.0) / max(1.0, $sharesOutstanding);

        // Smooth the EPS into a Trailing Twelve Months (TTM) metric to prevent wild P/E oscillations
        $oldEps = (float) $stock->getEarningsPerShare();
        $structuralEps = $oldEps == 0.0 ? $actualAnnualEpsRaw : $oldEps;
        $ttmEps = $this->mathUtility->calculateKalmanSmoothedEps(
            $structuralEps,
            $actualAnnualEpsRaw,
            $baselineVol,
            abs($macroState->outputGapEma)
        );

        // Save the newly calculated Annual EPS back to the database
        $stock->setEarningsPerShare((string) $ttmEps);

        // Calculate Market Expectations
        // Analysts update their models based on structural forward guidance. We do not drag this towards TTM EPS,
        // otherwise a massive one-off shock (like a hurricane) will artificially depress expectations for 4 straight quarters.
        $expectedAnnualEpsDrifted = $expectedAnnualEps;

        // Calculate Quarterly metrics for the UI and price gap logic
        $actualQuarterlyEps = $actualAnnualEpsRaw / 4.0;
        $expectedQuarterlyEps = $expectedAnnualEpsDrifted / 4.0;
        $surpriseAmountQuarterly = $actualQuarterlyEps - $expectedQuarterlyEps;

        $epsSurprisePct = abs($expectedQuarterlyEps) > 0.01
            ? $surpriseAmountQuarterly / abs($expectedQuarterlyEps)
            : ($surpriseAmountQuarterly > 0 ? 0.10 : ($surpriseAmountQuarterly < 0 ? -0.10 : 0.0));

        // Calculate Revenue Surprise (Top-Line)
        // Analysts use their updated forward guidance ($analystExpectedRevenue) to measure the surprise.
        $revenueSurprisePct = abs($analystExpectedRevenue) > 1.0
            ? ($actualRevenue - $analystExpectedRevenue) / abs($analystExpectedRevenue)
            : 0.0;

        // Sector-Sensitive Surprise Blend:
        // Growth / High-Margin companies are punished more on EPS misses.
        // Cyclical / Low-Margin companies are judged more on Top-Line (Revenue).
        $blendWeights = $strategy->getSurpriseBlendWeights();
        $epsWeight = $blendWeights['eps_weight'];
        $revWeight = $blendWeights['revenue_weight'];
        $surprisePct = ($revenueSurprisePct * $revWeight) + ($epsSurprisePct * $epsWeight);

        // VOLATILITY SHOCK
        $this->applyVolatilityShock($stock, $primaryShockZ, $baselineVol);

        // Calculate Free Cash Flow (Dividend Support). The inputs are Quarterly, so we must multiply by 4.0
        $fcfData = $this->calculateFreeCashFlowPerShare($actualQuarterlyNetIncome, $sharesOutstanding, $stock, $macroState, $quarterlyDepreciation, $isFinancial, $strategy, $actualRevenue, $previousQuarterlyRevenue);
        $annualFcfPerShare = $fcfData['fcf_per_share'] * 4.0;
        $actualAnnualCapEx = $fcfData['capex'] * 4.0;

        // Opportunity B: Maintenance CapEx Reinvestment Ratio vs Asset Depreciation Decay
        $reinvestmentRatio = $quarterlyDepreciation > 0 ? ($fcfData['capex'] / $quarterlyDepreciation) : 1.0;
        $strategy->applyAssetDepreciationDecay($stock, $reinvestmentRatio, 0.25);

        $currentPrice = (float) $stock->getPrice();

        if ($actualAnnualEpsRaw > 0) {
            $currentPE = $currentPrice / $actualAnnualEpsRaw;
        } else {
            // Fallback to Price-to-Sales (P/S) equivalent for unprofitable companies (annualized sales)
            $salesPerShare = $sharesOutstanding > 0 ? ($actualRevenue * 4.0) / $sharesOutstanding : 1.0;
            $priceToSales = $salesPerShare > 0 ? $currentPrice / $salesPerShare : 1.0;
            // Dynamic P/S equivalence: scales with structural after-tax operating margin
            $structuralAfterTaxMargin = max(0.01, (float) $stock->getOperatingMargin() * (1.0 - $corporateTaxRate));
            $currentPE = $priceToSales * (1.0 / $structuralAfterTaxMargin);
        }

        $priceGapPct = $this->calculatePriceGap($surprisePct, $currentPE, $beta);

        // ALLOCATE CAPITAL
        $allocation = $this->capitalAllocationEngine->allocateCapital(
            $stock,
            $actualAnnualEpsRaw,
            $fcfData['fcf_per_share'],
            $currentPrice,
            $sharesOutstanding,
            $macroState,
            $actualQuarterlyNetIncome // SECURITY FIX: Pass true GAAP Net Income to preserve Clean Surplus Accounting without double-counting depreciation
        );

        $stock->setSharesOutstanding((string) $allocation['new_shares']);

        // Subtract the Growth CapEx (Organic CapEx) spent by the CEO to find True FCF
        $organicCapex = $allocation['organic_capex'] ?? 0.0;

        // For all financial companies, balance sheet deployment (Cash -> Loans/Underwriting Float/Trading Assets/Fund Assets) is financial investment, not physical CapEx
        $isFinancial = \App\Data\Sectors::isFinancial($businessModel);
        $reportedOrganicCapex = $isFinancial ? 0.0 : $organicCapex;

        // Convert quarterly organic CapEx to an annualized per-share impact
        $annualizedOrganicCapex = $reportedOrganicCapex * 4.0;
        $organicCapexPerShare = $sharesOutstanding > 0 ? ($annualizedOrganicCapex / $sharesOutstanding) : 0.0;

        // True FCF accounts for BOTH Maintenance CapEx and Growth CapEx
        $trueAnnualFcfPerShare = $annualFcfPerShare - $organicCapexPerShare;
        $stock->setFreeCashFlowPerShare((string) $trueAnnualFcfPerShare);

        // Aggregate total shock from earnings and corporate actions
        $totalShockPct = $priceGapPct;
        $corporateActionDescriptions = "";

        if ($customEventLore) {
            $corporateActionDescriptions .= "\n• " . $customEventLore;
        }

        if (!empty($allocation['events'])) {
            foreach ($allocation['events'] as $subEvent) {
                if (isset($subEvent['event_type'])) {
                    $desc = $this->narrativeEngine->generateLore($subEvent['event_type'], $subEvent['context'] ?? []);
                } else {
                    $desc = $subEvent['description'] ?? '';
                }
                $corporateActionDescriptions .= "\n• " . $desc;
                $totalShockPct += ($subEvent['shock'] / 100.0);
            }
        }

        // APPLY THE GAP
        $currentPrice = (float) $stock->getPrice();

        // The Circuit Breaker (Limit Up / Limit Down)
        $totalShockPct = max(-FinancialConstants::MAX_QUARTERLY_PRICE_CIRCUIT_BREAKER, min(FinancialConstants::MAX_QUARTERLY_PRICE_CIRCUIT_BREAKER, $totalShockPct));

        // The Dividend Ex-Date Adjustment
        // A stock's price drops by the exact dividend amount, but market physics prevent it from going to absolute zero.
        // We floor it at $0.01 to prevent fractional penny infinite reverse-split loops.
        $exDivPrice = ($currentPrice * (1.0 + $totalShockPct)) - $allocation['dividend_paid'];
        $newPrice = max(0.01, $exDivPrice);

        // Precision Assignment
        $stock->setPrice(number_format($newPrice, 8, '.', ''));

        $formattedEps = $actualQuarterlyEps < 0 ? '-$' . number_format(abs($actualQuarterlyEps), 2) : '$' . number_format($actualQuarterlyEps, 2);
        $formattedSurprise = '$' . number_format(abs($surpriseAmountQuarterly), 2);

        // Calculate Economic Value Added (EVA)
        $equity = (float) $stock->getTotalEquity();

        if ($isFinancial) {
            // Financials create EVA when Return on Equity > Cost of Equity
            $costOfEquity = $health['cost_of_equity'] ?? 0.10;
            $smoothedReturn = (float) $stock->getRoeTtm();
            if ($smoothedReturn === 0.0) {
                $smoothedReturn = $truePostTaxReturn;
            }
            $annualEconomicProfit = $equity * ($smoothedReturn - $costOfEquity);
            $wacc = $costOfEquity; // Fallback for reporting purposes
        } else {
            // Normal companies create EVA when ROIC > WACC
            $wacc = $health['wacc'] ?? 0.08;
            $smoothedReturn = (float) $stock->getRoicTtm();
            if ($smoothedReturn === 0.0) {
                $smoothedReturn = $truePostTaxReturn;
            }
            $annualEconomicProfit = $investedCapital * ($smoothedReturn - $wacc);
        }

        $evaAbs = abs($annualEconomicProfit);
        $formattedEva = $evaAbs >= 1_000_000_000
            ? '$' . number_format($evaAbs / 1_000_000_000, 2) . 'B'
            : '$' . number_format($evaAbs / 1_000_000, 2) . 'M';

        $evaString = $annualEconomicProfit >= 0 ? "+{$formattedEva} EVA" : "-{$formattedEva} EVA";

        if ($surpriseAmountQuarterly > 0.0) {
            $description = "Q-Earnings: {$formattedEps} (Beat expectations by {$formattedSurprise} | {$evaString}).";
        } elseif ($surpriseAmountQuarterly < 0.0) {
            $description = "Q-Earnings: {$formattedEps} (Missed expectations by {$formattedSurprise} | {$evaString}).";
        } else {
            $description = "Q-Earnings: {$formattedEps} (Met expectations exactly | {$evaString}).";
        }

        $description .= $corporateActionDescriptions;

        // Create the main Earnings Event
        $earningsEvent = $this->marketEvent->publish($stock, 'EARNINGS', $description, $totalShockPct * 100);

        $totalReportedCapex = ($actualAnnualCapEx / 4.0) + $reportedOrganicCapex;
        $trueQuarterlyFcf = ($trueAnnualFcfPerShare * $sharesOutstanding) / 4.0;

        // Persist the comprehensive quarterly report
        $this->buildCorporateReport(
            $stock,
            $actualRevenue,
            $reportedActualNetIncome,
            $trueOperatingMargin,
            $debtMetrics,
            $quarterlyInterestIncome,
            $totalReportedCapex,
            $trueQuarterlyFcf,
            $truePostTaxReturn,
            $wacc,
            $annualEconomicProfit,
            $allocation,
            $health,
            $businessModel
        );

        return [$earningsEvent];
    }

    /**
     * Assembles and persists the CorporateReport database entity for charting.
     */
    private function buildCorporateReport(
        Stock $stock,
        float $revenue,
        float $netIncome,
        float $operatingMargin,
        array $debtMetrics,
        float $interestIncome,
        float $capex,
        float $freeCashFlow,
        float $roic,
        float $wacc,
        float $eva,
        array $allocation,
        array $health,
        string $businessModel
    ): void {
        $report = new \App\Entity\CorporateReport();
        $report->setStock($stock);
        $report->setRecordedAt(new \DateTime());

        // The Holy Trinity
        $report->setRevenue((string) $revenue);
        $report->setNetIncome((string) $netIncome);
        $report->setOperatingMargin((string) $operatingMargin);

        // Debt & Treasury Data
        $report->setInterestExpense((string) $debtMetrics['interest_expense']);
        $report->setInterestIncome((string) $interestIncome);
        $report->setBlendedRate((string) $debtMetrics['blended_rate']);
        $report->setDynamicSpread((string) $debtMetrics['dynamic_spread']);

        // Cash Flow & Balance Sheet
        $report->setCapitalExpenditures((string) $capex);
        $report->setFreeCashFlow((string) $freeCashFlow);
        $report->setEquity($stock->getTotalEquity());
        $report->setTotalDebt($stock->getTotalDebt());
        $report->setTreasury($stock->getCorporateTreasury());

        // Metrics
        $report->setRoic((string) $roic);
        $report->setShares((string) $stock->getSharesOutstanding());
        $report->setWacc((string) $wacc);
        $report->setEva((string) $eva);
        $report->setDividendPaid((string) $allocation['total_paid']);
        $report->setStockBuybacks((string) $allocation['total_cash_spent']);
        $report->setCashYield((string) $health['cash_yield']);
        $report->setDepositApy(isset($allocation['bank_apy']) ? (string) $allocation['bank_apy'] : null);

        // Leveraged/Banking specific metrics
        $finalEquity = (float) $stock->getTotalEquity();
        $finalTotalDebt = (float) $stock->getTotalDebt();

        $roe = $finalEquity > 0 ? ($netIncome / $finalEquity) * 4.0 : 0.0;
        $report->setReturnOnEquity((string) $roe);
        $report->setCostOfEquity((string) ($health['cost_of_equity'] ?? 0.10));

        $capitalRatio = ($finalEquity + $finalTotalDebt) > 0 ? ($finalEquity / ($finalEquity + $finalTotalDebt)) : 1.0;
        $report->setCapitalRatio((string) $capitalRatio);

        if (\App\Data\Sectors::isFinancial($businessModel) || (float) $stock->getCustomerDeposits() > 0) {
            $customerDeposits = (float) $stock->getCustomerDeposits();
            $depositRatio = $finalTotalDebt > 0 ? ($customerDeposits / $finalTotalDebt) : 0.0;
            $report->setCustomerDepositRatio((string) $depositRatio);
        }

        $this->entityManager->persist($report);
    }

    /**
     * Applies a volatility shock or cooling effect based on the statistical rarity of the earnings report.
     *
     * @param Stock $stock The stock entity to update.
     * @param float $revenueZ The Z-score (standard normal) representing the revenue shift.
     * @param float $baselineVol The baseline long-term volatility of the stock.
     */
    private function applyVolatilityShock(Stock $stock, float $revenueZ, float $baselineVol): void
    {
        $currentVol = (float) $stock->getCurrentVolatility();
        $zScore = abs($revenueZ); // How many standard deviations away from expectations

        if ($zScore > FinancialConstants::SURPRISE_Z_SCORE_THRESHOLD) {
            // THE LEVERAGE EFFECT (Black, 1976; Christie, 1982):
            // Negative earnings surprises spike volatility harder than positive ones.
            // Bad news increases financial leverage (equity drops → D/E rises → risk rises)
            // and investor uncertainty cascades asymmetrically (panic spreads faster than euphoria).
            $shockFactor = $revenueZ < 0
                ? FinancialConstants::VOLATILITY_SHOCK_FACTOR * FinancialConstants::NEGATIVE_SURPRISE_VOL_MULTIPLIER
                : FinancialConstants::VOLATILITY_SHOCK_FACTOR;

            $shockMultiplier = 1.0 + (($zScore - 1.0) * $shockFactor);
            $newVol = min($currentVol * $shockMultiplier, $baselineVol * FinancialConstants::MAX_VOLATILITY_MULTIPLIER);
            $stock->setCurrentVolatility((string) $newVol);
        } elseif ($zScore < FinancialConstants::BORING_Z_SCORE_THRESHOLD && $currentVol > $baselineVol) {
            // A boring, highly predictable quarter. Volatility cools off.
            $newVol = $currentVol - (($currentVol - $baselineVol) * FinancialConstants::VOLATILITY_COOLING_FACTOR);
            $stock->setCurrentVolatility((string) max($newVol, $baselineVol));
        }
    }

    /**
     * Calculates the dampened and capped price gap percentage based on the earnings surprise.
     *
     * Limits the immediate post-earnings price jump/drop to prevent the stock from
     * completely breaking the simulation on a single massive outlier report.
     *
     * @param float $surprisePct The raw percentage by which the company missed or beat expectations.
     * @param float $peRatio     The company's current P/E Ratio.
     * @param float $beta        The stock's risk baseline (Beta).
     * @return float The bounded percentage for the immediate price gap.
     */
    private function calculatePriceGap(float $surprisePct, float $peRatio, float $beta): float
    {
        // ASYMMETRIC VALUATION PHYSICS: 
        // Growth stocks (High PE) have "perfection priced in" and are punished brutally for misses.
        // Value stocks (Low PE) have lower expectations, taking smaller hits on misses but smaller pops on beats.
        $valuationPremium = max(0.5, min(3.0, $peRatio / FinancialConstants::BASELINE_MARKET_PE));

        if ($surprisePct < 0) {
            $priceGapPct = $surprisePct * FinancialConstants::PRICE_GAP_DAMPENING * sqrt($valuationPremium) * max(0.8, $beta);
        } else {
            // Dampen reward for high-fliers (it was already priced in)
            $priceGapPct = $surprisePct * FinancialConstants::PRICE_GAP_DAMPENING * (1.0 / sqrt($valuationPremium));
        }

        return max(-FinancialConstants::MAX_PRICE_GAP, min(FinancialConstants::MAX_PRICE_GAP, $priceGapPct));
    }

    /**
     * Converts accrual EPS into Free Cash Flow per Share based on Sector CapEx requirements.
     *
     * Factors in the specific Capital Expenditure (CapEx) requirements of the sector
     * and adjusts for macroeconomic cycles (e.g., companies invest more during booms).
     *
     * @param float $actualTotalNetIncome The total net income generated this quarter.
     * @param float $sharesOutstanding    The total shares currently outstanding.
     * @param Stock $stock                The stock entity.
     * @param MacroStateDTO $macroState           The current macroeconomic state.
     * @param float $absoluteDepreciation The absolute depreciation amount (non-cash expense).
     * @param bool  $isLeveraged          Whether the company is a leveraged financial institution.
     * @return array{fcf_per_share: float, capex: float}
     */
    private function calculateFreeCashFlowPerShare(
        float $actualTotalNetIncome,
        float $sharesOutstanding,
        Stock $stock,
        \App\DTO\MacroStateDTO $macroState,
        float $absoluteDepreciation,
        bool $isLeveraged,
        \App\Service\Model\BusinessModelInterface $strategy,
        float $actualRevenue,
        float $previousQuarterlyRevenue
    ): array {
        if ($sharesOutstanding <= 0) {
            return ['fcf_per_share' => 0.0, 'capex' => 0.0];
        }

        $capExRatio = (float) $stock->getCapexRatio();

        $outputGap = $macroState->outputGapEma;
        $capexCyclicality = $strategy->getCapexCyclicality();
        $cycleCapExModifier = max(0.50, min(1.50, 1.00 + ($outputGap * $capexCyclicality)));

        $physicalCapital = $isLeveraged ? (float) $stock->getTotalEquity() : $stock->getInvestedCapital();
        $baselineIncomeForCapEx = max($physicalCapital * 0.02, max(0.0, $actualTotalNetIncome));
        $actualCapEx = $baselineIncomeForCapEx * ($capExRatio * $cycleCapExModifier);

        // Opportunity A: Working Capital & Cash Conversion Cycle physics
        $workingCapitalIntensity = $strategy->getWorkingCapitalIntensity($stock);
        $deltaNwc = $workingCapitalIntensity * ($actualRevenue - $previousQuarterlyRevenue);

        // True FCF accounts for Net Income, non-cash Depreciation, Delta NWC, and CapEx
        $fcff = $actualTotalNetIncome + $absoluteDepreciation - $deltaNwc - $actualCapEx;

        return [
            'fcf_per_share' => $fcff / $sharesOutstanding,
            'capex' => $actualCapEx
        ];
    }
}
