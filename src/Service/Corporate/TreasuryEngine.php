<?php

declare(strict_types=1);

namespace App\Service\Corporate;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\DTO\CapitalAllocationContext;

/**
 * Service responsible for managing the internal corporate balance sheet.
 * Handles CapEx, Debt Issuance, Deleveraging Sweeps, and Liquidity Crises.
 */
class TreasuryEngine
{
    // --- CapEx & Deployment Rates ---
    /** Base organic spend rate (15%) for retained cash hoards. */
    private const BASE_ORGANIC_SPEND_RATE = 0.15;
    /** Additional variable spend rate (35%) scaled by economic spread (ROIC - WACC). */
    private const VARIABLE_ORGANIC_SPEND_RATE = 0.35;

    // --- Physical Capacity Limits (Growth Speed Limits) ---
    private const FIN_MEGA_HOARDER_GROWTH_LIMIT = 0.35;
    private const FIN_HOARDER_GROWTH_LIMIT = 0.20;
    private const FIN_STANDARD_GROWTH_LIMIT = 0.12;
    private const STD_HOARDER_GROWTH_LIMIT = 0.15;
    private const STD_STANDARD_GROWTH_LIMIT = 0.08;

    public function __construct(
        private CorporateMetrics $corporateMetrics,
        private DebtEngine $debtEngine,
        private CapExEngine $capExEngine,
        private MathUtility $mathUtility
    ) {}

    /**
     * Phase 1 of Balance Sheet Update: Execute Corporate Strategy
     * Handles M2 growth, debt issuance (recapitalization/expansion), and organic CapEx.
     * This must run BEFORE buybacks so that newly issued debt cash can fund Leveraged Buybacks,
     * and Growth CapEx takes priority over share repurchases.
     */
    public function executeCorporateStrategy(CapitalAllocationContext $ctx): void
    {
        // SYSTEMIC M2 MONEY SUPPLY GROWTH
        $this->processPassiveLiabilityGrowth($ctx);

        // DEBT MANAGEMENT (MACRO TOLERANCE)
        $this->processDebtExpansion($ctx);

        // ORGANIC BUSINESS EXPANSION (Internal CapEx)
        $this->processOrganicCapex($ctx);

        // SAVE INTERMEDIATE TREASURY (so CapitalAllocationEngine can use it for buybacks)
        $ctx->stock->setCorporateTreasury((string) $ctx->newTreasury);
    }

    /**
     * Phase 2 of Balance Sheet Update: Finalize Liquidity & Equity
     * Handles emergency borrowing, deleveraging, and clean surplus accounting.
     * This must run AFTER buybacks to capture the true final cash balance.
     */
    public function finalizeLiquidity(CapitalAllocationContext $ctx): void
    {
        $stock = $ctx->stock;
        $totalCashSpent = $ctx->totalPaid + $ctx->totalCashSpent;

        // RETAINED EARNINGS
        $currentRetainedStr = $this->formatBc($stock->getRetainedEarnings());
        $netIncomeStr = $this->formatBc($ctx->quarterlyNetIncome);
        $divPaidStr = $this->formatBc($ctx->totalPaid);
        $newRetainedStr = \bcsub(\bcadd($currentRetainedStr, $netIncomeStr, 4), $divPaidStr, 4);
        $stock->setRetainedEarnings($newRetainedStr);

        // TOTAL EQUITY (Clean Surplus Accounting)
        // physicalAssetAppreciation represents organic macro inflation scaling PLUS REIT depreciation offsets
        $currentEquityStr = $this->formatBc($stock->getTotalEquity());
        $reApprecStr = $this->formatBc($ctx->physicalAssetAppreciation);
        $totalCashSpentStr = $this->formatBc($totalCashSpent);
        $newEquityStr = \bcsub(\bcadd(\bcadd($currentEquityStr, $netIncomeStr, 4), $reApprecStr, 4), $totalCashSpentStr, 4);
        $stock->setTotalEquity($newEquityStr);

        // THE DEBT TRAP (Liquidity Crisis)
        $this->processEmergencyBorrowing($ctx);

        // EQUITY ISSUANCE (Secondary Offerings / Death Spirals)
        $this->processEquityIssuance($ctx);

        // ARBITRAGE PAYDOWN (Escape negative carry)
        $this->processArbitragePaydown($ctx);

        // THE DELEVERAGING SWEEP (Macro-Driven Cash Management)
        $this->processDeleveragingSweep($ctx, (float) $newEquityStr);

        // SAVE FINAL TREASURY
        $stock->setCorporateTreasury((string) $ctx->newTreasury);
    }

