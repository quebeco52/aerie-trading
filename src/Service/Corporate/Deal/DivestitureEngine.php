<?php

declare(strict_types=1);

namespace App\Service\Corporate\Deal;

use App\Data\Company\Sectors;
use App\Data\Company\Segments;
use App\DTO\DivestitureContext;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Corporate\Segment\SegmentBooks;
use App\Service\Corporate\Segment\SegmentOutlook;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MathUtility;
use App\Service\Math\Valuation;
use App\Service\News\MarketEventPublisher;
use App\Service\News\WireFormat;

/**
 * A listed firm selling a division for cash: the hazard gate, whether the business is dying, distressed or simply
 * rich enough to sell a slice at a premium, the one distress sale each episode allows, the sale price, the pro rata
 * retirement of every ledger, the tax on the gain, and the announcement.
 */
class DivestitureEngine
{
    // --- Divestiture Parameters ---
    /** Weight of the reported quarter in the income a sale is judged on; the rest is the structural return (half). */
    public const DIV_REPORTED_INCOME_WEIGHT = 0.50;
    /** Minimum fraction for dying company divestiture. */
    public const DIV_DYING_FRACTION_MIN = 0.30;
    /** Maximum fraction for dying company divestiture. */
    public const DIV_DYING_FRACTION_MAX = 0.50;
    /** Minimum sale multiple for dying company. */
    public const DIV_DYING_MULTIPLE_MIN = 3.0;
    /** Maximum sale multiple for dying company. */
    public const DIV_DYING_MULTIPLE_MAX = 5.0;
    /** Annual probability for dying company divestiture. */
    public const DIV_DYING_ANNUAL_PROB = 2.0;

    /** Minimum fraction for distressed company divestiture. */
    public const DIV_DISTRESSED_FRACTION_MIN = 0.15;
    /** Maximum fraction for distressed company divestiture. */
    public const DIV_DISTRESSED_FRACTION_MAX = 0.30;
    /** Minimum sale multiple for distressed company. */
    public const DIV_DISTRESSED_MULTIPLE_MIN = 6.0;
    /** Maximum sale multiple for distressed company. */
    public const DIV_DISTRESSED_MULTIPLE_MAX = 10.0;
    /** Annual probability for distressed company divestiture. */
    public const DIV_DISTRESSED_ANNUAL_PROB = 0.30;

    /** Minimum fraction for premium company divestiture. */
    public const DIV_PREMIUM_FRACTION_MIN = 0.05;
    /** Maximum fraction for premium company divestiture. */
    public const DIV_PREMIUM_FRACTION_MAX = 0.10;
    /** Minimum sale multiple for premium company. */
    public const DIV_PREMIUM_MIN_MULTIPLE = 8.0;
    /** Maximum sale multiple for premium company. */
    public const DIV_PREMIUM_MAX_MULTIPLE = 18.0;
    /** Annual probability for premium company divestiture. */
    public const DIV_PREMIUM_ANNUAL_PROB = 0.05;

    /**
     * Annual hazard at which a company sells a business it bought into a new industry and put up for sale once it
     * showed it does not earn its cost: 2, a buyer found in about half a year.
     */
    public const DIV_FAILED_ENTRY_ANNUAL_PROB = 2.0;

    /** P/E threshold for divestiture consideration. */
    public const DIV_PE_THRESHOLD = 30.0;
    /** Minimum net income for divestiture consideration, at the District's $12T scale. */
    public const DIV_MIN_NET_INCOME = 2_500_000_000.0;
    /** Long-run return below the business's cost of capital at which a firm sheds a division as distressed (-2pp). */
    public const DIV_DISTRESS_EVA_THRESHOLD = -0.02;
    /** Long-run return below which the business loses money through the cycle and is dying (0%). */
    public const DIV_DYING_RETURN_THRESHOLD = 0.00;
    /** Long-run return below the business's cost of capital at which the firm is dying (-5pp). */
    public const DIV_DYING_EVA_THRESHOLD = -0.05;

    /** Cash fortress ratio to prevent divestitures. */
    public const DIV_CASH_FORTRESS_RATIO = 0.10;

