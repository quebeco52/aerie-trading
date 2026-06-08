<?php

namespace App\Service\Corporate;

use App\Entity\Stock;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;

/**
 * Service responsible for managing the internal corporate balance sheet.
 * Handles CapEx, Debt Issuance, Deleveraging Sweeps, and Liquidity Crises.
 */
class TreasuryEngine
{
    public function __construct(
        private CorporateMetrics $corporateMetrics,
        private DebtEngine $debtEngine,
        private MathUtility $mathUtility
    ) {}

    /**
     * Updates the corporate balance sheet using Clean Surplus Accounting principles.
     * Handles retained earnings, total equity, and dynamically manages debt levels
     * (triggering emergency borrowing during liquidity crises or sweeping excess cash to pay down debt).
     */
    public function updateBalanceSheet(
        Stock $stock,
        float $quarterlyNetIncome,
        float $totalDividendsPaid,
        float $totalBuybackCash,
        float $operatingBase,
        float $nopat,
        float $newTreasury,
        array $macroState,
        array $health
    ): array {
        $events = [];
        $totalCashSpent = $totalDividendsPaid + $totalBuybackCash;

        // RETAINED EARNINGS
        $currentRetained = (float) $stock->getRetainedEarnings();
        $newRetained = $currentRetained + $quarterlyNetIncome - $totalDividendsPaid;
        $stock->setRetainedEarnings((string) $newRetained);

        // TOTAL EQUITY (Clean Surplus Accounting)
        $currentEquity = (float) $stock->getTotalEquity();
        $newEquity = $currentEquity + $quarterlyNetIncome - $totalCashSpent;
        $stock->setTotalEquity((string) $newEquity);

        // STATE MANAGER FOR MUTATIONS
        $state = [
            'treasury' => $newTreasury,
            'wholesaleDebt' => (float) $stock->getWholesaleDebt(),
            'customerDeposits' => (float) $stock->getCustomerDeposits(),
            'debtIssued' => 0.0,
            'organicCapex' => 0.0,
            'debtActionTaken' => false,
            'bank_apy' => null,
            'events' => []
        ];

        // SYSTEMIC M2 MONEY SUPPLY GROWTH
        $this->processPassiveLiabilityGrowth($stock, $macroState, $state);

        // DEBT MANAGEMENT (MACRO TOLERANCE)
        $this->processDebtExpansion($stock, $newEquity, $nopat, $macroState, $health, $state);

        //  ORGANIC BUSINESS EXPANSION (Internal CapEx)
        $this->processOrganicCapex($stock, $newEquity, $nopat, $operatingBase, $macroState, $health, $state);

        // THE DEBT TRAP (Liquidity Crisis)
        $this->processEmergencyBorrowing($stock, $operatingBase, $macroState, $health, $state);

        // ARBITRAGE PAYDOWN (Escape negative carry)
        $this->processArbitragePaydown($stock, $operatingBase, $health, $state);

        // THE DELEVERAGING SWEEP (Macro-Driven Cash Management)
        $this->processDeleveragingSweep($stock, $newEquity, $operatingBase, $health, $state);

        // SAVE FINAL TREASURY
        $stock->setCorporateTreasury((string) $state['treasury']);

        return [
            'events' => $state['events'], 
            'organic_capex' => $state['organicCapex'],
            'bank_apy' => $state['bank_apy']
        ];
    }