    private function processDebtExpansion(CapitalAllocationContext $ctx): void
    {
        $stock = $ctx->stock;

        $currentEquity = (float) $stock->getTotalEquity();
        $preBuybackEquity = $currentEquity + $ctx->quarterlyNetIncome + $ctx->physicalAssetAppreciation - $ctx->totalPaid;

        $totalDebt = $ctx->wholesaleDebt + $ctx->customerDeposits;
        $liveInvestedCapital = $this->corporateMetrics->calculateLiveInvestedCapital($preBuybackEquity, $totalDebt, $ctx->newTreasury);

        $trueReturn = $ctx->strategy->getTrueReturn($stock);
        if ($trueReturn === 0.0) {
            $trueReturn = $ctx->strategy->calculateEconomicReturn($stock, $ctx->quarterlyNopat, $liveInvestedCapital);
        }
        $hurdleRate = $ctx->strategy->getHurdleRate($ctx->health);

        $evaluationCapital = $ctx->strategy->getEvaluationCapital($preBuybackEquity, $liveInvestedCapital);

        $archetypeStrategy = \App\Data\CeoArchetypes::getStrategy($stock);
        $saturationPenalty = $this->corporateMetrics->calculateMarketSaturationPenalty($stock, $evaluationCapital, $ctx->macroState);
        $saturationPenalty = $archetypeStrategy->modifySaturationPenalty($saturationPenalty);
        $marginalReturn = $this->corporateMetrics->calculateMarginalReturn($stock, $trueReturn, $saturationPenalty, $evaluationCapital, $ctx->macroState);

        // Financials aggressively use isUnderLeveraged for stock buybacks to crush equity bloat, 
        // but they should NEVER issue massive amounts of expensive wholesale bonds just to increase leverage.
        $triggerWholesaleDebt = $ctx->isFinancial ? false : $ctx->health->isUnderLeveraged;
        $isUnderLeveragedForDebt = $triggerWholesaleDebt && !$ctx->health->isSevereNegativeCarry;

        if (($marginalReturn > $hurdleRate || $isUnderLeveragedForDebt) && $ctx->health->canIssueDebt) {
            $newBorrowingRate = $ctx->health->rawMetrics->currentMarketRate ?? 0.05;

            $ebit = $ctx->health->rawMetrics->ebit ?? 0.0;
            $depreciation = $ctx->health->rawMetrics->depreciation ?? 0.0;

            $trueExpansionCapacity = $ctx->strategy->calculateDebtExpansionCapacity(
                $preBuybackEquity,
                $totalDebt,
                $ctx->wholesaleDebt,
                $ctx->health,
                $newBorrowingRate,
                $ebit,
                $depreciation
            );

            // Subtract excess cash from the TOTAL balance sheet capacity first
            $targetCashReserves = $ctx->strategy->calculateTargetOperatingCash($ctx->operatingBase, $ctx->customerDeposits, $ctx->wholesaleDebt) * 1.20;
            $excessCash = max(0.0, $ctx->newTreasury - $targetCashReserves);

            $trueExpansionCapacity = $ctx->strategy->getUnfundedExpansionCapacity($trueExpansionCapacity, $excessCash);

            // Max 25% of operations per quarter (Quarterly Flow Limit)
            $trueExpansionCapacity = min($trueExpansionCapacity, $liveInvestedCapital * 0.25);

            if ($trueExpansionCapacity > 0) {
                $rawSpread = max(0.0, $marginalReturn - $hurdleRate);
                $spreadMultiplier = min(1.0, $rawSpread * 10.0);

                $aggressionData = $ctx->strategy->getDebtExpansionAggressiveness(
                    $spreadMultiplier,
                    $totalDebt,
                    $ctx->customerDeposits,
                    $targetCashReserves,
                    $ctx->newTreasury
                );

                $borrowProbability = $aggressionData['probability'];
                $aggressiveness = $aggressionData['aggressiveness'];

                if ($isUnderLeveragedForDebt) {
                    $aggressiveness = max($aggressiveness, 0.50);
                    $borrowProbability = max($borrowProbability, 0.90);
                }

                if ((mt_rand() / mt_getrandmax()) < $borrowProbability) {
                    $newDebtIssued = $trueExpansionCapacity * $aggressiveness;

                    $this->debtEngine->issueDebt($stock, $newDebtIssued, $newBorrowingRate);

                    $ctx->wholesaleDebt = (float) $stock->getWholesaleDebt();
                    $ctx->newTreasury += $newDebtIssued;
                    $ctx->debtIssued = $newDebtIssued;
                    $ctx->debtActionTaken = true;
                    if ($isUnderLeveragedForDebt) {
                        $ctx->recapActionTaken = true;
                    }

                    if ($newDebtIssued > 500_000_000.0) {
                        $amtB = number_format($newDebtIssued / 1_000_000_000, 2);
                        $ctx->events[] = ['description' => "Issued \${$amtB}B in bonds for " . ($isUnderLeveragedForDebt ? "recapitalization" : "expansion") . ".", 'shock' => 0.5];
                    }
                }
            }
        }
    }