    /**
     * Discount a distressed seller takes to the market value of the assets it sells (~14%; Pulvino 1998, aircraft fire
     * sales).
     */
    public const DIV_FIRE_SALE_DISCOUNT = 0.14;
    /**
     * Carried stream-state key: the time of the distress sale the firm's current episode has had; cleared once its
     * return has recovered.
     */
    public const STATE_DISTRESS_SALE_EPISODE = EarningsEngine::CARRIED_STATE_PREFIX . 'distress_sale_episode';

    /** The largest annual divestiture hazard any state assigns; the bound its rejection gate is struck at. */
    public static function divestitureHazardCeiling(): float
    {
        return max(self::DIV_DYING_ANNUAL_PROB, self::DIV_DISTRESSED_ANNUAL_PROB, self::DIV_PREMIUM_ANNUAL_PROB, self::DIV_FAILED_ENTRY_ANNUAL_PROB);
    }

    public function __construct(
        private MarketEventPublisher $marketEvent,
        private DebtEngine $debtEngine,
        private MathUtility $mathUtility,
        private CorporateMetrics $corporateMetrics,
        /** Industry capacity balance the division's share leaves; null in unit tests. */
        private ?IndustryShareLedger $industryShareLedger = null,
    ) {}

    public function evaluateCorporateDivestiture(Stock $seller, MacroStateDTO $macroState, float $dt): ?array
    {
        if ($seller->isBankrupt()) {
            return null;
        }

        // The same rejection gate as AcquisitionEngine::evaluatePrivateAcquisition(): pass at the ceiling hazard first,
        // work out which hazard actually applies only then.
        if (!$this->mathUtility->checkProbability(self::divestitureHazardCeiling() * $dt)) {
            return null;
        }

        $ctx = new DivestitureContext($seller, $macroState, $dt);

        $this->initializeDivestitureContext($ctx);

        // A business bought into a new industry that does not earn its cost is sold whatever the rest of the company
        // is doing: firms refocus by selling the businesses they diversified into (Kaplan & Weisbach 1992; John &
        // Ofek 1995).
        $failedEntry = SegmentBooks::forSale($seller);
        if ($failedEntry !== null) {
            $this->determineRefocusingSale($ctx, $failedEntry);
        } elseif ($this->isInOpenDistressEpisode($ctx) || $this->shouldAbortDivestiture($ctx)) {
            return null;
        } else {
            $this->determineDivestitureStrategy($ctx);
        }
        if (!$ctx->dealExecuted) {
            return null;
        }

        $this->executeDivestitureSale($ctx);
        $this->applyDivestitureAccounting($ctx);
        if ($failedEntry === null && ($ctx->isDying || $ctx->isDistressed)) {
            self::openDistressEpisode($seller, $macroState->totalTime);
        }

        return $this->finalizeDivestitureEvent($ctx);
    }

    /**
     * The hurdle the sale test reads: CAPM on the firm's own beta, its asset beta for an operating company and one that
     * carries its structural leverage for a lender. It holds no distress premium, so dearer financing is not read as a
     * business destroying value (Modigliani & Miller 1958).
     */
    public static function businessCostOfCapital(Stock $stock, MacroStateDTO $macroState): float
    {
        return Valuation::calculateCAPM($macroState->yield10y, (float) $stock->getBeta(), $macroState->equityRiskPremium);
    }

    /**
     * One distress sale per episode: the slice is pro rata, so it leaves the return that triggered it where it was. The
     * episode closes once the classification is healthy again, read before the cash-buffer exemption the proceeds meet.
     */
    private function isInOpenDistressEpisode(DivestitureContext $ctx): bool
    {
        if (!isset(($ctx->seller->getEarningsMomentumZ() ?? [])[self::STATE_DISTRESS_SALE_EPISODE])) {
            return false;
        }
        if ($ctx->isDistressed || $ctx->isDying) {
            return true;
        }
        self::closeDistressEpisode($ctx->seller);

        return false;
    }

    private static function openDistressEpisode(Stock $stock, float $time): void
    {
        $state = $stock->getEarningsMomentumZ() ?? [];
        $state[self::STATE_DISTRESS_SALE_EPISODE] = $time;
        $stock->setEarningsMomentumZ($state);
    }

