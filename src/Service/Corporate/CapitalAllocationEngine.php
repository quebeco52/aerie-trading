<?php

declare(strict_types=1);

namespace App\Service\Corporate;

use App\DTO\CapitalAllocationContext;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\CreditRisk;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use App\Service\Math\Valuation;

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
    /**
     * Share of the gap to its target capital ratio a bank closes each year: large US BHCs adjust 28-41% a year (Berger,
     * DeYoung, Flannery, Lee & Öztekin 2008).
     */
    public const CAPITAL_TARGET_ADJUSTMENT_SPEED = 0.35;

    // --- Leverage Targeting ---
    /** Share of the gap to its target leverage a firm closes each year: about a third (Flannery & Rangan 2006). */
    public const LEVERAGE_TARGET_ADJUSTMENT_SPEED = 0.34;

    // --- Capital Allocation & Life-Cycle Physics ---
    /**
     * Discount to intrinsic book at which a board starts repurchasing for the accretion itself. Below this the gap is
     * inside the noise a board would act on.
     */
    public const MIN_ACCRETIVE_REPURCHASE_DISCOUNT = 0.05;
    /** Fraction of excess cash allocated to quarterly buybacks for saturated firms (50%). */
    public const BUYBACK_SPEND_SATURATED_RATIO = 0.50;
    /** Fraction of excess cash allocated to quarterly buybacks for mega-hoarder saturated firms (70%). */
    public const BUYBACK_SPEND_MEGA_SATURATED_RATIO = 0.70;
    /** Maximum market cap percentage (15%) a fully saturated firm can repurchase in a single quarter. */
    public const MAX_REGULATORY_SPEND_SATURATED = 0.15;
    /**
     * Least share of its buyback capacity a non-hoarding board spends in a quarter, drawn uniformly up to all of it (no
     * published calibration).
     */
    public const BUYBACK_APPETITE_MIN_SHARE = 0.50;

    public function __construct(
        private CorporateLedgerService $corporateLedgerService,
        private CorporateMetrics $corporateMetrics,
        private DebtEngine $debtEngine,
        private MathUtility $mathUtility,
        private TreasuryEngine $treasuryEngine,
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
        ?float $openingCapitalRatio = null,
        ?float $expansionBudget = null,
    ): array {
        $ctx = new CapitalAllocationContext(
            $stock,
            $macroState,
            $actualAnnualEps,
            $quarterlyFcfPerShare,
            $currentPrice,
            $sharesOutstanding,
            $actualTotalNetIncome,
            $stockCompensation,
            $expansionBudget,
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
            'events' => $ctx->events,
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
        $ctx->businessModel = \App\Data\Company\Sectors::businessModelFor($ctx->industry);
        $ctx->isFinancial = \App\Data\Company\Sectors::isFinancial($ctx->businessModel);
        $ctx->strategy = \App\Data\Company\Sectors::getBusinessModelStrategy($ctx->businessModel);

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

        $trueReturn = $ctx->strategy->getTrueReturn($stock);

        // Life-Cycle Payout Target Expansion (DeAngelo & DeAngelo 2006 / Jensen 1986) As a firm approaches market
        // saturation, internal reinvestment slows and target payout scales toward cash cow levels.
        $saturationSeverity = $this->resolveRedeploymentSeverity($ctx, $trueReturn);
        $effectiveTargetPayout = $this->corporateMetrics->calculateLifeCyclePayoutRatio($targetPayout, $saturationSeverity);
        // A tax on dividends shifts payout toward buybacks, a cut back toward dividends (Chetty & Saez 2005).
        $effectiveTargetPayout *= Valuation::dividendTaxPayoutScale($ctx->macroState->dividendTaxRate);

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
                // A buffer tier caps the payout RATIO (Basel III maximum distributable amount): the dividend may not
                // pass that share of earnings, and a cap above what the board would pay leaves its choice, and the
                // hold above, untouched.
                $targetDividend = min($targetDividend, $sustainableBase * $regulatoryCap);
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
        // The tier caps what is paid, not only what the board aims at: smoothed from a dividend above it, the payment
        // would pass the cap for years, and the firm would borrow to pay it out of the capital the tier is rebuilding.
        if ($regulatoryCap !== null && $regulatoryCap > 0.0) {
            $ctx->newDividend = min($ctx->newDividend, max(0.0, $sustainableBase) * $regulatoryCap);
        }

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
        // It borrows what the dividend is short of the cash above its operating floor, and no more: a treasury below
        // the floor is refilled by the liquidity pass, revolver first and on its own refusal gates, not by debt raised
        // in the dividend's name. The cash cap below counts what was borrowed for the dividend.
        $cashAboveFloor = max(0.0, $ctx->newTreasury - $minOperatingCash);
        $distributionShortfall = ($fundedDividend * $ctx->sharesOutstanding) - $cashAboveFloor;
        $borrowedForDividend = 0.0;
        if ($fundedDividend > 0.0 && $distributionShortfall > 0.0 && $ctx->health->hasMarketAccess && $ctx->health->hasLeverageHeadroom) {
            $this->debtEngine->issueDebt($stock, $distributionShortfall, $ctx->health->rawMetrics->currentMarketRate ?? ($ctx->macroState->yield5yEma + (float) $stock->getCreditSpread()));
            $ctx->wholesaleDebt = (float) $stock->getWholesaleDebt();
            $ctx->newTreasury += $distributionShortfall;
            $borrowedForDividend = $distributionShortfall;

            if (TreasuryEngine::isNewsworthy($ctx->stock, $distributionShortfall)) {
                $purpose = $requiredDividend >= $heldDividend ? 'its required REIT distribution' : 'its dividend';
                $ctx->events[] = ['description' => "Borrowed \$" . number_format($distributionShortfall / 1_000_000_000, 2) . "B to fund {$purpose}.", 'shock' => 0.0];
            }
        }

        $usableCash = $cashAboveFloor + $borrowedForDividend;
        $distributableSurplus = max(0.0, (float) $stock->getRetainedEarnings() + ($ctx->quarterlyEps * $ctx->sharesOutstanding));

        $maxCashDividendPerShare = $ctx->sharesOutstanding > 0 ? ($usableCash / $ctx->sharesOutstanding) : 0.0;
        $maxLegalDividendPerShare = $ctx->sharesOutstanding > 0 ? ($distributableSurplus / $ctx->sharesOutstanding) : 0.0;

        $ctx->newDividend = min($ctx->newDividend, $maxCashDividendPerShare, $maxLegalDividendPerShare);

        // A regulator's ceiling on what may leave in twelve months, dividends and repurchases together: each quarter
        // takes a quarter of it, so no twelve-month window exceeds it, and it binds a payer holding its dividend as
        // the cash and legal caps do. What the dividend leaves is the repurchase's room.
        $ceiling = $ctx->strategy->getDistributionCeiling($stock, $ctx->trailingQuarterlyEps * 4.0 * $ctx->sharesOutstanding);
        if ($ceiling !== null) {
            $quarterCeiling = max(0.0, $ceiling) / 4.0;
            $ctx->newDividend = min($ctx->newDividend, $ctx->sharesOutstanding > 0 ? $quarterCeiling / $ctx->sharesOutstanding : 0.0);
            $ctx->distributionRoom = max(0.0, $quarterCeiling - ($ctx->newDividend * $ctx->sharesOutstanding));
        }

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

        // The cash target the manager runs to: the buffer it chooses, biased, above the liability backing and the
        // solvency floor, which stay where the model puts them. Every hoarding test downstream measures against
        // this, so the fortress is not detected as defective for holding the reserves that define it.
        $ctx->targetOperatingCash = $ctx->stock->getManagementProfile()->managedTargetCash($ctx->strategy, $ctx->operatingBase, (float) $ctx->stock->getCustomerDeposits(), (float) $ctx->stock->getWholesaleDebt());
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
            $ctx->strategy->getUndeployableCapitalShare($ctx->stock, $ctx->macroState, $this->mathUtility),
        );
    }

    private function executeBuybacks(CapitalAllocationContext $ctx): void
    {
        // A pass-through that must distribute its income keeps no surplus to buy its shares back with.
        if ($ctx->strategy->getMinimumDistributionRatio() > 0.0) {
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

        // Negative carry asks the firm to stop holding idle cash against its debt. An under-levered firm's
        // recapitalization spends that cash first, which answers it, so the carry test does not hold it back.
        $isHeldForPaydown = $ctx->health->wantsToPaydownDebt && !$canEasilyCoverDebt && !$isUnderLeveraged;

        if ($isLiquidityCrisis || (!$isHoarder && ($isHeldForPaydown || $ctx->health->interestCoverage < $minBuybackIcr))) {
            // A balance-sheet lender's distributions answer to its capital ratio, not to interest coverage.
            if (!($ctx->strategy->isFinancial() && ($isUnderLeveraged || $capitalSurplusReturn > 0.0))) {
                $ctx->newShares = $ctx->sharesOutstanding;
                return;
            }
        }

        $trueReturn = $ctx->strategy->getTrueReturn($stock);
        $hurdleRate = $ctx->strategy->getHurdleRate($ctx->health);
        $economicSpread = $trueReturn - $hurdleRate;

        $saturationSeverity = $this->resolveRedeploymentSeverity($ctx, $trueReturn);

        // The multiple values equity, so it is struck at the cost of equity on the return on equity, as the
        // market strikes it (MarketEngine); the spread above stays ROIC against the hurdle, an EVA test.
        $fairValuePE = ManagementValuation::fairValuePE($ctx->strategy, $stock, $trueReturn, $ctx->health, $ctx->macroState);

        $ctx->newShares = $ctx->sharesOutstanding;

        // A share retired below intrinsic book raises every remaining share's book value, whatever the
        // business does next. Zero for an operating company, whose case is the earnings test above; for a
        // closed-end structure that test can never find it, because a trust's economic spread sits at zero
        // and the discount inflates the very P/E being compared.
        $repurchaseAccretion = $ctx->strategy->resolveRepurchaseAccretion($stock, $ctx->currentPrice);
        $isTradingBelowBook = $repurchaseAccretion >= self::MIN_ACCRETIVE_REPURCHASE_DISCOUNT;

        if (($economicSpread > 0.02 && $ctx->currentPE < ($fairValuePE + 3.0)) || $isHoarder || $isUnderLeveraged || $capitalSurplusReturn > 0.0 || $saturationSeverity > 0.20 || $isTradingBelowBook) {
            $maxWillingSpend = $ctx->strategy->calculateMaxBuybackSpend($excessCash, $ctx->retainedEarningsThisQuarter, $isMegaHoarder);

            // Saturation Buyback Unlock: Mature firms distribute non-reinvestable excess cash
            if ($saturationSeverity > 0.05) {
                $saturationSpendRatio = $isMegaHoarder
                    ? self::BUYBACK_SPEND_MEGA_SATURATED_RATIO
                    : self::BUYBACK_SPEND_SATURATED_RATIO;
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
            $effectiveRegulatoryPct = $baseRegulatoryPct + (self::MAX_REGULATORY_SPEND_SATURATED - $baseRegulatoryPct) * $saturationSeverity;
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
            if ($ctx->distributionRoom !== null) {
                $absoluteMaxSpend = min($absoluteMaxSpend, $ctx->distributionRoom);
            }

            $minOperatingCash = $ctx->strategy->calculateMinOperatingCash($ctx->operatingBase, (float) $stock->getCustomerDeposits(), (float) $stock->getWholesaleDebt());

            // Borrowed only for the part of the repurchase the firm can make that its excess cash cannot pay for. A
            // treasury below its operating floor is refilled before any recapitalization: borrowing into it only
            // plugged the hole, and the repurchase it was raised for never happened.
            $recapitalization = $recapitalization > 0.0 && $ctx->newTreasury >= $minOperatingCash
                ? $this->fundRecapitalization($ctx, min($recapitalization, $absoluteMaxSpend), $excessCash)
                : 0.0;

            // Hard Solvency Constraint: Cannot spend more cash than physically available in treasury above min
            // operating buffer
            $availableCashBuffer = max(0.0, $ctx->newTreasury - $minOperatingCash);
            $absoluteMaxSpend = min($absoluteMaxSpend, $availableCashBuffer);

            $valuationDiscount = max(
                $repurchaseAccretion,
                max(0.0, ($fairValuePE - $ctx->currentPE) / max(1.0, $fairValuePE)),
            );
            $aggression = $isMegaHoarder ? 1.0 : min(1.0, 0.50 + $valuationDiscount);

            $actualSpend = $absoluteMaxSpend * $aggression * ($isHoarder ? 1.0 : $this->mathUtility->generateUniformBetween(self::BUYBACK_APPETITE_MIN_SHARE, 1.0));
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
                    'shock' => 0.0,
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
        // The earning-asset ledger already carries this quarter's deployment; the treasury is still the opening
        // balance.
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

        $endRatio = $openingRatio - (TimeSeries::periodAdjustmentSpeed(self::CAPITAL_TARGET_ADJUSTMENT_SPEED, EarningsEngine::QUARTERLY_TIME_STEP) * ($openingRatio - $targetRatio));
        if ($equity / $assets <= $endRatio) {
            return 0.0;
        }

        return ($equity - ($endRatio * $assets)) / (1.0 - $endRatio);
    }

    /**
     * Partial adjustment toward a target debt-to-equity ratio (Flannery & Rangan 2006): the quarter's change in the
     * ratio, this quarter's retained earnings included, closes the quarterly share of the gap it opened on. D is
     * net debt: a repurchase R financed with new debt or with cash above the operating balance moves the ratio to
     * (D + R) / (E - R), so R = (L E - D) / (1 + L) is the one that ends the quarter on the ratio L.
     */
    public static function leverageTargetRepurchase(float $equity, float $debt, float $targetRatio, float $openingRatio): float
    {
        if ($equity <= 0.0 || $openingRatio >= $targetRatio) {
            return 0.0;
        }

        $endRatio = $openingRatio + (TimeSeries::periodAdjustmentSpeed(self::LEVERAGE_TARGET_ADJUSTMENT_SPEED, EarningsEngine::QUARTERLY_TIME_STEP) * ($targetRatio - $openingRatio));

        return max(0.0, (($endRatio * $equity) - $debt) / (1.0 + $endRatio));
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

        $leaseLiability = $this->corporateMetrics->resolveLeaseLiability($ctx->stock, $ctx->health->rawMetrics->revenue, $ctx->strategy->getLeaseIntensity($ctx->stock));

        // Net of the cash left above the operating balance once the dividend is paid, as the opening ratio is: a
        // repurchase from that cash raises net debt by what it spends, so the same R ends the quarter on the ratio
        // whichever leg fundRecapitalization pays it from.
        return self::leverageTargetRepurchase(
            $this->resolvePreBuybackEquity($ctx),
            CreditRisk::netBookDebt((float) $ctx->stock->getTotalDebt(), $leaseLiability, $ctx->newTreasury, $ctx->targetOperatingCash),
            $ctx->health->leverageTarget,
            $ctx->health->debtToEquity,
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