    private function processOrganicCapex(CapitalAllocationContext $ctx): void
    {
        $stock = $ctx->stock;
        $currentEquity = (float) $stock->getTotalEquity();
        $preBuybackEquity = $currentEquity + $ctx->quarterlyNetIncome + $ctx->physicalAssetAppreciation - $ctx->totalPaid;

        $totalDebt = $ctx->wholesaleDebt + $ctx->customerDeposits;
        $liveInvestedCapital = $this->corporateMetrics->calculateLiveInvestedCapital($preBuybackEquity, $totalDebt, $ctx->newTreasury);
        $trueReturn = $ctx->strategy->getTrueReturn($stock);
        if ($trueReturn === 0.0) {
            $trueReturn = $ctx->strategy->calculateEconomicReturn($stock, $ctx->quarterlyNopat, $liveInvestedCapital);
        }
        $hurdleRate = $ctx->strategy->getHurdleRate($ctx->health);
        $evaluationCapital = $ctx->strategy->getEvaluationCapital($preBuybackEquity, $liveInvestedCapital);

        $archetypeStrategy = \App\Data\CeoArchetypes::getStrategy($stock);
        $saturationPenalty = $this->corporateMetrics->calculateMarketSaturationPenalty($stock, $evaluationCapital, $ctx->macroState);
        $saturationPenalty = $archetypeStrategy->modifySaturationPenalty($saturationPenalty);
        $marginalReturn = $this->corporateMetrics->calculateMarginalReturn($stock, $trueReturn, $saturationPenalty, $evaluationCapital, $ctx->macroState);

        $investmentProbability = min(0.95, max(0.10, 0.20 + ($marginalReturn * 2.0)));
        $investmentProbability = $archetypeStrategy->modifyInvestmentProbability($investmentProbability, $marginalReturn);

        $isRecap = $ctx->recapActionTaken;
        $forcedExpansion = $ctx->debtActionTaken && !$isRecap;

        // Under-leveraged financials must shrink equity via buybacks, not grow assets.
        // Skipping organic capex entirely here ensures the FCF stays in treasury so
        // CapitalAllocationEngine::executeBuybacks() can deploy it for recapitalization.
        // Expanding the loan book when D/E is far below the regulatory target only worsens
        // equity bloat and suppresses ROE further.
        if (!$forcedExpansion && $ctx->isFinancial && ($ctx->health->isUnderLeveraged ?? false)) {
            return;
        }

        if (!$forcedExpansion && (mt_rand(1, 1000) / 1000.0) > $investmentProbability) {
            return;
        }

        $targetCashReserves = $ctx->strategy->calculateTargetOperatingCash($ctx->operatingBase, $ctx->customerDeposits, $ctx->wholesaleDebt) * 1.20;
        $hoardStatus = $ctx->strategy->evaluateHoardingStatus($ctx->newTreasury, $targetCashReserves, $ctx->operatingBase, $totalDebt);
        $excessCash = $hoardStatus['excess_cash'];
        $isHoarder = $hoardStatus['is_hoarder'];
        $isMegaHoarder = $hoardStatus['is_mega_hoarder'];

        if ((($trueReturn > $hurdleRate || $isHoarder) && $excessCash > 0 && !$ctx->health->wantsToPaydownDebt) || $forcedExpansion) {
            $spreadMultiplier = $isHoarder ? 1.0 : min(1.0, max(0.0, ($trueReturn - $hurdleRate) * 10.0));

            $baseExcessCash = max(0.0, $excessCash - $ctx->debtIssued);
            $organicSpend = $baseExcessCash * (self::BASE_ORGANIC_SPEND_RATE + (self::VARIABLE_ORGANIC_SPEND_RATE * $spreadMultiplier));

            $expansionSpend = $ctx->strategy->calculateOrganicCapexSpend($organicSpend, $ctx->debtIssued);
            $expansionSpend = min($expansionSpend, $excessCash);

            $maxGrowthSpeed = $ctx->strategy->getMaxOrganicGrowthSpeed($isHoarder, $isMegaHoarder);

            $expansionCapBasis = $ctx->strategy->getExpansionCapacityBasis($preBuybackEquity, $totalDebt, $liveInvestedCapital);
            $maxOrganicCapacity = $expansionCapBasis * $maxGrowthSpeed;

            $expansionSpend = min($expansionSpend, max($maxOrganicCapacity, $ctx->debtIssued));

            if ($marginalReturn <= 0.0 && !$forcedExpansion) {
                $expansionSpend = 0.0;
            }

            if ($expansionSpend > 0) {
                $ctx->organicCapex = $expansionSpend;
                $ctx->newTreasury -= $expansionSpend;

                $this->capExEngine->allocateGrowthCapEx($stock, $expansionSpend);

                if ($expansionSpend > 1_000_000_000.0) {
                    $amtB = number_format($expansionSpend / 1_000_000_000, 2);
                    $actionText = match ($ctx->businessModel) {
                        'commercial_bank', 'credit_services', 'shadow_bank' => 'loan book expansion',
                        'insurance' => 'underwriting infrastructure and float expansion',
                        'brokerage', 'investment_bank' => 'trading desk and market-making capacity',
                        'asset_manager', 'private_equity' => 'fund seeding and AUM deployment',
                        'clearing_house' => 'clearing collateral and exchange margin reserves',
                        'distressed_debt' => 'distressed credit and turnaround equity acquisitions',
                        'reit' => 'property acquisitions and development',
                        default => 'organic expansion'
                    };
                    $ctx->events[] = ['description' => "Deployed \${$amtB}B in {$actionText}.", 'shock' => 0.5];
                }
            }
        }
    }

