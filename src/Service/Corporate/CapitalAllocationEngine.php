<?php

declare(strict_types=1);

namespace App\Service\Corporate;

use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Macro\MacroEngine;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\DTO\CapitalAllocationContext;
use App\DTO\MacroStateDTO;

/**
 * Service responsible for returning cash to shareholders.
 * Handles Dividends and Share Repurchases (Buybacks).
 */
class CapitalAllocationEngine
{
    // --- Dividend Liquidity ---
    /** Operating cash buffer multiple (1.5x) below which coverage under the crisis threshold omits the dividend. */
    private const CASH_BUFFER_SAFETY_MULT = 1.5;

    // --- Capital Ratio Targeting ---
    /** Share of the gap to its target capital ratio a bank closes each year: large US BHCs adjust 28-41% a year (Berger, DeYoung, Flannery, Lee & Öztekin 2008). */
    public const CAPITAL_TARGET_ADJUSTMENT_SPEED = 0.35;

    // --- Leverage Targeting ---
    /** Share of the gap to its target leverage a firm closes each year: about a third (Flannery & Rangan 2006). */
    public const LEVERAGE_TARGET_ADJUSTMENT_SPEED = 0.34;

    public function __construct(
        private CorporateLedgerService $corporateLedgerService,
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
        MacroStateDTO $macroState,
        float $actualTotalNetIncome = 0.0,
        float $stockCompensation = 0.0,
        ?float $openingCapitalRatio = null
    ): array {
        $ctx = new CapitalAllocationContext(
            $stock,
            $macroState,
            $actualAnnualEps,
            $quarterlyFcfPerShare,
            $currentPrice,
            $sharesOutstanding,
            $actualTotalNetIncome,
            $stockCompensation
        );
        $ctx->openingCapitalRatio = $openingCapitalRatio;

        $this->initializeContext($ctx);
        $this->executeDividends($ctx);
        $this->executeCorporateStrategy($ctx);
        $this->executeBuybacks($ctx);
        $this->finalizeLiquidity($ctx);

        return [
            'new_shares' => $ctx->newShares,
            'dividend_paid' => $ctx->newDividend,
            'total_paid' => $ctx->totalPaid,
            'total_cash_spent' => $ctx->totalCashSpent,
            'equity_raised' => $ctx->equityRaised,
            'bank_apy' => $ctx->bankApy,
            'organic_capex' => $ctx->organicCapex,
            'loan_originations' => $ctx->loanOriginations,
            'asset_sale_proceeds' => $ctx->assetSaleProceeds,
            'asset_sale_loss' => $ctx->assetSaleLoss,
            'events' => $ctx->events
        ];
    }

    private function initializeContext(CapitalAllocationContext $ctx): void
    {
        $stock = $ctx->stock;
        
        $ctx->quarterlyEps = $ctx->actualAnnualEps / 4.0;
        $ctx->trailingQuarterlyEps = (float) $stock->getEarningsPerShare() / 4.0;
        $ctx->quarterlyNetIncome = $ctx->actualTotalNetIncome != 0.0 ? $ctx->actualTotalNetIncome : ($ctx->quarterlyEps * $ctx->sharesOutstanding);
        
        $ctx->currentTreasury = (float) $stock->getCorporateTreasury();
        $ctx->wholesaleDebt = (float) $stock->getWholesaleDebt();
        $ctx->customerDeposits = (float) $stock->getCustomerDeposits();
        
        $ctx->operatingBase = $this->corporateMetrics->calculateOperatingBase((float) $stock->getTotalRevenue(), (float) $stock->getTotalEquity());
        $ctx->investedCapital = $stock->getInvestedCapital();
        
        $ctx->industry = $stock->getIndustry() ?: 'General';
        $ctx->businessModel = \App\Data\Sectors::businessModelFor($ctx->industry);
        $ctx->isFinancial = \App\Data\Sectors::isFinancial($ctx->businessModel);
        $ctx->strategy = \App\Data\Sectors::getBusinessModelStrategy($ctx->businessModel);
        
        // Coverage, cost of capital and the hurdle that gate distributions and borrowing are read off what the
        // firm actually earned over the last twelve months, as its lenders measure it. The structural margin
        // only moves through reinvestment decay, so on it a firm in a margin collapse kept paying a dividend on
        // coverage it no longer had; one annualized quarter instead flipped the verdict with the seasons.
        $ctx->health = $this->debtEngine->analyzeTrailingDebtHealth($stock, $ctx->macroState);
        
        $ebit = $ctx->health->rawMetrics->ebit ?? 0.0;
        $nopat = $ebit > 0 ? $ebit * (1.0 - $ctx->macroState->corporateTaxRate) : $ebit;
        $ctx->quarterlyNopat = $nopat / 4.0;
        
        $totalFcfGenerated = $ctx->quarterlyFcfPerShare * $ctx->sharesOutstanding;
        $ctx->newTreasury = $ctx->currentTreasury + $totalFcfGenerated;
    }