    private static function closeDistressEpisode(Stock $stock): void
    {
        $state = $stock->getEarningsMomentumZ() ?? [];
        unset($state[self::STATE_DISTRESS_SALE_EPISODE]);
        $stock->setEarningsMomentumZ($state);
    }

    private function initializeDivestitureContext(DivestitureContext $ctx): void
    {
        $stock = $ctx->seller;

        $ctx->price = (float) $stock->getPrice();
        $ctx->shares = max(1.0, (float) $stock->getSharesOutstanding());
        $ctx->netIncome = (float) $stock->getTotalNetIncome();

        $ctx->health = $this->debtEngine->analyzeDebtHealth($stock, $ctx->macroState);
        $ctx->wacc = $ctx->health->wacc;

        $ctx->industry = $stock->getIndustry() ?: 'General';
        $ctx->businessModel = Sectors::businessModelFor($ctx->industry);
        $ctx->strategy = Sectors::getBusinessModelStrategy($ctx->businessModel);

        $ctx->currentReturn = $ctx->strategy->getTrueReturn($stock);
        if ($ctx->currentReturn === 0.0) {
            $ctx->currentReturn = $ctx->strategy->calculateEconomicReturn($stock, $ctx->netIncome, $stock->getInvestedCapital());
        }
        // Whether the business earns its keep is read on its long-run return against the cost of capital of the
        // business: a slump shorter than that horizon is not decline, and a distress premium on the firm's financing
        // is not value destroyed by its operations.
        $longRunReturn = $stock->getLongRunReturn();
        $ctx->throughCycleReturn = $longRunReturn !== null ? (float) $longRunReturn : $ctx->currentReturn;
        $ctx->hurdleRate = self::businessCostOfCapital($stock, $ctx->macroState);

        $ctx->evaSpread = $ctx->throughCycleReturn - $ctx->hurdleRate;

        $ctx->isDistressed = $ctx->evaSpread < self::DIV_DISTRESS_EVA_THRESHOLD;
        $ctx->isDying = $ctx->throughCycleReturn < self::DIV_DYING_RETURN_THRESHOLD || $ctx->evaSpread < self::DIV_DYING_EVA_THRESHOLD;

        $ctx->treasury = (float) $stock->getCorporateTreasury();
        $ctx->operatingBase = $this->corporateMetrics->calculateOperatingBase((float) $stock->getTotalRevenue(), (float) $stock->getTotalEquity());
        $ctx->hasCashBuffer = $ctx->treasury > ($ctx->operatingBase * self::DIV_CASH_FORTRESS_RATIO);

        $ctx->currentEquity = (float) $stock->getTotalEquity();
        $ctx->investedCapital = $stock->getInvestedCapital();
        $ctx->evaluationCapital = $ctx->strategy->getEvaluationCapital($ctx->currentEquity, $ctx->investedCapital);

        $ctx->structuralNetIncome = $ctx->evaluationCapital * $ctx->currentReturn;
        $ctx->normalizedNetIncome = ($ctx->netIncome * self::DIV_REPORTED_INCOME_WEIGHT) + ($ctx->structuralNetIncome * (1.0 - self::DIV_REPORTED_INCOME_WEIGHT));

        $ctx->normalizedEps = $ctx->normalizedNetIncome / $ctx->shares;
        $ctx->currentPE = $ctx->normalizedEps > 0 ? $ctx->price / $ctx->normalizedEps : 0.0;

        $ctx->targetCash = $stock->getManagementProfile()->managedTargetCash($ctx->strategy, $ctx->operatingBase, (float) $stock->getCustomerDeposits(), (float) $stock->getWholesaleDebt());
        $ctx->hoardStatus = $ctx->strategy->evaluateHoardingStatus($ctx->treasury, $ctx->targetCash, $stock->getManagementProfile()->appliedHoardingBase($ctx->operatingBase), (float) $stock->getTotalDebt());

        $ctx->nominalGdpIndex = $ctx->macroState->nominalGdpIndex;
        $ctx->samRatio = (float) $stock->getSamRatio();
        $ctx->scaleRatio = $this->corporateMetrics->calculateAddressableShare($stock, $ctx->evaluationCapital, $ctx->macroState);
    }

