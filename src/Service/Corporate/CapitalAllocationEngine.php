<?php

namespace App\Service\Corporate;

use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Macro\MacroEngine;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;

/**
 * Service responsible for returning cash to shareholders.
 * Handles Dividends and Share Repurchases (Buybacks).
 */
class CapitalAllocationEngine
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CorporateMetrics $corporateMetrics,
        private DebtEngine $debtEngine,
        private MathUtility $mathUtility,
        private TreasuryEngine $treasuryEngine
    ) {}

    /**
     * Allocates quarterly capital via dividends and buybacks, and updates the balance sheet.
     */
    public function allocateCapital(
        Stock $stock,
        float $actualAnnualEps,
        float $quarterlyFcfPerShare,
        float $currentPrice,
        float $sharesOutstanding,
        array &$macroState,
        float $actualTotalNetIncome = 0.0
    ): array {
        $events = [];
        $oldShares = $sharesOutstanding;
        $quarterlyEps = $actualAnnualEps / 4.0;

        // $actualTotalNetIncome passed from EarningsEngine is ALREADY QUARTERLY. Do not divide by 4.
        $quarterlyNetIncome = $actualTotalNetIncome != 0.0 ? $actualTotalNetIncome : ($quarterlyEps * $oldShares);

        $currentTreasury = (float) $stock->getCorporateTreasury();
        $operatingBase = $this->corporateMetrics->calculateOperatingBase((float) $stock->getTotalRevenue(), (float) $stock->getTotalEquity());
        $investedCapital = $stock->getInvestedCapital();

        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';

        $realEstateAppreciation = 0.0;
        if ($businessModel === 'reit') {
            $customDepreciation = (float) $stock->getDepreciationRate();
            $depRate = $customDepreciation > 0.0 ? $customDepreciation : $this->corporateMetrics->getIndustryDepreciationRate($industry);
            $absoluteDepreciation = $investedCapital * $depRate;
            // Real Estate Appreciation: Offset GAAP depreciation so book value doesn't bleed to zero
            $realEstateAppreciation = $absoluteDepreciation / 4.0;
        }

        $health = $this->debtEngine->analyzeDebtHealth($stock, $macroState);

        $ebit = $health['raw_metrics']['ebit'] ?? 0.0;
        $corporateTaxRate = $macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE;
        $nopat = $ebit > 0 ? $ebit * (1.0 - $corporateTaxRate) : $ebit;

        // CALCULATE BASELINE CASH CHANGES
        $totalFcfGenerated = $quarterlyFcfPerShare * $oldShares;
        $newTreasury = $currentTreasury + $totalFcfGenerated;

        // EXECUTE DIVIDENDS
        $divData = $this->executeDividends($stock, $quarterlyEps, $oldShares, $currentPrice, $newTreasury, $operatingBase, $investedCapital, $nopat, $health, $actualTotalNetIncome);
        if ($divData['event']) $events[] = $divData['event'];

        $newTreasury -= $divData['total_paid'];

        // CORPORATE STRATEGY (CapEx & Debt Expansion)
        // We calculate pre-buyback equity to pass to Treasury for leverage ratios
        $currentEquity = (float) $stock->getTotalEquity();
        $preBuybackEquity = $currentEquity + $quarterlyNetIncome + $realEstateAppreciation - $divData['total_paid'];

        $strategyEvents = $this->treasuryEngine->executeCorporateStrategy(
            $stock,
            $preBuybackEquity,
            $operatingBase,
            $nopat,
            $newTreasury,
            $macroState,
            $health
        );

        if (!empty($strategyEvents['events'])) {
            $events = array_merge($events, $strategyEvents['events']);
        }

        // Update Treasury with post-strategy cash (includes newly issued debt minus CapEx)
        $newTreasury = $strategyEvents['new_treasury'];
        $organicCapex = $strategyEvents['organic_capex'] ?? 0.0;
        $bankApy = $strategyEvents['bank_apy'] ?? null;

        // CALCULATE EXCESS CASH
        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
        $targetOperatingCash = $strategy->calculateTargetOperatingCash($operatingBase, (float) $stock->getCustomerDeposits(), (float) $stock->getWholesaleDebt());
        $excessCash = max(0.0, $newTreasury - $targetOperatingCash);

        $currentPE = $actualAnnualEps > 0 ? ($currentPrice / $actualAnnualEps) : 9999.0;
        $retainedEarningsThisQuarter = max(0.0, $quarterlyNetIncome - $divData['total_paid']);

        // THE TRADING DESK: EXECUTE BUYBACKS
        if ($businessModel === 'reit') {
            $buybackData = ['new_shares' => $oldShares, 'total_cash_spent' => 0.0, 'event' => null];
        } else {
            $buybackData = $this->executeBuybacks(
                $stock,
                $newTreasury,
                $targetOperatingCash,
                $oldShares,
                $currentPrice,
                $currentPE,
                $operatingBase,
                $investedCapital,
                $nopat,
                $health,
                $macroState,
                $actualTotalNetIncome,
                $retainedEarningsThisQuarter
            );
        }
        if ($buybackData['event']) $events[] = $buybackData['event'];

        $newTreasury -= $buybackData['total_cash_spent'];

        // FINALIZE LIQUIDITY & EQUITY
        $liquidityEvents = $this->treasuryEngine->finalizeLiquidity(
            $stock,
            $quarterlyNetIncome,
            $divData['total_paid'],
            $buybackData['total_cash_spent'],
            $operatingBase,
            $newTreasury,
            $macroState,
            $health,
            $realEstateAppreciation
        );

        if (!empty($liquidityEvents['events'])) {
            $events = array_merge($events, $liquidityEvents['events']);
        }

        return [
            'new_shares' => $buybackData['new_shares'],
            'dividend_paid' => $divData['dividend_per_share'],
            'total_paid' => $divData['total_paid'],
            'total_cash_spent' => $buybackData['total_cash_spent'],
            'bank_apy' => $bankApy,
            'organic_capex' => $organicCapex,
            'events' => $events
        ];
    }

    private function executeDividends(Stock $stock, float $quarterlyEps, float $shares, float $currentPrice, float $availableTreasury, float $operatingBase, float $investedCapital, float $nopat, array $health, float $actualTotalNetIncome = 0.0): array
    {
        $targetPayout = (float) $stock->getTargetPayoutRatio();
        $speed = (float) $stock->getDividendSpeed();
        $lastDividend = (float) $stock->getLastDividend();
        $archetypeStrategy = \App\Data\CeoArchetypes::getStrategy($stock->getCeoArchetype());

        $targetPayout = $archetypeStrategy->modifyTargetPayoutRatio($targetPayout);

        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
        $isFinancial = \App\Data\Sectors::isFinancial($businessModel);

        $customDepreciation = (float) $stock->getDepreciationRate();
        $depRate = $customDepreciation > 0.0 ? $customDepreciation : $this->corporateMetrics->getIndustryDepreciationRate($industry);

        $sustainableBase = $strategy->getSustainableDividendBase($stock, $quarterlyEps, $investedCapital, $depRate);
        $calculatedTarget = $sustainableBase > 0 ? ($sustainableBase * $targetPayout) : 0.0;
        $targetDividend = $speed > 0.05 ? $calculatedTarget : max($calculatedTarget, $lastDividend);

        $isRegulatoryDividendHalt = false;
        if ($isFinancial) {
            $trueReturn = (float)$stock->getTotalEquity() > 0 ? ($actualTotalNetIncome / (float)$stock->getTotalEquity()) * 4.0 : 0.0;
            $hurdleRate = $health['cost_of_equity'] ?? 0.10;

            $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$stock->getIndustry() ?? 'General']['equity_limit'] ?? 10.0;
            if ((float)$stock->getDebtToEquityRatio() > $equityLimit) {
                $isRegulatoryDividendHalt = true;
            }
        } else {
            $adjustedNopat = $nopat;
            if ($businessModel === 'reit') {
                $adjustedNopat += ($investedCapital * $depRate); // FFO/NOI adjustment
            }
            $trueReturn = $investedCapital > 0 ? ($adjustedNopat / $investedCapital) * 4.0 : 0.0;
            $hurdleRate = $health['wacc'];
        }

        $evaSpread = $trueReturn - $hurdleRate;
        $isTitan = in_array($stock->getSystemicImportance(), ['titan']);
        $isAristocrat = $speed <= 0.05;

        $distressMultiplier = 1.0 + ($isTitan ? 0.2 : 0.0) + ($isAristocrat ? 0.2 : 0.0);

        $minOperatingCash = $strategy->calculateMinOperatingCash($operatingBase, (float) $stock->getCustomerDeposits(), (float) $stock->getWholesaleDebt());
        $hasCashBuffer = $availableTreasury > ($minOperatingCash * 1.5);
        $isCriticalCash = $availableTreasury < $minOperatingCash;

        $isDeepDistress = $evaSpread < (-0.08 * $distressMultiplier);
        $isModerateDistressNoCash = ($evaSpread < (-0.04 * $distressMultiplier)) && !$hasCashBuffer;

        $modelThresholds = \App\Data\Sectors::getModelThresholds($businessModel);
        $crisisThreshold = $modelThresholds['dividend_crisis_icr'];
        $isLiquidityCrisis = $health['interest_coverage'] < 1.0 || ($health['interest_coverage'] < $crisisThreshold && !$hasCashBuffer);

        $cutDividend = false;
        if ($isDeepDistress || $isLiquidityCrisis || $isRegulatoryDividendHalt) {
            if ($archetypeStrategy->shouldResistDividendCut($isLiquidityCrisis, $isRegulatoryDividendHalt)) {
                // The CEO refuses to cut the dividend!
            } else {
                $targetDividend = 0.0;
                $cutDividend = true;
                $speed = ($isLiquidityCrisis || $isRegulatoryDividendHalt) ? 1.0 : min(1.0, $speed + 0.25);
            }
        } elseif ($isModerateDistressNoCash) {
            if ($archetypeStrategy->shouldResistDividendCut(false, false)) {
                // The CEO refuses to cut the dividend!
            } else {
                // Moderate distress without cash buffer: Rebase dividend to 50% of last dividend to preserve capital without total elimination
                $targetDividend = $lastDividend * 0.50;
                $cutDividend = true;
                $speed = min(1.0, $speed + 0.15);
            }
        } elseif ($isCriticalCash && $calculatedTarget < $lastDividend) {
            $targetDividend = $calculatedTarget;
            if ($speed <= 0.05) $speed = 0.50;
        }

        if ($lastDividend > 0 && $speed <= 0.05) {
            $catchUpRatio = $calculatedTarget / $lastDividend;
            if ($catchUpRatio > 1.30) {
                $speed = min(0.15, $speed + (($catchUpRatio - 1.30) * 0.10));
            }
        }

        $newDividend = max(0.0, $lastDividend + ($speed * ($targetDividend - $lastDividend)));

        $usableCash = max(0.0, $availableTreasury - $minOperatingCash);

        $maxDividendPerShare = $shares > 0 ? ($usableCash / $shares) : 0.0;
        $maxMarketDividend = max(0.0, $currentPrice * 0.10);

        // SECURITY MEASURE: Do not allow dividends to drive Book Equity below 0.
        $currentEquity = (float) $stock->getTotalEquity();
        $maxEquityDividendPerShare = $shares > 0 ? (max(0.0, $currentEquity * 0.50) / $shares) : 0.0;

        $newDividend = min($newDividend, $maxDividendPerShare, $maxMarketDividend, $maxEquityDividendPerShare);
        $totalPaid = $newDividend * $shares;
        $event = null;

        if ($newDividend > 0.0) {
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE users u
                 INNER JOIN user_stocks us ON u.id = us.user_id
                 SET u.cash_balance = u.cash_balance + (us.quantity * :dividend)
                 WHERE us.stock_id = :stock_id',
                ['dividend' => $newDividend, 'stock_id' => $stock->getId()]
            );

            $stock->setLastDividend((string) $newDividend);
            $yield = (($newDividend * 4) / max($currentPrice, 0.01)) * 100;
            $totalPaidStr = $totalPaid >= 1_000_000_000 ? number_format($totalPaid / 1_000_000_000, 2) . 'B' : number_format($totalPaid / 1_000_000, 2) . 'M';

            $event = ['description' => "Paid $" . number_format($newDividend, 2) . "/share div (\${$totalPaidStr} total, " . number_format($yield, 2) . "% yield).", 'shock' => 0.0];
        } else {
            $stock->setLastDividend('0.00');
        }

        return ['dividend_per_share' => $newDividend, 'total_paid' => $totalPaid, 'event' => $event];
    }

    private function executeBuybacks(Stock $stock, float $treasury, float $targetOperatingCash, float $shares, float $currentPrice, float $currentPE, float $operatingBase, float $investedCapital, float $nopat, array $health, array &$macroState, float $actualTotalNetIncome = 0.0, float $retainedEarningsThisQuarter = 0.0): array
    {
        $excessCash = max(0.0, $treasury - $targetOperatingCash);
        $canEasilyCoverDebt = $excessCash > ((float) $stock->getTotalDebt() * 2.0);
        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        $isFinancial = \App\Data\Sectors::isFinancial($businessModel);

        if ($isFinancial) {
            $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$stock->getIndustry() ?? 'General']['equity_limit'] ?? 10.0;

            // Optimal leverage is structurally max(1.0, EquityLimit - 1.0). 
            // We allow buybacks up to a safe 0.5x overshoot before locking them out to preserve capital.
            $buybackLockoutThreshold = max(1.0, $equityLimit - 1.0) + 0.5;

            if ((float)$stock->getDebtToEquityRatio() > $buybackLockoutThreshold) {
                return ['new_shares' => $shares, 'total_cash_spent' => 0.0, 'event' => null];
            }
        }

        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
        $hoardStatus = $strategy->evaluateHoardingStatus($treasury, $targetOperatingCash, $operatingBase, (float) $stock->getTotalDebt());
        $excessCash = $hoardStatus['excess_cash'];
        $isHoarder = $hoardStatus['is_hoarder'];
        $isMegaHoarder = $hoardStatus['is_mega_hoarder'];

        $archetypeStrategy = \App\Data\CeoArchetypes::getStrategy($stock->getCeoArchetype());

        $isLiquidityCrisis = $health['interest_coverage'] < 1.0;
        $modelThresholds = \App\Data\Sectors::getModelThresholds($businessModel);
        $minBuybackIcr = $modelThresholds['buyback_min_icr'];

        if ($isLiquidityCrisis || (!$isHoarder && (($health['wants_to_paydown_debt'] && !$canEasilyCoverDebt) || $health['interest_coverage'] < $minBuybackIcr))) {
            return ['new_shares' => $shares, 'total_cash_spent' => 0.0, 'event' => null];
        }

        $totalCashSpent = 0.0;
        $event = null;

        if ($isFinancial) {
            $trueReturn = (float)$stock->getTotalEquity() > 0 ? ($actualTotalNetIncome / (float)$stock->getTotalEquity()) * 4.0 : 0.0;
            $hurdleRate = $health['cost_of_equity'] ?? 0.10;
        } else {
            $trueReturn = $investedCapital > 0 ? ($nopat / $investedCapital) * 4.0 : 0.0;
            $hurdleRate = $health['wacc'];
        }
        $economicSpread = $trueReturn - $hurdleRate;

        $fairValuePE = $this->mathUtility->calculateIntrinsicFairValuePE($hurdleRate, $trueReturn, 0.02);

        if (($economicSpread > 0.02 && $currentPE < ($fairValuePE + 3.0)) || $isHoarder) {
            $maxWillingSpend = $strategy->calculateMaxBuybackSpend($excessCash, $retainedEarningsThisQuarter, $isMegaHoarder);

            $marketCap = $shares * max($currentPrice, 0.01);
            $maxRegulatorySpend = $marketCap * ($isMegaHoarder ? 0.075 : ($isHoarder ? 0.05 : 0.015));

            // CAPITAL STRUCTURE MAINTENANCE: 
            // If the company is under-leveraged, they can aggressively deploy excess cash to buy back stock.
            $currentDebtRatio = ((float) $stock->getTotalDebt()) / max(1.0, (float) $stock->getTotalEquity());
            $isUnderLeveraged = $health['is_under_leveraged'] ?? false;

            if ($isUnderLeveraged && $excessCash > 0) {
                $maxWillingSpend = max($maxWillingSpend, $excessCash * 0.50);
                $maxRegulatorySpend = max($maxRegulatorySpend, $marketCap * 0.05); // Allow 5% of market cap per quarter for recapitalization
            }

            $absoluteMaxSpend = min($maxWillingSpend, $maxRegulatorySpend);

            // SECURITY MEASURE: Do not allow buybacks to drive Book Equity below 0 (causes simulation math failure).
            $currentEquity = (float) $stock->getTotalEquity();
            $maxEquitySpend = max(0.0, $currentEquity * 0.50); // Never spend more than 50% of remaining equity
            $absoluteMaxSpend = min($absoluteMaxSpend, $maxEquitySpend);

            $valuationDiscount = max(0.0, ($fairValuePE - $currentPE) / max(1.0, $fairValuePE));
            // Hoarders ignore valuation discounts and always buy aggressively
            $aggression = $isMegaHoarder ? 1.0 : min(1.0, 0.50 + $valuationDiscount);
            $aggression = $archetypeStrategy->modifyBuybackAggression($aggression);

            // Hoarders bypass the random execution dampener
            $actualSpend = $absoluteMaxSpend * $aggression * ($isHoarder ? 1.0 : (mt_rand(50, 100) / 100.0));
            $sharesRepurchased = (int) floor($actualSpend / max($currentPrice, 0.01));

            if ($sharesRepurchased > 0) {
                $totalCashSpent = $sharesRepurchased * max($currentPrice, 0.01);
                $shares -= $sharesRepurchased;
                $stock->setSharesOutstanding((string) $shares);

                $pctRetired = ($sharesRepurchased / ($shares + $sharesRepurchased)) * 100;
                $event = [
                    'description' => "Bought back " . number_format($sharesRepurchased) . " shares.",
                    'shock' => $pctRetired * 0.5
                ];
            }
        }

        return ['new_shares' => $shares, 'total_cash_spent' => $totalCashSpent, 'event' => $event];
    }
}