    private function executeDividends(CapitalAllocationContext $ctx): void
    {
        $stock = $ctx->stock;
        
        // Bertrand & Schoar (2003): payout policy carries a persistent manager fixed effect. An empire
        // builder retains what a steward would distribute, from the same balance sheet.
        $targetPayout = $stock->getPolicyPayoutRatio();
        $speed = (float) $stock->getDividendSpeed();
        $lastDividend = (float) $stock->getLastDividend();
        $isAristocrat = $stock->isDividendAristocrat();

        $customDepreciation = (float) $stock->getDepreciationRate();
        $depRate = $customDepreciation > 0.0 ? $customDepreciation : $this->corporateMetrics->getIndustryDepreciationRate($ctx->industry);

        $trueReturn = (float) $ctx->strategy->getTrueReturn($stock);
        if ($trueReturn === 0.0) {
            $trueReturn = $ctx->strategy->calculateEconomicReturn($stock, $ctx->strategy->getReturnBasisIncome($stock, $ctx->quarterlyNopat, $ctx->actualTotalNetIncome), $ctx->investedCapital);
        }

        // Life-Cycle Payout Target Expansion (DeAngelo & DeAngelo 2006 / Jensen 1986)
        // As a firm approaches market saturation, internal reinvestment slows and target payout scales toward cash cow levels.
        $saturationSeverity = $this->resolveRedeploymentSeverity($ctx, $trueReturn);
        $effectiveTargetPayout = $this->corporateMetrics->calculateLifeCyclePayoutRatio($targetPayout, $saturationSeverity);

        // Life-cycle gate (Dickinson 2011): a pre-profit firm funding itself with outside capital does not
        // initiate distributions; every dollar goes back into the business until operations turn cash positive.
        $lastStage = $stock->getLifecycleStage();
        if ($lastStage !== null && !$lastStage->initiatesDistributions()) {
            $effectiveTargetPayout = 0.0;
        }

        // Lintner (1956) and Fama & Babiak (1968) set the dividend against the year's earnings: the target moves
        // with trailing-twelve-month EPS, so one strong quarter is not a raise the floor then locks in, and one
        // weak quarter is not a cut.
        $sustainableBase = $ctx->strategy->getSustainableDividendBase($stock, $ctx->trailingQuarterlyEps, $ctx->investedCapital, $depRate);
        if ($sustainableBase <= 0.0 && $ctx->quarterlyFcfPerShare > 0.0) {
            $sustainableBase = min($ctx->quarterlyFcfPerShare, $lastDividend / max(0.01, $effectiveTargetPayout));
        }
        $calculatedTarget = $sustainableBase > 0 ? ($sustainableBase * $effectiveTargetPayout) : 0.0;
        // Managers are reluctant to cut (Lintner 1956): a payer holds its dividend while earnings fall, and cuts by
        // choice only after a loss year (DeAngelo, DeAngelo & Skinner 1992). One known for an unbroken record of
        // increases does not cut by choice even then; either one still cuts when the caps below force it to.
        $isLossYear = $ctx->trailingQuarterlyEps <= 0.0;
        $holdsDividend = $isAristocrat || !$isLossYear;
        $targetDividend = $holdsDividend ? max($calculatedTarget, $lastDividend) : $calculatedTarget;

        $isRegulatoryDividendHalt = false;
        
        $regulatoryCap = $ctx->strategy->getRegulatoryDividendCap($stock, $ctx->newTreasury, $ctx->macroState);
        if ($regulatoryCap !== null) {
            if ($regulatoryCap <= 0.0) {
                $isRegulatoryDividendHalt = true;
            } else {
                $targetDividend = min($targetDividend, $sustainableBase * min($effectiveTargetPayout, $regulatoryCap));
            }
        }

        $minOperatingCash = $ctx->strategy->calculateMinOperatingCash($ctx->operatingBase, (float) $stock->getCustomerDeposits(), (float) $stock->getWholesaleDebt());
        $hasCashBuffer = $ctx->newTreasury > ($minOperatingCash * self::CASH_BUFFER_SAFETY_MULT);

        $crisisThreshold = $ctx->strategy->getDividendCrisisIcr();
        $isLiquidityCrisis = $ctx->health->interestCoverage < 1.0 || ($ctx->health->interestCoverage < $crisisThreshold && !$hasCashBuffer);

        // Falling earnings cut the dividend through the target payout, at the firm's own adjustment speed: cuts
        // follow losses (DeAngelo, DeAngelo & Skinner 1992), not a return below the hurdle on earnings that still
        // cover it, and the cash and legal caps below bind when the treasury cannot pay. Only a liquidity crisis
        // or a regulator stops the dividend outright.
        if ($isLiquidityCrisis || $isRegulatoryDividendHalt) {
            $targetDividend = 0.0;
            $speed = 1.0;
        }

        // The dividend leg of the same restricted-payments clause. A breach freezes the distribution where
        // it stands rather than cutting it: what lenders withhold consent for is an INCREASE in payments
        // while the firm is out of compliance. min() keeps a deeper cut, from falling earnings or a crisis.
        if (!$ctx->health->hasLeverageHeadroom) {
            $targetDividend = min($targetDividend, $lastDividend);
        }

        if ($saturationSeverity > 0.20 && $calculatedTarget > $lastDividend) {
            $speed = min(1.0, $speed + ($saturationSeverity * 0.10));
        }

        $ctx->newDividend = max(0.0, $lastDividend + ($speed * ($targetDividend - $lastDividend)));

        // A pass-through keeps its tax status only by distributing its taxable income, so while the quarter is
        // profitable no payout preference, smoothing or crisis omission above can take the dividend below it.
        // Taxable income is net income: a REIT's EPS here is FFO, depreciation added back, which the rule
        // does not reach.
        $taxableIncomePerShare = max(0.0, $ctx->quarterlyNetIncome) / max(1.0, $ctx->sharesOutstanding);
        $requiredDividend = $taxableIncomePerShare * $ctx->strategy->getMinimumDistributionRatio();
        $ctx->newDividend = max($ctx->newDividend, $requiredDividend);

        // Taxable income is struck on historic-cost depreciation, so when replacing the property costs more than
        // the book charge the trust owes a distribution its cash flow does not cover. It borrows the gap at its
        // market rate while its lenders will fund it, as REITs draw their lines to pay required distributions.
        // A payer holding its dividend does the same: managers raise external funds before they cut (Brav, Graham,
        // Harvey & Michaely 2005), so a quarter's cash shortfall is not a cut while lenders will fund it.
        $heldDividend = $holdsDividend ? min($ctx->newDividend, $lastDividend) : 0.0;
        $fundedDividend = max($requiredDividend, $heldDividend);
        // Measured from the operating floor itself, so a treasury below it borrows enough to pay: the cash cap
        // below reads the same floor.
        $distributionShortfall = ($fundedDividend * $ctx->sharesOutstanding) - ($ctx->newTreasury - $minOperatingCash);
        if ($fundedDividend > 0.0 && $distributionShortfall > 0.0 && $ctx->health->canIssueDebt && $ctx->health->hasLeverageHeadroom) {
            $this->debtEngine->issueDebt($stock, $distributionShortfall, $ctx->health->rawMetrics->currentMarketRate ?? ($ctx->macroState->yield5yEma + (float) $stock->getCreditSpread()));
            $ctx->wholesaleDebt = (float) $stock->getWholesaleDebt();
            $ctx->newTreasury += $distributionShortfall;

            if (TreasuryEngine::isNewsworthy($ctx->stock, $distributionShortfall)) {
                $purpose = $requiredDividend >= $heldDividend ? 'its required REIT distribution' : 'its dividend';
                $ctx->events[] = ['description' => "Borrowed \$" . number_format($distributionShortfall / 1_000_000_000, 2) . "B to fund {$purpose}.", 'shock' => 0.0];
            }
        }

        $usableCash = max(0.0, $ctx->newTreasury - $minOperatingCash);
        $distributableSurplus = max(0.0, (float) $stock->getRetainedEarnings() + ($ctx->quarterlyEps * $ctx->sharesOutstanding));

        $maxCashDividendPerShare = $ctx->sharesOutstanding > 0 ? ($usableCash / $ctx->sharesOutstanding) : 0.0;
        $maxLegalDividendPerShare = $ctx->sharesOutstanding > 0 ? ($distributableSurplus / $ctx->sharesOutstanding) : 0.0;

        $ctx->newDividend = min($ctx->newDividend, $maxCashDividendPerShare, $maxLegalDividendPerShare);

        // Any cut ends the record the aristocrat was known for, whatever forced it. Read at the four decimals a
        // dividend is declared and stored in, so float dust from a funded shortfall is not a cut.
        if ($isAristocrat && round($ctx->newDividend, 4) < $lastDividend) {
            $stock->setDividendAristocrat(false);
            $ctx->events[] = ['description' => 'Cut its dividend, ending its record of dividend increases.', 'shock' => 0.0];
        }
        // Every share outstanding is paid, so the treasury debit below covers the whole register. Only
        // player-held shares are credited to a cash balance by the ledger service; the remainder is the
        // notional public float and simply leaves the company. The two are not meant to reconcile.
        $ctx->totalPaid = $ctx->newDividend * $ctx->sharesOutstanding;

        if ($ctx->newDividend > 0.0) {
            $this->corporateLedgerService->processDividendPayment($stock, $ctx->newDividend, new \DateTime());

            $stock->setLastDividend((string) $ctx->newDividend);
            $yield = (($ctx->newDividend * 4) / max($ctx->currentPrice, 0.01)) * 100;
            $totalPaidStr = $ctx->totalPaid >= 1_000_000_000 ? number_format($ctx->totalPaid / 1_000_000_000, 2) . 'B' : number_format($ctx->totalPaid / 1_000_000, 2) . 'M';

            $ctx->events[] = ['description' => "Paid $" . number_format($ctx->newDividend, 2) . "/share div (\${$totalPaidStr} total, " . number_format($yield, 2) . "% yield).", 'shock' => 0.0];
        } else {
            $stock->setLastDividend('0.00');
        }

        $ctx->newTreasury -= $ctx->totalPaid;
        $ctx->retainedEarningsThisQuarter = max(0.0, $ctx->quarterlyNetIncome - $ctx->totalPaid);
    }