    private function processEmergencyBorrowing(CapitalAllocationContext $ctx): void
    {
        $stock = $ctx->stock;
        $minOperatingCash = $ctx->strategy->calculateMinOperatingCash($ctx->operatingBase, $ctx->customerDeposits, $ctx->wholesaleDebt);

        if ($ctx->newTreasury < $minOperatingCash) {
            $cashShortfall = $minOperatingCash - $ctx->newTreasury;

            if ($ctx->health->canIssueDebt) {
                $currentMarketRate = $ctx->health->rawMetrics->currentMarketRate ?? ($ctx->macroState->yield5yEma + (float) $stock->getCreditSpread());
                $costOfEmergencyDebt = $currentMarketRate + FinancialConstants::EMERGENCY_DEBT_SPREAD_PENALTY;

                $this->debtEngine->issueDebt($stock, $cashShortfall, $costOfEmergencyDebt);

                $ctx->wholesaleDebt = (float) $stock->getWholesaleDebt();
                $ctx->newTreasury = $minOperatingCash;
                $ctx->debtActionTaken = true;

                if ($cashShortfall > 10_000_000.0) {
                    $ctx->events[] = ['description' => "Forced to borrow \$" . number_format($cashShortfall / 1_000_000_000, 2) . "B at penalty rates due to cash shortfall.", 'shock' => -5.0];
                }
            } else {
                $ctx->failedEmergencyBorrow = true;
            }
        }
    }