    private function shouldAbortDivestiture(DivestitureContext $ctx): bool
    {
        if ($ctx->hoardStatus['is_hoarder']) {
            return true;
        }

        if ($ctx->hasCashBuffer && !($ctx->scaleRatio > 1.00)) {
            $ctx->isDistressed = false;
            $ctx->isDying = false;
        }

        if (!$ctx->isDistressed && ($ctx->currentPE < self::DIV_PE_THRESHOLD || $ctx->normalizedNetIncome < self::DIV_MIN_NET_INCOME)) {
            return true;
        }

        return false;
    }

    private function determineDivestitureStrategy(DivestitureContext $ctx): void
    {
        if ($ctx->isDying) {
            $ctx->divestedFraction = $this->mathUtility->generateUniformBetween(self::DIV_DYING_FRACTION_MIN, self::DIV_DYING_FRACTION_MAX);
            [$ctx->minFraction, $ctx->maxFraction] = [self::DIV_DYING_FRACTION_MIN, self::DIV_DYING_FRACTION_MAX];
            $ctx->saleMultiple = $this->mathUtility->generateUniformBetween(self::DIV_DYING_MULTIPLE_MIN, self::DIV_DYING_MULTIPLE_MAX);
            $ctx->annualProbability = self::DIV_DYING_ANNUAL_PROB;
        } elseif ($ctx->isDistressed) {
            $ctx->divestedFraction = $this->mathUtility->generateUniformBetween(self::DIV_DISTRESSED_FRACTION_MIN, self::DIV_DISTRESSED_FRACTION_MAX);
            [$ctx->minFraction, $ctx->maxFraction] = [self::DIV_DISTRESSED_FRACTION_MIN, self::DIV_DISTRESSED_FRACTION_MAX];
            $ctx->saleMultiple = $this->mathUtility->generateUniformBetween(self::DIV_DISTRESSED_MULTIPLE_MIN, self::DIV_DISTRESSED_MULTIPLE_MAX);
            $ctx->annualProbability = self::DIV_DISTRESSED_ANNUAL_PROB;
        } else {
            $ctx->divestedFraction = $this->mathUtility->generateUniformBetween(self::DIV_PREMIUM_FRACTION_MIN, self::DIV_PREMIUM_FRACTION_MAX);
            [$ctx->minFraction, $ctx->maxFraction] = [self::DIV_PREMIUM_FRACTION_MIN, self::DIV_PREMIUM_FRACTION_MAX];
            $ctx->sectorPE = Sectors::MACRO_SECTORS[$ctx->seller->getSector()] ?? 20.0;
            $blendedMultiple = ($ctx->currentPE + $ctx->sectorPE) / 2.0;
            $ctx->saleMultiple = min(self::DIV_PREMIUM_MAX_MULTIPLE, max(self::DIV_PREMIUM_MIN_MULTIPLE, $blendedMultiple));
            $ctx->annualProbability = self::DIV_PREMIUM_ANNUAL_PROB;
        }

        // Second stage of the gate: accept with the ratio of this state's hazard to the ceiling. The dt
        // cancels, so the comparison is between annual rates.
        if ($this->mathUtility->generateUniform() * self::divestitureHazardCeiling() >= $ctx->annualProbability) {
            $ctx->dealExecuted = false;
            return;
        }

        $ctx->dealExecuted = true;
        $this->chooseSegmentSale($ctx);
    }

    /**
     * The sale of a business bought into a new industry and put up for sale: whole, its share of the plant, at the
     * multiple a buyer pays for a business earning less than its cost (or the market's value of it less the forced
     * seller's discount, if more), once a buyer is found.
     */
    private function determineRefocusingSale(DivestitureContext $ctx, string $segment): void
    {
        $ctx->dealExecuted = false;
        if ($this->mathUtility->generateUniform() * self::divestitureHazardCeiling() >= self::DIV_FAILED_ENTRY_ANNUAL_PROB) {
            return;
        }

        $momentum = $ctx->seller->getEarningsMomentumZ() ?? [];
        $ctx->soldSegment = $segment;
        $ctx->divestedFraction = (SegmentBooks::plant($momentum) ?? [])[$segment] ?? 0.0;
        [$ctx->minFraction, $ctx->maxFraction] = [$ctx->divestedFraction, $ctx->divestedFraction];
        $ctx->saleMultiple = $this->mathUtility->generateUniformBetween(self::DIV_DISTRESSED_MULTIPLE_MIN, self::DIV_DISTRESSED_MULTIPLE_MAX);
        $ctx->annualProbability = self::DIV_FAILED_ENTRY_ANNUAL_PROB;
        $earnings = SegmentBooks::earningShares($momentum, $segment);
        $ctx->revenueFraction = $earnings['revenue'];
        $ctx->incomeFraction = $earnings['income'];
        $ctx->dealExecuted = $ctx->divestedFraction > 0.0;
    }