    private function processDebtExpansion(Stock $stock, float $newEquity, float $nopat, array $macroState, array $health, array &$state): void
    {
        $totalDebt = $state['wholesaleDebt'] + $state['customerDeposits'];
        $liveInvestedCapital = $this->corporateMetrics->calculateLiveInvestedCapital($newEquity, $totalDebt, $state['treasury']);

        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        $isFinancial = \App\Data\Sectors::isFinancial($businessModel);

        if ($isFinancial) {
            $trueReturn = (float) $stock->getCurrentRoe();
            $hurdleRate = $health['cost_of_equity'] ?? 0.10;
        } else {
            $trueReturn = (float) $stock->getCurrentRoic();
            $hurdleRate = $health['wacc'];
        }

        $evaluationCapital = $isFinancial ? $newEquity : $liveInvestedCapital;

        if ($trueReturn > $hurdleRate && $health['can_issue_debt']) {
            $newBorrowingRate = $health['raw_metrics']['current_market_rate'] ?? 0.05;

            if ($isFinancial) {
                $evalDebt = $state['wholesaleDebt'];
                $modelThresholds = \App\Data\Sectors::getModelThresholds($businessModel);
                $evalTolerance = $modelThresholds['wholesale_leverage_limit'] ?? $health['debt_tolerance'];
            } else {
                $evalDebt = $totalDebt;
                $evalTolerance = $health['debt_tolerance'];
            }
            
            $balanceSheetCapacity = max(0.0, ($newEquity * $evalTolerance) - $evalDebt);

            if ($isFinancial) {
                $incomeStatementCapacity = $balanceSheetCapacity; 
            } else {
                $ebit = $health['raw_metrics']['ebit'] ?? 0.0;
                $minimumIcr = 3.5; 
                $maxTolerableInterest = max(0.0, $ebit / $minimumIcr);
                $currentInterestExpense = $health['raw_metrics']['interest_expense'] ?? 0.0;
                $availableInterestCapacity = max(0.0, $maxTolerableInterest - $currentInterestExpense);
                $incomeStatementCapacity = $newBorrowingRate > 0 ? ($availableInterestCapacity / $newBorrowingRate) : 0.0;
            }

            $trueExpansionCapacity = min($incomeStatementCapacity, $balanceSheetCapacity);
            $trueExpansionCapacity = min($trueExpansionCapacity, $liveInvestedCapital * 0.15);

            if ($trueExpansionCapacity > 0) {
                $spreadMultiplier = min(1.0, max(0.0, ($trueReturn - $hurdleRate) * 10.0));

                if ($isFinancial) {
                    $bankSpreadMultiplier = min(1.0, max(0.0, ($trueReturn - $hurdleRate) * 20.0));
                    $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
                    $aggressionData = $strategy->getDebtExpansionAggressiveness($bankSpreadMultiplier);
                    $borrowProbability = $aggressionData['probability'];
                    $aggressiveness = $aggressionData['aggressiveness'];

                    if (in_array($businessModel, ['commercial_bank', 'credit_services'])) {
                        $depositRatio = $totalDebt > 0 ? ($state['customerDeposits'] / $totalDebt) : 0.0;
                        if ($depositRatio < 0.70) {
                            $depositConstraint = max(0.0, ($depositRatio - 0.40) / 0.30);
                            $borrowProbability *= $depositConstraint;
                            $aggressiveness *= $depositConstraint;
                        }
                    }
                } else {
                    $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
                    $aggressionData = $strategy->getDebtExpansionAggressiveness($spreadMultiplier);
                    $borrowProbability = $aggressionData['probability'];
                    $aggressiveness = $aggressionData['aggressiveness'];
                }

                $saturationPenalty = $this->corporateMetrics->calculateMarketSaturationPenalty($stock, $evaluationCapital, $macroState);
                $borrowProbability *= max(0.05, 1.0 - $saturationPenalty);

                if ((mt_rand() / mt_getrandmax()) < $borrowProbability) {
                    $newDebtIssued = $trueExpansionCapacity * $aggressiveness;
                    
                    // Issue the debt and calculate blended fixed rate
                    // Uses $newBorrowingRate which is the current Market Fixed Rate (Yield 5Y + Spread)
                    $this->debtEngine->issueDebt($stock, $newDebtIssued, $newBorrowingRate);

                    $state['wholesaleDebt'] = (float) $stock->getWholesaleDebt();
                    $state['treasury'] += $newDebtIssued;
                    $state['debtIssued'] = $newDebtIssued;
                    $state['debtActionTaken'] = true;

                    if ($newDebtIssued > 500_000_000.0) {
                        $amtB = number_format($newDebtIssued / 1_000_000_000, 2);
                        $state['events'][] = ['description' => "Issued \${$amtB}B in bonds for expansion.", 'shock' => 0.5];
                    }
                }
            }
        }
    }