    private function processEquityIssuance(CapitalAllocationContext $ctx): void
    {
        $stock = $ctx->stock;
        if ($ctx->currentPrice <= 0.0) return;

        if ($ctx->totalCashSpent > 0.0) return;

        $currentPE = $ctx->quarterlyEps > 0 ? ($ctx->currentPrice / ($ctx->quarterlyEps * 4)) : 9999.0;
        if ($ctx->actualAnnualEps > 0) {
            $currentPE = $ctx->currentPrice / $ctx->actualAnnualEps;
        }

        $trueReturn = $ctx->strategy->getTrueReturn($stock);
        $hurdleRate = $ctx->strategy->getHurdleRate($ctx->health);
        $economicSpread = $trueReturn - $hurdleRate;

        $fairValuePE = $this->mathUtility->calculateIntrinsicFairValuePE($hurdleRate, $trueReturn, 0.02);

        $bookValuePerShare = max(0.01, $stock->getTotalEquity() / max(1, $ctx->sharesOutstanding));
        $priceToBook = $ctx->currentPrice / $bookValuePerShare;

        $isBubble = $economicSpread > 0.0 && $currentPE > ($fairValuePE * 2.5) && $currentPE > 40.0 && $priceToBook > 3.0;
        $isDeathSpiral = $ctx->failedEmergencyBorrow;

        $executeIssuance = false;

        if ($isDeathSpiral) {
            $executeIssuance = $this->mathUtility->generateUniform() < 0.80;
        } elseif ($isBubble) {
            $bubbleSeverity = ($currentPE / max(1.0, $fairValuePE * 2.5)) - 1.0;
            $probBubble = min(0.90, 0.05 + ($bubbleSeverity * 0.20));
            $executeIssuance = $this->mathUtility->generateUniform() < $probBubble;
        }

        if ($executeIssuance) {
            $minOperatingCash = $ctx->strategy->calculateMinOperatingCash($ctx->operatingBase, $ctx->customerDeposits, $ctx->wholesaleDebt);

            $targetRaise = 0.0;
            $reason = "";
            $shock = 0.0;

            if ($isDeathSpiral) {
                $shortfall = max(0.0, $minOperatingCash - $ctx->newTreasury);
                $targetRaise = $shortfall * 1.5;
                $reason = "execute a highly dilutive emergency stock offering to stave off bankruptcy";
                $shock = -15.0;
            } elseif ($isBubble) {
                $marketCap = $ctx->sharesOutstanding * $ctx->currentPrice;
                $investedCapital = (float) $stock->getInvestedCapital();
                $maxRaise = abs($investedCapital) * 0.10;
                $targetRaise = min($marketCap * 0.05, $maxRaise);
                $reason = "exploit premium valuation with a secondary offering";
                $shock = -5.0;
            }

            if ($targetRaise > 10_000_000.0) {
                $offeringPrice = $ctx->currentPrice * 0.90;
                $sharesIssued = $targetRaise / max(0.01, $offeringPrice);

                $stock->setSharesOutstanding((string) ($ctx->sharesOutstanding + $sharesIssued));
                $ctx->newTreasury += $targetRaise;

                $currentEquityStr = $this->formatBc($stock->getTotalEquity());
                $stock->setTotalEquity(\bcadd($currentEquityStr, $this->formatBc($targetRaise), 4));

                $amtB = number_format($targetRaise / 1_000_000_000, 2);
                $ctx->events[] = [
                    'description' => "Issued new shares to raise \${$amtB}B and {$reason}.",
                    'shock' => $shock
                ];

                $ctx->failedEmergencyBorrow = false;
            }
        }
    }