    /**
     * A company that keeps plant by segment sells a whole business where it can: the segment whose plant earns least
     * (SegmentBooks::weakestSegment()), when its size is in the range this kind of sale takes. The slice is then its
     * share of the plant, which takes the same share of every ledger the company keeps whole (working capital,
     * goodwill, leases, debt): those are not kept by segment.
     */
    private function chooseSegmentSale(DivestitureContext $ctx): void
    {
        $ctx->revenueFraction = $ctx->divestedFraction;
        $ctx->incomeFraction = $ctx->divestedFraction;
        $momentum = $ctx->seller->getEarningsMomentumZ() ?? [];
        $segment = SegmentBooks::weakestSegment($ctx->seller);
        $share = $segment === null ? 0.0 : (SegmentBooks::plant($momentum)[$segment] ?? 0.0);
        if ($segment !== null && $share >= $ctx->minFraction && $share <= $ctx->maxFraction) {
            $ctx->soldSegment = $segment;
            $ctx->divestedFraction = $share;
            // What it sells and earns, not its plant, is what the buyer pays for and the seller's sales lose.
            $earnings = SegmentBooks::earningShares($momentum, $segment);
            $ctx->revenueFraction = $earnings['revenue'];
            $ctx->incomeFraction = $earnings['income'];
        }
    }

    private function executeDivestitureSale(DivestitureContext $ctx): void
    {
        $ctx->lostNetIncome = $ctx->normalizedNetIncome * $ctx->incomeFraction;
        $ctx->currentDebt = (float) $ctx->seller->getWholesaleDebt();
        $ctx->lostDebt = $ctx->currentDebt * $ctx->divestedFraction;

        // The book value leaving with the division is what its ledgers actually carry, less the debt the buyer
        // assumes. Only a balance sheet with no modelled asset side (a bank's loan book) falls back to the
        // strategy's capital-proxy estimate; anything else would state a gain against assets never retired.
        $ctx->goodwillSold = SegmentBooks::goodwillSold($ctx->seller->getEarningsMomentumZ() ?? [], $ctx->soldSegment, $ctx->divestedFraction, (float) $ctx->seller->getGoodwill());
        $ctx->lostEquity = $ctx->seller->hasBalanceSheetLedger()
            ? $this->calculateBookValueDisposed($ctx->seller, $ctx->divestedFraction, $ctx->goodwillSold) - $ctx->lostDebt
            : $ctx->strategy->calculateDivestedEquity($ctx->seller, $ctx->divestedFraction, $ctx->currentEquity, $ctx->currentDebt, $ctx->treasury, $ctx->investedCapital, $ctx->lostDebt);

        // What the assets fetch on their own: the market's value of the slice sold, less the discount a forced seller
        // takes (Shleifer & Vishny 1992; Pulvino 1998). The floor reads the market, not the book: a firm trading
        // below book has assets the market values below cost, and a floor on book sold them for more than they were
        // worth. The buyer takes its share of the debt, so this is the slice's equity value, as the earnings price is.
        // The cash over the business's needs and the listed stakes stay with the seller, so their value is not sold:
        // the market's value less the cash at face, on the share of the rest that is operations.
        $excessCash = CorporateMetrics::excessOperatingCash($ctx->seller);
        $operatingNetAssets = max(1.0, (float) $ctx->seller->getTotalEquity() - $excessCash);
        $operatingShare = max(0.0, min(1.0, 1.0 - ((float) ($ctx->seller->getListedStakesCarrying() ?? 0.0) / $operatingNetAssets)));
        $ctx->soldValue = $ctx->incomeFraction * max(0.0, ($ctx->price * $ctx->shares) - $excessCash) * $operatingShare;
        $fireSaleValue = $ctx->soldValue * (1.0 - self::DIV_FIRE_SALE_DISCOUNT);

        $ctx->salePrice = $ctx->normalizedNetIncome > 0
            ? max($ctx->lostNetIncome * $ctx->saleMultiple, $fireSaleValue)
            : $fireSaleValue;

        $currentTreasury = (float) $ctx->seller->getCorporateTreasury();
        $ctx->newTreasury = $currentTreasury + $ctx->salePrice;
        $ctx->seller->setCorporateTreasury((string) $ctx->newTreasury);
    }