    private function processOrganicCapex(Stock $stock, float $newEquity, float $nopat, float $operatingBase, array $macroState, array $health, array &$state): void
    {
        $totalDebt = $state['wholesaleDebt'] + $state['customerDeposits'];
        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        $isFinancial = \App\Data\Sectors::isFinancial($businessModel);
        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
        
        $targetCashReserves = $strategy->calculateTargetOperatingCash($operatingBase, $state['customerDeposits'], $state['wholesaleDebt']) * 1.20;
        $liveInvestedCapital = $this->corporateMetrics->calculateLiveInvestedCapital($newEquity, $totalDebt, $state['treasury']);
        
        $trueReturn = $isFinancial ? (float) $stock->getCurrentRoe() : (float) $stock->getCurrentRoic();
        $hurdleRate = $isFinancial ? ($health['cost_of_equity'] ?? 0.10) : $health['wacc'];
        $evaluationCapital = $isFinancial ? $newEquity : $liveInvestedCapital;

        $investmentProbability = min(0.95, max(0.10, 0.20 + ($trueReturn * 2.0)));
        $saturationPenalty = $this->corporateMetrics->calculateMarketSaturationPenalty($stock, $evaluationCapital, $macroState);
        $investmentProbability *= max(0.05, 1.0 - $saturationPenalty);

        if ($isFinancial) {
            $investmentProbability = 0.00;
        }
        

        $fundInvestmentOpportunity = (mt_rand() / mt_getrandmax()) < $investmentProbability;

        if (($trueReturn > $hurdleRate && $state['treasury'] > $targetCashReserves && !$health['wants_to_paydown_debt'] && $fundInvestmentOpportunity) || $state['debtActionTaken']) {
            $spreadMultiplier = min(1.0, max(0.0, ($trueReturn - $hurdleRate) * 10.0));
            $organicSpend = ($state['treasury'] - $targetCashReserves) * (0.02 + (0.13 * $spreadMultiplier));
            
            $expansionSpend = $strategy->calculateOrganicCapexSpend($organicSpend, $state['debtIssued']);
            $expansionSpend = min($expansionSpend, max(0.0, $state['treasury'] - $targetCashReserves));
            
            $maxGrowthSpeed = $isFinancial ? 0.08 : 0.05; 
            $expansionCapBasis = $isFinancial ? ($newEquity + $totalDebt) : $liveInvestedCapital;
            $expansionSpend = min($expansionSpend, $expansionCapBasis * $maxGrowthSpeed);

            if ($expansionSpend > 0) {
                $state['organicCapex'] = $expansionSpend;
                $state['treasury'] -= $expansionSpend;

                if ($expansionSpend > 1_000_000_000.0) {
                    $amtB = number_format($expansionSpend / 1_000_000_000, 2);
                    $actionText = match($businessModel) {
                        'commercial_bank', 'credit_services', 'shadow_bank' => 'loan book expansion',
                        'insurance' => 'underwriting infrastructure',
                        'brokerage' => 'platform expansion',
                        'asset_manager' => 'fund seeding and platform expansion',
                        'reit' => 'property acquisitions and development',
                        default => 'organic expansion'
                    };
                    $state['events'][] = ['description' => "Deployed \${$amtB}B in {$actionText}.", 'shock' => 0.5];
                }
            }
        }
    }