    private function processArbitragePaydown(CapitalAllocationContext $ctx): void
    {
        $stock = $ctx->stock;
        $targetOperatingCash = $ctx->strategy->calculateTargetOperatingCash($ctx->operatingBase, $ctx->customerDeposits, $ctx->wholesaleDebt);

        if (!$ctx->debtActionTaken && $ctx->health->wantsToPaydownDebt && $ctx->wholesaleDebt > 0 && $ctx->newTreasury > $targetOperatingCash) {
            $distressThreshold = 1.0 + $ctx->strategy->getRequiredIcrBuffer();
            $isLiquidityCrisis = $ctx->health->interestCoverage < 1.0;
            $isDistressed = $ctx->health->interestCoverage < $distressThreshold;
            $paydownProbability = $isLiquidityCrisis ? 1.0 : ($isDistressed ? 0.50 : 0.15);

            if ((mt_rand() / mt_getrandmax()) < $paydownProbability) {
                $sweepPercentage = $isLiquidityCrisis ? 0.50 : 0.10;
                $arbitragePaydown = ($ctx->newTreasury - $targetOperatingCash) * $sweepPercentage;
                $maxRetireableDebt = $ctx->wholesaleDebt * ($isLiquidityCrisis ? 0.15 : 0.05);
                $actualPaydown = min($arbitragePaydown, $ctx->wholesaleDebt, $maxRetireableDebt);

                if ($actualPaydown > 0) {
                    $ctx->wholesaleDebt -= $actualPaydown;
                    $stock->setWholesaleDebt((string) $ctx->wholesaleDebt);
                    $ctx->newTreasury -= $actualPaydown;
                    $ctx->debtActionTaken = true;

                    if ($actualPaydown > 500_000_000.0) {
                        $amtB = number_format($actualPaydown / 1_000_000_000, 2);
                        $reason = $isLiquidityCrisis ? "survive a liquidity crisis" : "reduce debt burden and escape negative carry";
                        $ctx->events[] = [
                            'description' => "Paid down \${$amtB}B of debt to {$reason}.",
                            'shock' => 1.0
                        ];
                    }
                }
            }
        }
    }