    private function executeCorporateStrategy(CapitalAllocationContext $ctx): void
    {
        $this->treasuryEngine->executeCorporateStrategy($ctx);
        
        // The DISCRETIONARY cash target — the buffer a manager chooses to run, not the solvency floor, which
        // stays exactly where the model puts it. Every hoarding test downstream measures against this, so
        // the fortress is no longer detected as defective for holding the reserves that define it.
        $ctx->targetOperatingCash = $ctx->stock->getManagementProfile()->appliedTargetCash(
            $ctx->strategy->calculateTargetOperatingCash($ctx->operatingBase, (float) $ctx->stock->getCustomerDeposits(), (float) $ctx->stock->getWholesaleDebt())
        );
        $ctx->excessCash = max(0.0, $ctx->newTreasury - $ctx->targetOperatingCash);
        
        if ($ctx->actualAnnualEps > 0) {
            $ctx->currentPE = $ctx->currentPrice / $ctx->actualAnnualEps;
        } else {
            $actualRevenue = (float) $ctx->stock->getTotalRevenue();
            $salesPerShare = $ctx->sharesOutstanding > 0 ? $actualRevenue / $ctx->sharesOutstanding : 1.0;
            $priceToSales = $salesPerShare > 0 ? $ctx->currentPrice / $salesPerShare : 1.0;
            $structuralAfterTaxMargin = max(0.01, (float) $ctx->stock->getOperatingMargin() * (1.0 - $ctx->macroState->corporateTaxRate));
            $ctx->currentPE = $priceToSales * (1.0 / $structuralAfterTaxMargin);
        }
    }