    private function processEmergencyBorrowing(Stock $stock, float $operatingBase, array $macroState, array $health, array &$state): void
    {
        $industry = $stock->getIndustry() ?: 'General';
        $strategy = \App\Data\Sectors::getBusinessModelStrategy(\App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none');
        $minOperatingCash = $strategy->calculateMinOperatingCash($operatingBase, $state['customerDeposits'], $state['wholesaleDebt']);

        if ($state['treasury'] < $minOperatingCash) {
            $cashShortfall = $minOperatingCash - $state['treasury'];
                
                // Emergency debt is highly punitive (+200 bps penalty) but still anchors to the 5Y corporate fixed rate
                $currentMarketRate = $health['raw_metrics']['current_market_rate'] ?? (($macroState['yield_5y_ema'] ?? 0.045) + (float) $stock->getCreditSpread());
                $costOfEmergencyDebt = $currentMarketRate + 0.02;
                
                $this->debtEngine->issueDebt($stock, $cashShortfall, $costOfEmergencyDebt);

                $state['wholesaleDebt'] = (float) $stock->getWholesaleDebt();
            $state['treasury'] = $minOperatingCash;
            $state['debtActionTaken'] = true;

            if ($cashShortfall > 10_000_000.0) {
                $state['events'][] = ['description' => "Forced to borrow \$" . number_format($cashShortfall / 1_000_000_000, 2) . "B at penalty rates due to cash shortfall.", 'shock' => -5.0];
            }
        }
    }

    private function processArbitragePaydown(Stock $stock, float $operatingBase, array $health, array &$state): void
    {
        $totalDebt = $state['wholesaleDebt'] + $state['customerDeposits'];
        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        $isFinancial = \App\Data\Sectors::isFinancial($businessModel);
        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
        $targetOperatingCash = $strategy->calculateTargetOperatingCash($operatingBase, $state['customerDeposits'], $state['wholesaleDebt']);

        if (!$state['debtActionTaken'] && $health['wants_to_paydown_debt'] && $state['wholesaleDebt'] > 0 && $state['treasury'] > $targetOperatingCash) {
            $distressThreshold = $isFinancial ? 1.05 : 2.0;
            $isLiquidityCrisis = $health['interest_coverage'] < 1.0;
            $isDistressed = $health['interest_coverage'] < $distressThreshold;
            $paydownProbability = $isLiquidityCrisis ? 1.0 : ($isDistressed ? 0.50 : 0.15);

            if ((mt_rand() / mt_getrandmax()) < $paydownProbability) {
                $sweepPercentage = $isLiquidityCrisis ? 0.50 : 0.10;
                $arbitragePaydown = ($state['treasury'] - $targetOperatingCash) * $sweepPercentage;
                $maxRetireableDebt = $state['wholesaleDebt'] * ($isLiquidityCrisis ? 0.15 : 0.05);
                $actualPaydown = min($arbitragePaydown, $state['wholesaleDebt'], $maxRetireableDebt);

                if ($actualPaydown > 0) {
                    $state['wholesaleDebt'] -= $actualPaydown;
                    $stock->setWholesaleDebt((string) $state['wholesaleDebt']);
                    $state['treasury'] -= $actualPaydown;
                    $state['debtActionTaken'] = true;

                    if ($actualPaydown > 500_000_000.0) {
                        $amtB = number_format($actualPaydown / 1_000_000_000, 2);
                        $reason = $isLiquidityCrisis ? "survive a liquidity crisis" : "reduce debt burden and escape negative carry";
                        $state['events'][] = [
                            'description' => "Paid down \${$amtB}B of debt to {$reason}.",
                            'shock' => 1.0
                        ];
                    }
                }
            }
        }
    }

    private function processDeleveragingSweep(Stock $stock, float $newEquity, float $operatingBase, array $health, array &$state): void
    {
        $totalDebt = $state['wholesaleDebt'] + $state['customerDeposits'];
        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        $isFinancial = \App\Data\Sectors::isFinancial($businessModel);
        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
        $targetOperatingCash = $strategy->calculateTargetOperatingCash($operatingBase, $state['customerDeposits'], $state['wholesaleDebt']);

        if (!$state['debtActionTaken'] && $state['wholesaleDebt'] > 0.0 && $state['treasury'] > $targetOperatingCash) {
            $excessCash = $state['treasury'] - $targetOperatingCash;
            $macroDebtTolerance = $health['debt_tolerance'];
            
            $evalDebt = $isFinancial ? $state['wholesaleDebt'] : $totalDebt;
            $modelThresholds = \App\Data\Sectors::getModelThresholds($businessModel);
            $evalLimit = $isFinancial ? ($modelThresholds['wholesale_leverage_limit'] ?? $macroDebtTolerance) : $macroDebtTolerance;

            $currentDebtRatio = $evalDebt / max(1.0, $newEquity);

            $baselineSpread = (float) $stock->getCreditSpread();
            $dynamicSpread = $health['raw_metrics']['dynamic_spread'] ?? $baselineSpread;
            $isJunkBondStatus = $dynamicSpread > ($baselineSpread + 0.0011);

            if ($currentDebtRatio > $evalLimit || $isJunkBondStatus) {
                $targetRatio = $isJunkBondStatus ? max(0.10, $evalLimit * 0.75) : max(0.10, $evalLimit - 0.05);

                $targetTotalDebt = $newEquity * $targetRatio;
                $debtToPayOff = min($excessCash, max(0.0, $evalDebt - $targetTotalDebt));
                $debtToPayOff = min($debtToPayOff, $state['wholesaleDebt']);

                if ($debtToPayOff > 0) {
                    $state['wholesaleDebt'] -= $debtToPayOff;
                    $stock->setWholesaleDebt((string) $state['wholesaleDebt']);
                    $state['treasury'] -= $debtToPayOff;

                    if ($debtToPayOff > 500_000_000.0) {
                        $amtB = number_format($debtToPayOff / 1_000_000_000, 2);
                        $state['events'][] = [
                            'description' => "Swept \${$amtB}B cash to aggressively deleverage.",
                            'shock' => 2.0
                        ];
                    }
                }
            }
        }
    }

    private function processPassiveLiabilityGrowth(Stock $stock, array $macroState, array &$state): void
    {
        $industry = $stock->getIndustry() ?: 'General';
        $strategy = \App\Data\Sectors::getBusinessModelStrategy(\App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none');
        $strategy->processPassiveLiabilityGrowth($stock, $macroState, $state, $this->mathUtility);
    }
}