    private function applyDivestitureAccounting(DivestitureContext $ctx): void
    {
        $stock = $ctx->seller;

        $currentEps = (float) $stock->getEarningsPerShare();
        $stock->setEarningsPerShare((string) ($currentEps * (1.0 - $ctx->incomeFraction)));

        $stock->setWholesaleDebt((string) max(0.0, $ctx->currentDebt - $ctx->lostDebt));
        // The buyer assumes its share of the debt, and with it that share of any principal already overdue.
        $stock->setPrincipalArrears((string) ((float) $stock->getPrincipalArrears() * (1.0 - $ctx->divestedFraction)));

        $ctx->strategy->shedDivestedLiabilities($stock, $ctx->divestedFraction, $ctx->treasury);

        $currentRevenue = (float) $stock->getTotalRevenue();
        $stock->setTotalRevenue((string) max(1.0, $currentRevenue * (1.0 - $ctx->revenueFraction)));
        // ...and a seller as if the business it sold had never been its own.
        ProForma::restateOperatingHistory($stock, 1.0 - $ctx->incomeFraction, 0.0, 0.0, 1.0 - $ctx->revenueFraction);
        if ($ctx->soldSegment === null) {
            $this->industryShareLedger?->recordDivestedFraction($stock, $ctx->divestedFraction, $ctx->macroState, IndustryShareLedger::secularExcessGrowth($ctx->strategy, $stock));
        } else {
            // A segment sold whole leaves its own industry; the company's other businesses keep all their plant.
            $this->industryShareLedger?->recordSoldSegment($stock, $ctx->soldSegment);
            $momentum = $stock->getEarningsMomentumZ() ?? [];
            foreach (SegmentBooks::sellSegment(SegmentBooks::plant($momentum) ?? [], $ctx->soldSegment) as $segment => $share) {
                $momentum[SegmentBooks::STATE_PLANT . $segment] = $share;
            }
            // Its lines sell nothing from now on, its market and its share of the sales are no longer the company's,
            // and the business kept earns its own segments' margins.
            $momentum[SegmentBooks::STATE_TARGET . $ctx->soldSegment] = 0.0;
            $momentum[SegmentBooks::STATE_MARKET . $ctx->soldSegment] = 0.0;
            $momentum[SegmentOutlook::STATE_SHARE . $ctx->soldSegment] = 0.0;
            $stock->setEarningsMomentumZ($momentum);
            $economics = SegmentBooks::companyEconomics($momentum);
            if ($economics !== null) {
                $stock->setOperatingMargin((string) max(0.01, $economics['margin']));
                $stock->setAssetTurnover((string) $economics['turnover']);
            }
        }

        $markSold = $stock->hasBalanceSheetLedger() ? $this->retireDivestedLedgers($stock, $ctx->divestedFraction, $ctx->goodwillSold, $ctx->soldSegment) : 0.0;
        if ($ctx->soldSegment !== null) {
            $stock->setEarningsMomentumZ(SegmentBooks::withoutEntryState($stock->getEarningsMomentumZ() ?? [], $ctx->soldSegment));
        }

        // A gain on selling a business is taxable income (ASC 740), struck under the same carryforward rules as a
        // quarter's (FirmEconomics::resolveIncomeTax()): it uses the carried loss up to its cap, and a loss adds to
        // the carryforward. The current tax is paid from the proceeds.
        $ctx->gainOnSale = $ctx->salePrice - $ctx->lostEquity;
        $openingDta = $stock->getDeferredTaxAsset();
        $nol = (float) $stock->getNetOperatingLoss();
        // A loss carries no asset while the firm's recent pre-tax income, the sale's included, is a cumulative loss.
        $allowanceRequired = array_sum($stock->getPretaxIncomeHistory() ?? []) + $ctx->gainOnSale < 0.0;
        $tax = FirmEconomics::resolveIncomeTax($ctx->gainOnSale, $nol, $openingDta, $allowanceRequired, $ctx->strategy->getEffectiveTaxRate($ctx->macroState->corporateTaxRate));
        $ctx->taxOnSale = $tax['current'] + $tax['deferred'];
        $stock->setNetOperatingLoss((string) $tax['nol']);
        $stock->setDeferredTaxAsset($tax['dta']);
        $stock->setCorporateTreasury((string) ((float) $stock->getCorporateTreasury() - $tax['current']));

        // Equity moves by the gain or loss on sale after its tax, struck on amortized cost, and by the slice's
        // securities mark leaving other comprehensive income: proceeds in, book value and tax out. No floor, because a
        // floor would invent equity with no asset behind it and unbalance the sheet.
        $afterTaxGain = $ctx->gainOnSale - $ctx->taxOnSale;
        $stock->setTotalEquity((string) ($ctx->currentEquity + $afterTaxGain - $markSold));
        $currentRetained = (float) $stock->getRetainedEarnings();
        $stock->setRetainedEarnings((string) ($currentRetained + $afterTaxGain));
        // A division sold as a slice of the firm leaves the business kept earning the same margin and return it did
        // before; a segment sold whole leaves the rest selling at its own industries' prices from the next report.
        // Distress does not make it more efficient.
    }