    private function processDeleveragingSweep(CapitalAllocationContext $ctx, float $newEquity): void
    {
        $stock = $ctx->stock;
        $totalDebt = $ctx->wholesaleDebt + $ctx->customerDeposits;

        $targetOperatingCash = $ctx->strategy->calculateTargetOperatingCash($ctx->operatingBase, $ctx->customerDeposits, $ctx->wholesaleDebt);
        $archetypeStrategy = \App\Data\CeoArchetypes::getStrategy($stock);
        $targetOperatingCash = $archetypeStrategy->modifyTargetOperatingCash($targetOperatingCash);

        if (!$ctx->debtActionTaken && $ctx->wholesaleDebt > 0.0 && $ctx->newTreasury > $targetOperatingCash) {
            $excessCash = $ctx->newTreasury - $targetOperatingCash;
            $macroDebtTolerance = $ctx->health->debtTolerance;

            $evalDebt = $ctx->strategy->getDeleveragingEvaluationDebt($totalDebt, $ctx->wholesaleDebt);
            $modelThresholds = $ctx->strategy->getModelThresholds();
            $evalLimit = $ctx->strategy->getDeleveragingEvaluationLimit($modelThresholds, $macroDebtTolerance);

            if (isset($modelThresholds['wholesale_leverage_limit'])) {
                // Financial institutions: the wholesale_leverage_limit is a regulatory ceiling,
                // not subject to CEO personality adjustments. Archetypes should not crush it.
            } else {
                $effectiveCostOfDebt = $ctx->health->effectiveCost ?? 0.05;
                $evalLimit = $archetypeStrategy->modifyDebtToleranceLimit($evalLimit, $effectiveCostOfDebt);
            }

            $currentDebtRatio = $evalDebt / max(1.0, $newEquity);

            $hoardStatus = $ctx->strategy->evaluateHoardingStatus($ctx->newTreasury, $targetOperatingCash, $ctx->operatingBase, $totalDebt);

            $baselineSpread = (float) $stock->getCreditSpread();
            $dynamicSpread = $ctx->health->rawMetrics->dynamicSpread ?? $baselineSpread;
            $isJunkBondStatus = $dynamicSpread > ($baselineSpread + 0.0011);

            $shouldSweep = $currentDebtRatio > $evalLimit;

            // Commercial banks and insurers have structural, regulatory-driven balance sheets 
            // where "cash hoarding" is just normal float/deposits, and junk status on marginal debt 
            // shouldn't force them to liquidate their structural funding.
            if (!in_array($ctx->businessModel, ['commercial_bank', 'insurance'])) {
                if ($isJunkBondStatus || $hoardStatus['is_hoarder']) {
                    $shouldSweep = true;
                }
            }

            if ($shouldSweep) {
                $targetRatio = $isJunkBondStatus ? max(0.10, $evalLimit * 0.75) : max(0.10, $evalLimit - 0.05);

                $targetTotalDebt = $newEquity * $targetRatio;
                $debtToPayOff = min($excessCash, max(0.0, $evalDebt - $targetTotalDebt));
                $debtToPayOff = min($debtToPayOff, $ctx->wholesaleDebt);

                if ($debtToPayOff > 0) {
                    $ctx->wholesaleDebt -= $debtToPayOff;
                    $stock->setWholesaleDebt((string) $ctx->wholesaleDebt);
                    $ctx->newTreasury -= $debtToPayOff;

                    if ($debtToPayOff > 500_000_000.0) {
                        $amtB = number_format($debtToPayOff / 1_000_000_000, 2);
                        $ctx->events[] = [
                            'description' => "Swept \${$amtB}B cash to aggressively deleverage.",
                            'shock' => 2.0
                        ];
                    }
                }
            }
        }
    }

    private function processPassiveLiabilityGrowth(CapitalAllocationContext $ctx): void
    {
        // Pack state for interface call
        $state = [
            'treasury' => $ctx->newTreasury,
            'wholesaleDebt' => $ctx->wholesaleDebt,
            'customerDeposits' => $ctx->customerDeposits,
            'debtIssued' => $ctx->debtIssued,
            'organicCapex' => $ctx->organicCapex,
            'debtActionTaken' => $ctx->debtActionTaken,
            'bank_apy' => $ctx->bankApy,
            'failed_emergency_borrow' => $ctx->failedEmergencyBorrow,
            'events' => $ctx->events
        ];

        $ctx->strategy->processPassiveLiabilityGrowth($ctx->stock, $ctx->macroState, $state, $this->mathUtility);

        // Unpack state
        $ctx->newTreasury = $state['treasury'];
        $ctx->wholesaleDebt = $state['wholesaleDebt'];
        $ctx->customerDeposits = $state['customerDeposits'];
        $ctx->debtIssued = $state['debtIssued'];
        $ctx->organicCapex = $state['organicCapex'];
        $ctx->debtActionTaken = $state['debtActionTaken'];
        $ctx->bankApy = $state['bank_apy'];
        $ctx->failedEmergencyBorrow = $state['failed_emergency_borrow'];
        $ctx->events = $state['events'];
    }

    private function formatBc(int|float|string|null $val, int $scale = 4): string
    {
        if ($val === null || $val === '') {
            return '0.' . str_repeat('0', $scale);
        }
        if (is_string($val)) {
            if (stripos($val, 'e') !== false && is_numeric($val)) {
                $val = (float) $val;
            } else {
                return $val;
            }
        }
        if (is_numeric($val) && !is_finite((float) $val)) {
            return '0.' . str_repeat('0', $scale);
        }
        return sprintf('%.' . $scale . 'F', (float) $val);
    }
}