    /**
     * How much of the firm's capital has nowhere to go this quarter, as the life-cycle payout expansion and
     * the buyback gate both read it.
     *
     * Saturation is the general case: a firm whose capital has outgrown its market earns less on the next
     * unit of it. A model may also know its capital is idle for a reason saturation cannot see — an
     * underwriter declining to write at the rate on offer holds surplus behind a book it is not writing —
     * and the larger of the two is what management is actually looking at when it decides what to hand back.
     */
    private function resolveRedeploymentSeverity(CapitalAllocationContext $ctx, float $trueReturn): float
    {
        $evaluationCapital = $ctx->strategy->getEvaluationCapital((float) $ctx->stock->getTotalEquity(), $ctx->investedCapital);
        $saturationPenalty = $this->corporateMetrics->calculateMarketSaturationPenalty($ctx->stock, $evaluationCapital, $ctx->macroState);

        return max(
            $this->corporateMetrics->calculateSaturationSeverity($saturationPenalty, $trueReturn),
            $ctx->strategy->getUndeployableCapitalShare($ctx->stock, $ctx->macroState, $this->mathUtility)
        );
    }

    private function executeBuybacks(CapitalAllocationContext $ctx): void
    {
        if ($ctx->businessModel === 'reit') {
            $ctx->newShares = $ctx->sharesOutstanding;
            $ctx->totalCashSpent = 0.0;
            return;
        }

        $stock = $ctx->stock;
        $canEasilyCoverDebt = $ctx->excessCash > ((float) $stock->getTotalDebt() * 2.0);

        if ($ctx->strategy->checkBuybackRegulatoryLockout($stock, $ctx->newTreasury, $ctx->macroState)) {
            $ctx->newShares = $ctx->sharesOutstanding;
            return;
        }

        // Restricted payments covenant: block share buybacks when leverage headroom is breached.
        if (!$ctx->health->hasLeverageHeadroom) {
            $ctx->newShares = $ctx->sharesOutstanding;
            return;
        }

        $manager = $stock->getManagementProfile();
        $hoardStatus = $ctx->strategy->evaluateHoardingStatus($ctx->newTreasury, $ctx->targetOperatingCash, $manager->appliedHoardingBase($ctx->operatingBase), (float) $stock->getTotalDebt());
        $excessCash = $hoardStatus['excess_cash'];
        $isHoarder = $hoardStatus['is_hoarder'];
        $isMegaHoarder = $hoardStatus['is_mega_hoarder'];

        $isUnderLeveraged = $ctx->health->isUnderLeveraged ?? false;

        $isLiquidityCrisis = $ctx->health->interestCoverage < 1.0;
        $minBuybackIcr = $ctx->strategy->getBuybackMinIcr();

        $capitalSurplusReturn = $this->resolveCapitalSurplusReturn($ctx);
        $recapitalization = $this->resolveRecapitalizationRepurchase($ctx);

        if ($isLiquidityCrisis || (!$isHoarder && (($ctx->health->wantsToPaydownDebt && !$canEasilyCoverDebt) || $ctx->health->interestCoverage < $minBuybackIcr))) {
            // A balance-sheet lender's distributions answer to its capital ratio, not to interest coverage.
            if (!($ctx->strategy->isFinancial() && ($isUnderLeveraged || $capitalSurplusReturn > 0.0))) {
                $ctx->newShares = $ctx->sharesOutstanding;
                return;
            }
        }

        $trueReturn = (float) $ctx->strategy->getTrueReturn($stock);
        if ($trueReturn === 0.0) {
            $trueReturn = $ctx->strategy->calculateEconomicReturn($stock, $ctx->strategy->getReturnBasisIncome($stock, $ctx->quarterlyNopat, $ctx->actualTotalNetIncome), $ctx->investedCapital);
        }
        $hurdleRate = $ctx->strategy->getHurdleRate($ctx->health);
        $economicSpread = $trueReturn - $hurdleRate;

        $saturationSeverity = $this->resolveRedeploymentSeverity($ctx, $trueReturn);

        // The multiple values equity, so it is struck at the cost of equity on the return on equity, as the
        // market strikes it (MarketEngine); the spread above stays ROIC against the hurdle, an EVA test.
        $equityRates = $ctx->strategy->getEquityValuationRates($stock, $trueReturn, $ctx->health, $ctx->macroState->corporateTaxRate);
        $fairValuePE = $this->mathUtility->calculateManagementFairValuePE(
            $equityRates['costOfEquity'],
            $equityRates['equityReturn'],
            $ctx->strategy->getFadedSecularGrowthRate($stock, $ctx->macroState->totalTime),
            $ctx->macroState->outputGap,
            $ctx->health->leveredBeta,
            $ctx->macroState->inflation,
            $ctx->strategy->getMoatSpread(),
            \App\Data\Sectors::baselineIndustryPe($stock->getIndustry()),
            (float) ($stock->getAccrualsRatio() ?? 0.0),
            $stock->getPolicyPayoutRatio(),
            $ctx->macroState->yield10yEma
        );

        $ctx->newShares = $ctx->sharesOutstanding;

        // A share retired below intrinsic book raises every remaining share's book value, whatever the
        // business does next. Zero for an operating company, whose case is the earnings test above; for a
        // closed-end structure that test can never find it, because a trust's economic spread sits at zero
        // and the discount inflates the very P/E being compared.
        $repurchaseAccretion = $ctx->strategy->resolveRepurchaseAccretion($stock, $ctx->currentPrice);
        $isTradingBelowBook = $repurchaseAccretion >= FinancialConstants::MIN_ACCRETIVE_REPURCHASE_DISCOUNT;

        if (($economicSpread > 0.02 && $ctx->currentPE < ($fairValuePE + 3.0)) || $isHoarder || $isUnderLeveraged || $capitalSurplusReturn > 0.0 || $saturationSeverity > 0.20 || $isTradingBelowBook) {
            $maxWillingSpend = $ctx->strategy->calculateMaxBuybackSpend($excessCash, $ctx->retainedEarningsThisQuarter, $isMegaHoarder);

            // Saturation Buyback Unlock: Mature firms distribute non-reinvestable excess cash
            if ($saturationSeverity > 0.05) {
                $saturationSpendRatio = $isMegaHoarder
                    ? FinancialConstants::BUYBACK_SPEND_MEGA_SATURATED_RATIO
                    : FinancialConstants::BUYBACK_SPEND_SATURATED_RATIO;
                $saturationWillingSpend = $excessCash * $saturationSpendRatio * $saturationSeverity;
                $maxWillingSpend = max($maxWillingSpend, $saturationWillingSpend);
            }

            // Bertrand & Schoar's payout fixed effect is over TOTAL distribution. Biasing the dividend alone
            // simply rerouted the cash: what a fortress withheld from the dividend piled up in the treasury
            // and came straight back out through this leg, so the firm that was supposed to retain ended up
            // distributing more than the steward. The recap floor below is deliberately left unbiased —
            // that is a capital-structure repair, not a distribution preference.
            $maxWillingSpend *= $manager->payoutBias();

            $marketCap = $ctx->sharesOutstanding * max($ctx->currentPrice, 0.01);
            $baseRegulatoryPct = $isMegaHoarder ? 0.075 : ($isHoarder ? 0.05 : 0.015);
            $effectiveRegulatoryPct = $baseRegulatoryPct + (FinancialConstants::MAX_REGULATORY_SPEND_SATURATED - $baseRegulatoryPct) * $saturationSeverity;
            $maxRegulatorySpend = $marketCap * $effectiveRegulatoryPct;

            if ($isUnderLeveraged || $capitalSurplusReturn > 0.0) {
                // An under-levered firm hands back its idle cash and retires the equity its recapitalization
                // replaces with debt, and an institution above its capital target returns the surplus it is
                // steering off. Both are funded from retained earnings even when idle excess cash is zero.
                $recapBudget = $isUnderLeveraged ? max($excessCash, $ctx->retainedEarningsThisQuarter, $recapitalization) : 0.0;
                $maxWillingSpend = max($maxWillingSpend, $recapBudget, $capitalSurplusReturn);
                $maxRegulatorySpend = max($maxRegulatorySpend, $marketCap * 0.05);
            }

            $absoluteMaxSpend = min($maxWillingSpend, $maxRegulatorySpend);

            $currentEquity = (float) $stock->getTotalEquity();
            $maxEquitySpend = max(0.0, $currentEquity * 0.50);
            $absoluteMaxSpend = min($absoluteMaxSpend, $maxEquitySpend);

            $minOperatingCash = $ctx->strategy->calculateMinOperatingCash($ctx->operatingBase, (float) $stock->getCustomerDeposits(), (float) $stock->getWholesaleDebt());

            // Borrowed only for the part of the repurchase the firm can make that its excess cash cannot pay for. A
            // treasury below its operating floor is refilled before any recapitalization: borrowing into it only
            // plugged the hole, and the repurchase it was raised for never happened.
            $recapitalization = $recapitalization > 0.0 && $ctx->newTreasury >= $minOperatingCash
                ? $this->fundRecapitalization($ctx, min($recapitalization, $absoluteMaxSpend), $excessCash)
                : 0.0;

            // Hard Solvency Constraint: Cannot spend more cash than physically available in treasury above min operating buffer
            $availableCashBuffer = max(0.0, $ctx->newTreasury - $minOperatingCash);
            $absoluteMaxSpend = min($absoluteMaxSpend, $availableCashBuffer);

            $valuationDiscount = max(
                $repurchaseAccretion,
                max(0.0, ($fairValuePE - $ctx->currentPE) / max(1.0, $fairValuePE))
            );
            $aggression = $isMegaHoarder ? 1.0 : min(1.0, 0.50 + $valuationDiscount);

            $actualSpend = $absoluteMaxSpend * $aggression * ($isHoarder ? 1.0 : (mt_rand(50, 100) / 100.0));
            // The capital return and the recapitalization are plans, not opportunistic purchases: price and
            // appetite do not scale them.
            $actualSpend = max($actualSpend, min(max($capitalSurplusReturn, $recapitalization), $absoluteMaxSpend));
            $sharesRepurchased = (int) floor($actualSpend / max($ctx->currentPrice, 0.01));
            
            // Cap buybacks at 95% of currently outstanding shares per quarter.
            $maxSharesToBuy = (int) floor($ctx->sharesOutstanding * 0.95);
            $sharesRepurchased = min($sharesRepurchased, $maxSharesToBuy);

            if ($sharesRepurchased > 0) {
                $ctx->totalCashSpent = $sharesRepurchased * max($ctx->currentPrice, 0.01);
                $newSharesVal = max(1.0, (float) $stock->getSharesOutstanding() - $sharesRepurchased);
                $sharesStr = (string) $newSharesVal;
                $stock->setSharesOutstanding($sharesStr);
                $ctx->newShares = $newSharesVal;

                // The repurchase reaches the price as what it is — shares bought in the market — through the
                // order-flow channel and the 10b-18 pacing in StockTracker, not as a shock struck here. The
                // old "half the percentage retired" was invented, and it charged the price for a quarter's
                // buying in one tick with no slippage while a player buying the same notional paid both.
                $stock->addCorporateFlowBacklog((float) $sharesRepurchased);

                $ctx->events[] = [
                    'description' => "Bought back " . number_format($sharesRepurchased) . " shares.",
                    'shock' => 0.0
                ];
            }
        }
        
        $ctx->newTreasury -= $ctx->totalCashSpent;
    }