    /**
     * Book value of the assets a divested division takes with it, before the debt the buyer assumes: a pro
     * rata slice of every operating ledger, and the goodwill the sale takes. Goodwill follows the business it was
     * paid for (ASC 350-20-40-1): a business bought into a new industry takes what was paid for it, any other a share
     * of the rest, as relative value would allocate it. The trade cycle goes net of its credit-loss allowance and of
     * the payables suppliers are owed, and the deferred tax on the plant's timing difference is a liability the buyer
     * inherits with the plant.
     */
    private function calculateBookValueDisposed(Stock $stock, float $fraction, float $goodwillSold): float
    {
        $assets = $stock->getNetPpe()
            + (float) $stock->getCipBalance()
            + $stock->getNetReceivables()
            + $stock->getNetInventory()
            + $stock->getNetEarningAssets();
        $liabilities = (float) ($stock->getPayables() ?? 0.0) + (float) $stock->getDeferredTaxLiability();

        if ($stock->hasEarningAssetLedger()) {
            // A divested banking division takes its share of the cash reserves and the deposits that fund
            // it, which is what the financial strategy sheds in shedDivestedLiabilities().
            $assets += max(0.0, (float) $stock->getCorporateTreasury());
            $liabilities += (float) $stock->getCustomerDeposits();
        }

        return (($assets - $liabilities) * $fraction) + $goodwillSold;
    }

