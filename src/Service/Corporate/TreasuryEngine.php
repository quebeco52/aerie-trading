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
        array &$macroState,
        array $health,
        float $realEstateAppreciation = 0.0
    ): array {
        $events = [];
        $totalCashSpent = $totalDividendsPaid + $totalBuybackCash;

        // RETAINED EARNINGS
        $currentRetained = (float) $stock->getRetainedEarnings();
        $newRetained = $currentRetained + $quarterlyNetIncome - $totalDividendsPaid;
        $stock->setRetainedEarnings((string) $newRetained);

        // TOTAL EQUITY (Clean Surplus Accounting)
        $currentEquity = (float) $stock->getTotalEquity();
        $newEquity = $currentEquity + $quarterlyNetIncome + $realEstateAppreciation - $totalCashSpent;
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
            'failed_emergency_borrow' => false,
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

        // EQUITY ISSUANCE (Secondary Offerings / Death Spirals)
        $this->processEquityIssuance($stock, $operatingBase, $macroState, $health, $state, $totalBuybackCash);

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

    private function processDebtExpansion(Stock $stock, float $newEquity, float $nopat, array &$macroState, array $health, array &$state): void
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

        $isUnderLeveraged = $health['is_under_leveraged'] && !$health['is_severe_negative_carry'];

        if (($trueReturn > $hurdleRate || $isUnderLeveraged) && $health['can_issue_debt']) {
            $newBorrowingRate = $health['raw_metrics']['current_market_rate'] ?? 0.05;

            if ($isFinancial) {
                $modelThresholds = \App\Data\Sectors::getModelThresholds($businessModel);
                $wholesaleTolerance = $modelThresholds['wholesale_leverage_limit'] ?? $health['debt_tolerance'];

                $wholesaleCapacity = max(0.0, ($newEquity * $wholesaleTolerance) - $state['wholesaleDebt']);
                $totalCapacity = max(0.0, ($newEquity * $health['debt_tolerance']) - $totalDebt);

                $balanceSheetCapacity = min($wholesaleCapacity, $totalCapacity);
                $incomeStatementCapacity = $balanceSheetCapacity;
            } else {
                $evalDebt = $totalDebt;
                $evalTolerance = $health['debt_tolerance'];
                $balanceSheetCapacity = max(0.0, ($newEquity * $evalTolerance) - $evalDebt);

                $ebit = $health['raw_metrics']['ebit'] ?? 0.0;
                $depreciation = $health['raw_metrics']['depreciation'] ?? 0.0;
                
                // REITs use FFO (EBIT + Depreciation) to cover interest, as depreciation is non-cash.
                $operatingIncome = $businessModel === 'reit' ? ($ebit + $depreciation) : $ebit;
                
                // Highly stable businesses (like REITs and Utilities) can safely borrow at much lower ICR thresholds.
                $modelThresholds = \App\Data\Sectors::getModelThresholds($businessModel);
                $minimumIcr = ($modelThresholds['buyback_min_icr'] ?? 3.0) + 0.5;
                
                $maxTolerableInterest = max(0.0, $operatingIncome / $minimumIcr);
                $currentInterestExpense = $health['raw_metrics']['interest_expense'] ?? 0.0;
                $availableInterestCapacity = max(0.0, $maxTolerableInterest - $currentInterestExpense);
                $incomeStatementCapacity = $newBorrowingRate > 0 ? ($availableInterestCapacity / $newBorrowingRate) : 0.0;
            }

            $trueExpansionCapacity = min($incomeStatementCapacity, $balanceSheetCapacity);

            // Allow specific business models to adjust capacity (e.g., Banks spending cheap deposits first)
            // Subtract excess cash from the TOTAL balance sheet capacity first
            $operatingBase = $this->corporateMetrics->calculateOperatingBase((float) $stock->getTotalRevenue(), (float) $stock->getTotalEquity());
            $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
            $targetCashReserves = $strategy->calculateTargetOperatingCash($operatingBase, $state['customerDeposits'], $state['wholesaleDebt']) * 1.20;
            $excessCash = max(0.0, $state['treasury'] - $targetCashReserves);

            $trueExpansionCapacity = $strategy->getUnfundedExpansionCapacity($trueExpansionCapacity, $excessCash);

            // Max 25% of operations per quarter (Quarterly Flow Limit)
            $trueExpansionCapacity = min($trueExpansionCapacity, $liveInvestedCapital * 0.25);

            if ($trueExpansionCapacity > 0) {
                $spreadMultiplier = min(1.0, max(0.0, ($trueReturn - $hurdleRate) * 10.0));

                if ($isFinancial) {
                    $bankSpreadMultiplier = min(1.0, max(0.0, ($trueReturn - $hurdleRate) * 20.0));
                    $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
                    $aggressionData = $strategy->getDebtExpansionAggressiveness($bankSpreadMultiplier);
                    $borrowProbability = $aggressionData['probability'];
                    $aggressiveness = $aggressionData['aggressiveness'];

                    if (in_array($businessModel, ['commercial_bank', 'credit_services', 'clearing_house'])) {
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

                // CAPITAL STRUCTURE MAINTENANCE:
                // If a company is under-leveraged (Debt/Equity < 60% of tolerance), it aggressively issues debt to recapitalize
                // rather than just to capture new market share, so it ignores the market saturation penalty.

                if ($isUnderLeveraged) {
                    $aggressiveness = max($aggressiveness, 0.50);
                    $borrowProbability = max($borrowProbability, 0.90);
                } else {
                    $borrowProbability *= max(0.05, 1.0 - $saturationPenalty);
                }

                if ((mt_rand() / mt_getrandmax()) < $borrowProbability) {
                    $newDebtIssued = $trueExpansionCapacity * $aggressiveness;

                    // Issue the debt and calculate blended fixed rate
                    // Uses $newBorrowingRate which is the current Market Fixed Rate (Yield 5Y + Spread)
                    $this->debtEngine->issueDebt($stock, $newDebtIssued, $newBorrowingRate);

                    $state['wholesaleDebt'] = (float) $stock->getWholesaleDebt();
                    $state['treasury'] += $newDebtIssued;
                    $state['debtIssued'] = $newDebtIssued;
                    $state['debtActionTaken'] = true;
                    if ($isUnderLeveraged) {
                        $state['recapActionTaken'] = true;
                    }

                    if ($newDebtIssued > 500_000_000.0) {
                        $amtB = number_format($newDebtIssued / 1_000_000_000, 2);
                        $state['events'][] = ['description' => "Issued \${$amtB}B in bonds for " . ($isUnderLeveraged ? "recapitalization" : "expansion") . ".", 'shock' => 0.5];
                    }
                }
            }
        }
    }

    private function processOrganicCapex(Stock $stock, float $newEquity, float $nopat, float $operatingBase, array &$macroState, array $health, array &$state): void
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

        $archetypeStrategy = \App\Data\CeoArchetypes::getStrategy($stock->getCeoArchetype());
        $investmentProbability = $archetypeStrategy->modifyInvestmentProbability($investmentProbability, $trueReturn);
        $saturationPenalty = $this->corporateMetrics->calculateMarketSaturationPenalty($stock, $evaluationCapital, $macroState);
        $saturationPenalty = $archetypeStrategy->modifySaturationPenalty($saturationPenalty);
        $investmentProbability = max(0.05, $investmentProbability - $saturationPenalty);

        $isRecap = !empty($state['recapActionTaken']);
        $forcedExpansion = $state['debtActionTaken'] && !$isRecap;

        if (!$forcedExpansion && (mt_rand(1, 1000) / 1000.0) > $investmentProbability) {
            return; // Management decided to hold onto cash instead of expanding
        }

        $hoardStatus = $strategy->evaluateHoardingStatus($state['treasury'], $targetCashReserves, $operatingBase, $totalDebt);
        $excessCash = $hoardStatus['excess_cash'];
        $isHoarder = $hoardStatus['is_hoarder'];
        $isMegaHoarder = $hoardStatus['is_mega_hoarder'];

        // Bypass the hurdle rate check if the company is hoarding cash. Sitting on excess cash is a mathematically guaranteed drag on ROE.
        if ((($trueReturn > $hurdleRate || $isHoarder) && $excessCash > 0 && !$health['wants_to_paydown_debt']) || $forcedExpansion) {
            $spreadMultiplier = $isHoarder ? 1.0 : min(1.0, max(0.0, ($trueReturn - $hurdleRate) * 10.0));
            // Boosted deployment rate so massive hoards can actually be cleared
            $organicSpend = $excessCash * (0.15 + (0.35 * $spreadMultiplier));

            $expansionSpend = $strategy->calculateOrganicCapexSpend($organicSpend, $state['debtIssued']);
            $expansionSpend = min($expansionSpend, $excessCash);

            // Mega hoarders need massive physical capacity limits to flush the cash
            $maxGrowthSpeed = $isFinancial ? ($isMegaHoarder ? 0.35 : ($isHoarder ? 0.20 : 0.12)) : ($isHoarder ? 0.15 : 0.08);
            $expansionCapBasis = $isFinancial ? ($newEquity + $totalDebt) : $liveInvestedCapital;
            $expansionSpend = min($expansionSpend, $expansionCapBasis * $maxGrowthSpeed);

            if ($expansionSpend > 0) {
                $state['organicCapex'] = $expansionSpend;
                $state['treasury'] -= $expansionSpend;

                if ($expansionSpend > 1_000_000_000.0) {
                    $amtB = number_format($expansionSpend / 1_000_000_000, 2);
                    $actionText = match ($businessModel) {
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

    private function processEmergencyBorrowing(Stock $stock, float $operatingBase, array &$macroState, array $health, array &$state): void
    {
        $industry = $stock->getIndustry() ?: 'General';
        $strategy = \App\Data\Sectors::getBusinessModelStrategy(\App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none');
        $minOperatingCash = $strategy->calculateMinOperatingCash($operatingBase, $state['customerDeposits'], $state['wholesaleDebt']);

        if ($state['treasury'] < $minOperatingCash) {
            $cashShortfall = $minOperatingCash - $state['treasury'];

            if ($health['can_issue_debt']) {
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
            } else {
                // Liquidity Crisis - Cannot issue debt, MUST liquidate assets or dilute
                $state['failed_emergency_borrow'] = true;
            }
        }
    }

    private function processEquityIssuance(Stock $stock, float $operatingBase, array &$macroState, array $health, array &$state, float $totalBuybackCash): void
    {
        $currentPrice = (float) $stock->getPrice();
        if ($currentPrice <= 0.0) return;

        // CONTRADICTION CHECK: A company should never buy back shares and issue new shares in the exact same quarter.
        if ($totalBuybackCash > 0.0) return;

        $shares = (float) $stock->getSharesOutstanding();
        $eps = (float) $stock->getEarningsPerShare();
        $currentPE = $eps > 0 ? ($currentPrice / $eps) : 9999.0;

        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        $isFinancial = \App\Data\Sectors::isFinancial($businessModel);

        $trueReturn = $isFinancial ? (float) $stock->getCurrentRoe() : (float) $stock->getCurrentRoic();
        $hurdleRate = $isFinancial ? ($health['cost_of_equity'] ?? 0.10) : ($health['wacc'] ?? 0.08);
        $economicSpread = $trueReturn - $hurdleRate;

        $fairValuePE = $this->mathUtility->calculateIntrinsicFairValuePE($macroState['policy_rate'] ?? 0.04, $economicSpread, $macroState['equity_risk_premium'] ?? \App\Service\Macro\MacroEngine::BASE_EQUITY_RISK_PREMIUM);

        $bookValuePerShare = max(0.01, $stock->getTotalEquity() / max(1, $shares));
        $priceToBook = $currentPrice / $bookValuePerShare;

        // A true bubble requires the stock to trade at a massive premium to its actual physical footprint (P/B > 3.0).
        // Otherwise, a company with high interest expense will have a near-zero EPS, creating a mathematically infinite P/E that triggers false bubbles.
        $isBubble = $economicSpread > 0.0 && $currentPE > ($fairValuePE * 2.5) && $currentPE > 40.0 && $priceToBook > 3.0;
        $isDeathSpiral = $state['failed_emergency_borrow'] ?? false;

        $executeIssuance = false;

        if ($isDeathSpiral) {
            // Death Spiral: Management is desperate. Very high chance they dilute to survive, 
            // but some stubborn or incompetent management teams might freeze and do nothing.
            $executeIssuance = $this->mathUtility->generateUniform() < 0.80; // 80% chance
        } elseif ($isBubble) {
            // Bubble: Management exploits the premium valuation. The more extreme the bubble, the more likely they cash in.
            $bubbleSeverity = ($currentPE / max(1.0, $fairValuePE * 2.5)) - 1.0;
            $probBubble = min(0.90, 0.05 + ($bubbleSeverity * 0.20)); // Scales from 5% to 90% chance
            $executeIssuance = $this->mathUtility->generateUniform() < $probBubble;
        }

        if ($executeIssuance) {
            $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
            $minOperatingCash = $strategy->calculateMinOperatingCash($operatingBase, $state['customerDeposits'], $state['wholesaleDebt']);

            $targetRaise = 0.0;
            $reason = "";
            $shock = 0.0;

            if ($isDeathSpiral) {
                // Raise enough to cover the shortfall + 50% buffer
                $shortfall = max(0.0, $minOperatingCash - $state['treasury']);
                $targetRaise = $shortfall * 1.5;
                $reason = "execute a highly dilutive emergency stock offering to stave off bankruptcy";
                $shock = -15.0; // Market hates dilution, especially distressed dilution
            } elseif ($isBubble) {
                // Exploit the bubble to raise 5% of their market cap in cash, but cap it against their physical reality.
                // A company cannot raise more than 10% of its Invested Capital in a single offering without destroying its ROIC.
                $marketCap = $shares * $currentPrice;
                $investedCapital = (float) $stock->getInvestedCapital();
                $maxRaise = abs($investedCapital) * 0.10;
                $targetRaise = min($marketCap * 0.05, $maxRaise);
                $reason = "exploit premium valuation with a secondary offering";
                $shock = -5.0;
            }

            // Only dilute if the raise is meaningful
            if ($targetRaise > 10_000_000.0) {
                // Assume a 10% underpricing discount for the offering
                $offeringPrice = $currentPrice * 0.90;
                $sharesIssued = $targetRaise / max(0.01, $offeringPrice);

                $stock->setSharesOutstanding((string) ($shares + $sharesIssued));
                $state['treasury'] += $targetRaise;

                // ACCOUNTING FIX: A stock issuance must increase Book Value (Paid-in Capital)
                $currentEquity = (float) $stock->getTotalEquity();
                $stock->setTotalEquity((string) ($currentEquity + $targetRaise));

                $amtB = number_format($targetRaise / 1_000_000_000, 2);
                $state['events'][] = [
                    'description' => "Issued new shares to raise \${$amtB}B and {$reason}.",
                    'shock' => $shock
                ];

                $state['failed_emergency_borrow'] = false;
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

        $archetypeStrategy = \App\Data\CeoArchetypes::getStrategy($stock->getCeoArchetype());
        $targetOperatingCash = $archetypeStrategy->modifyTargetOperatingCash($targetOperatingCash);

        if (!$state['debtActionTaken'] && $state['wholesaleDebt'] > 0.0 && $state['treasury'] > $targetOperatingCash) {
            $excessCash = $state['treasury'] - $targetOperatingCash;
            $macroDebtTolerance = $health['debt_tolerance'];

            $evalDebt = $isFinancial ? $state['wholesaleDebt'] : $totalDebt;
            $modelThresholds = \App\Data\Sectors::getModelThresholds($businessModel);
            
            if ($isFinancial && isset($modelThresholds['wholesale_leverage_limit'])) {
                $effectiveCostOfDebt = $health['effective_cost'] ?? 0.05;
                $evalLimit = $archetypeStrategy->modifyDebtToleranceLimit($modelThresholds['wholesale_leverage_limit'], $effectiveCostOfDebt);
            } else {
                $evalLimit = $macroDebtTolerance;
            }

            $currentDebtRatio = $evalDebt / max(1.0, $newEquity);

            $baselineSpread = (float) $stock->getCreditSpread();
            $dynamicSpread = $health['raw_metrics']['dynamic_spread'] ?? $baselineSpread;
            $isJunkBondStatus = $dynamicSpread > ($baselineSpread + 0.0011);

            $hoardStatus = $strategy->evaluateHoardingStatus($state['treasury'], $targetOperatingCash, $operatingBase, $totalDebt);

            if ($currentDebtRatio > $evalLimit || $isJunkBondStatus || $hoardStatus['is_hoarder']) {
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

    private function processPassiveLiabilityGrowth(Stock $stock, array &$macroState, array &$state): void
    {
        $industry = $stock->getIndustry() ?: 'General';
        $strategy = \App\Data\Sectors::getBusinessModelStrategy(\App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none');
        $strategy->processPassiveLiabilityGrowth($stock, $macroState, $state, $this->mathUtility);
    }
}