    /**
     * The repurchase that holds an institution's book capital ratio to one quarter's partial adjustment toward
     * its own target. Zero for a bank that opened below target: it rebuilds by retaining, under the regulatory
     * dividend cap that already governs it.
     */
    private function resolveCapitalSurplusReturn(CapitalAllocationContext $ctx): float
    {
        $target = $ctx->strategy->getTargetCapitalRatio($ctx->stock, $ctx->macroState);
        if ($target === null) {
            return 0.0;
        }

        $stock = $ctx->stock;
        $openingEquity = (float) $stock->getTotalEquity();
        $equity = $this->resolvePreBuybackEquity($ctx);
        // The earning-asset ledger already carries this quarter's deployment; the treasury is still the opening balance.
        $assets = $stock->getTotalAssets() - max(0.0, (float) $stock->getCorporateTreasury()) + max(0.0, $ctx->newTreasury);
        $openingRatio = $ctx->openingCapitalRatio ?? ($assets > 0.0 ? $openingEquity / $assets : 0.0);

        return self::capitalTargetRepurchase($equity, $assets, $target, $openingRatio);
    }

    /**
     * Partial adjustment toward a target capital ratio (Berger et al. 2008): the quarter's change in the ratio,
     * this quarter's earnings included, closes the quarterly share of the gap it opened on. The repurchase B
     * returns what would carry it past that, and retiring shares takes the cash with them, so (E - B) / (A - B)
     * is the ratio the quarter ends on.
     */
    public static function capitalTargetRepurchase(float $equity, float $assets, float $targetRatio, float $openingRatio): float
    {
        if ($equity <= 0.0 || $assets <= $equity || $openingRatio < $targetRatio) {
            return 0.0;
        }

        $endRatio = $openingRatio - (self::quarterlyAdjustmentSpeed(self::CAPITAL_TARGET_ADJUSTMENT_SPEED) * ($openingRatio - $targetRatio));
        if ($equity / $assets <= $endRatio) {
            return 0.0;
        }

        return ($equity - ($endRatio * $assets)) / (1.0 - $endRatio);
    }