    /**
     * Retires the divested fraction of every operating ledger, the same slice calculateBookValueDisposed()
     * valued. Gross cost and accumulated depreciation leave together, which keeps the plant that stays at the
     * same average age: selling a division tells you nothing about how worn the plant you kept is. The tax
     * basis and deferred tax go with the plant, goodwill with the business, and the trade balances with the
     * customers and suppliers who move to the buyer. Cash stays: the proceeds are already in treasury.
     *
     * @return float The slice's securities mark net of its deferred tax, which leaves other comprehensive income now
     *               rather than at the next quarter's mark (ASC 320 recycling).
     */
    private function retireDivestedLedgers(Stock $stock, float $fraction, float $goodwillSold, ?string $soldSegment): float
    {
        $retained = max(0.0, 1.0 - $fraction);
        $markSold = 0.0;

        if ($stock->hasEarningAssetLedger()) {
            $stock->setEarningAssets((string) ((float) $stock->getEarningAssets() * $retained));
            $stock->setCreditLossAllowance((string) ((float) $stock->getCreditLossAllowance() * $retained));

            // The securities sold take their recognised mark and its deferred tax with them.
            $markSold = (1.0 - $retained) * ($stock->getRecognizedSecuritiesMark() - $stock->getSecuritiesDeferredTax());
            $stock->setUnrealizedSecuritiesMark((string) ((float) $stock->getUnrealizedSecuritiesMark() * $retained));
            $stock->setSecuritiesDeferredTax($stock->getSecuritiesDeferredTax() * $retained);
        }
        if ($stock->getGrossPpe() !== null) {
            $stock->setGrossPpe((string) ((float) $stock->getGrossPpe() * $retained));
            $stock->setAccumulatedDepreciation((string) ((float) $stock->getAccumulatedDepreciation() * $retained));
        }
        if ($stock->getPpeTaxBasis() !== null) {
            $stock->setPpeTaxBasis((string) ((float) $stock->getPpeTaxBasis() * $retained));
        }
        $stock->setCipBalance((string) ((float) $stock->getCipBalance() * $retained));
        // Construction leaves pro rata, so a venture still being built keeps the same share of what is left.
        $stock->setEarningsMomentumZ(SegmentBooks::scalePending($stock->getEarningsMomentumZ() ?? [], $retained));
        $stock->setEarningsMomentumZ(SegmentBooks::keepGoodwill($stock->getEarningsMomentumZ() ?? [], $soldSegment, $retained) + ($stock->getEarningsMomentumZ() ?? []));
        $stock->setGoodwill((string) max(0.0, (float) $stock->getGoodwill() - $goodwillSold));
        $stock->setDeferredTaxLiability((string) ((float) $stock->getDeferredTaxLiability() * $retained));
        // The unit's leases go with it (the ledger would otherwise run them off over years of no use); the loss
        // carryforward and its asset stay with the seller, whose losses they were.
        if ($stock->getLeaseLiability() !== null) {
            $stock->setLeaseLiability($stock->getLeaseLiability() * $retained);
        }

        if ($stock->hasWorkingCapitalLedger()) {
            $stock->setReceivables((string) ((float) $stock->getReceivables() * $retained));
            $stock->setReceivablesAllowance((string) ((float) $stock->getReceivablesAllowance() * $retained));
            $stock->setInventory((string) ((float) $stock->getInventory() * $retained));
            $stock->setInventoryAllowance((string) ((float) $stock->getInventoryAllowance() * $retained));
            $stock->setPayables((string) ((float) $stock->getPayables() * $retained));
        }

        return $markSold;
    }

    private function finalizeDivestitureEvent(DivestitureContext $ctx): array
    {
        $salePrice = WireFormat::money($ctx->salePrice);
        $gainOrLoss = WireFormat::money(abs($ctx->gainOnSale));
        $result = $ctx->gainOnSale >= 0.0 ? "generating a {$gainOrLoss} gain on sale" : "booking a {$gainOrLoss} loss on sale";
        if ($ctx->taxOnSale !== 0.0) {
            $result .= ' (' . WireFormat::money(abs($ctx->gainOnSale - $ctx->taxOnSale)) . ' after tax)';
        }
        $division = $ctx->soldSegment === null ? PrivateCompany::procedural($this->mathUtility)['name'] . ' division' : lcfirst(SegmentBooks::label($ctx->seller, $ctx->soldSegment)) . ' business';
        $desc = "{$ctx->seller->getName()} sold its {$division} for {$salePrice} in cash, {$result}.";

        // The seller's announcement return is what the sale is worth to its shareholders over their stake. The
        // market already values the division pro rata inside the firm, and the buyer takes its share of the
        // debt with it, so the seller gives up the slice's market value and receives the price, less the tax:
        // selling above its own valuation creates value (Hite, Owers & Rogers 1987), a fire sale below it
        // destroys some. Limited liability is the only bound.
        $marketCap = max(1.0, $ctx->price * $ctx->shares);
        $ctx->eventShock = max(-100.0, (($ctx->salePrice - $ctx->taxOnSale - $ctx->soldValue) / $marketCap) * 100.0);

        $event = $this->marketEvent->publish($ctx->seller, 'DIVESTITURE', $desc, $ctx->eventShock, dealShareOfValue: $ctx->salePrice / $marketCap);

        return ['event' => $event, 'shock' => $ctx->eventShock / 100.0];
    }
}