    /**
     * Partial adjustment toward a target debt-to-equity ratio (Flannery & Rangan 2006): the quarter's change in the
     * ratio, this quarter's retained earnings included, closes the quarterly share of the gap it opened on. A
     * repurchase R financed with debt moves the ratio to (D + R) / (E - R), so R = (L E - D) / (1 + L) is the one
     * that ends the quarter on the ratio L.
     */
    public static function leverageTargetRepurchase(float $equity, float $debt, float $targetRatio, float $openingRatio): float
    {
        if ($equity <= 0.0 || $openingRatio >= $targetRatio) {
            return 0.0;
        }

        $endRatio = $openingRatio + (self::quarterlyAdjustmentSpeed(self::LEVERAGE_TARGET_ADJUSTMENT_SPEED) * ($targetRatio - $openingRatio));

        return max(0.0, (($endRatio * $equity) - $debt) / (1.0 + $endRatio));
    }

    /** The quarterly share of a gap that compounds to closing the annual share of it in a year. */
    private static function quarterlyAdjustmentSpeed(float $annualSpeed): float
    {
        return 1.0 - ((1.0 - $annualSpeed) ** 0.25);
    }

    /**
     * The repurchase that carries an under-levered operating company one quarter's partial adjustment toward its
     * leverage target. A leveraged recapitalization exchanges debt for equity (Denis & Denis 1993): borrowing the
     * cash to hold it recapitalized nothing, and left the idle proceeds for the capex gate to spend on plant a
     * saturated firm had no use for. A lender levers up by lending, through TreasuryEngine's balance-sheet
     * expansion, so a financial recapitalizes nothing here.
     */
    private function resolveRecapitalizationRepurchase(CapitalAllocationContext $ctx): float
    {
        if ($ctx->strategy->isFinancial() || !$ctx->health->isUnderLeveraged) {
            return 0.0;
        }

        $leaseLiability = $this->corporateMetrics->calculateLeaseLiability($ctx->health->rawMetrics->revenue, $ctx->strategy->getLeaseIntensity());

        return self::leverageTargetRepurchase(
            $this->resolvePreBuybackEquity($ctx),
            (float) $ctx->stock->getTotalDebt() + $leaseLiability,
            $ctx->health->leverageTarget,
            $ctx->health->debtToEquity
        );
    }

    /** Book equity once this quarter's earnings, stock compensation and dividend are through it. */
    private function resolvePreBuybackEquity(CapitalAllocationContext $ctx): float
    {
        return (float) $ctx->stock->getTotalEquity() + $ctx->quarterlyNetIncome + $ctx->stockCompensation - $ctx->totalPaid;
    }

    /**
     * Funds a recapitalization repurchase from excess cash first and borrows the rest while the firm's lenders
     * will fund it, up to its debt capacity. Returns the repurchase the firm can pay for.
     */
    private function fundRecapitalization(CapitalAllocationContext $ctx, float $repurchase, float $excessCash): float
    {
        $shortfall = $repurchase - $excessCash;
        if ($shortfall <= 0.0) {
            return $repurchase;
        }

        $stock = $ctx->stock;
        $metrics = $ctx->health->rawMetrics;
        $borrowing = 0.0;
        if ($ctx->health->canIssueDebt && $ctx->health->hasLeverageHeadroom && !$ctx->health->isSevereNegativeCarry) {
            $capacity = $ctx->strategy->calculateDebtExpansionCapacity($this->resolvePreBuybackEquity($ctx), (float) $stock->getTotalDebt(), $ctx->wholesaleDebt, $ctx->health, $metrics->currentMarketRate, $metrics->ebit, $metrics->depreciation);
            $borrowing = max(0.0, min($shortfall, $capacity));
        }

        if ($borrowing > 0.0) {
            $this->debtEngine->issueDebt($stock, $borrowing, $metrics->currentMarketRate);
            $ctx->wholesaleDebt = (float) $stock->getWholesaleDebt();
            $ctx->newTreasury += $borrowing;
            $ctx->debtIssued += $borrowing;
            $ctx->debtActionTaken = true;
            $ctx->recapActionTaken = true;

            if (TreasuryEngine::isNewsworthy($ctx->stock, $borrowing)) {
                $ctx->events[] = ['description' => "Issued \$" . number_format($borrowing / 1_000_000_000, 2) . "B in bonds for recapitalization."];
            }
        }

        return $repurchase - $shortfall + $borrowing;
    }

    /**
     * The most an institution can borrow and still hold its target capital ratio. Borrowed cash lands on the
     * asset side, so E / (A + D) = target caps the new debt D at E / target - A.
     */
    public static function capitalTargetBorrowingCapacity(float $equity, float $assets, float $targetRatio): float
    {
        if ($targetRatio <= 0.0 || $equity <= 0.0) {
            return 0.0;
        }

        return max(0.0, ($equity / $targetRatio) - $assets);
    }

    private function finalizeLiquidity(CapitalAllocationContext $ctx): void
    {
        $this->treasuryEngine->finalizeLiquidity($ctx);
    }
}
