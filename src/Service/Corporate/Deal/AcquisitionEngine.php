<?php

declare(strict_types=1);

namespace App\Service\Corporate\Deal;

use App\Data\Company\IndustryAdjacency;
use App\Data\Company\Segments;
use App\DTO\AcquisitionContext;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Corporate\ManagementValuation;
use App\Service\Corporate\Segment\EntryMarkets;
use App\Service\Corporate\Segment\SegmentBookOpener;
use App\Service\Corporate\Segment\SegmentBooks;
use App\Service\Corporate\Segment\SegmentEntry;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\BusinessModelInterface;
use App\Service\News\MarketEventPublisher;
use App\Service\News\WireFormat;

/**
 * A listed firm buying a private, off-board company, which brings fresh market cap onto the board: the hazard gate,
 * the deal its manager and balance sheet choose, the target's economics and price, the funding in cash, debt or
 * shares, the purchase accounting at closing, and the announcement. MergerReview holds the approval gates its size
 * has to clear.
 */
class AcquisitionEngine
{
    // --- M&A Deal Parameters ---
    /** Minimum size for a deal to be executed, at the District's $12T scale. */
    public const MA_MIN_DEAL_SIZE = 500_000_000.0;
    /** Probability for overvalued companies. */
    public const MA_OVERVALUED_PROB = 0.15;
    /** Probability for empire builders. */
    public const MA_EMPIRE_BUILDER_PROB = 0.75;
    /** Probability for mega hoarders. */
    public const MA_MEGA_HOARDER_PROB = 0.50;
    /** Probability for standard hoarders. */
    public const MA_HOARDER_PROB = 0.25;
    /** Probability for low leverage companies. */
    public const MA_LOW_LEVERAGE_PROB = 0.10;
    /** Probability for moderate leverage companies. */
    public const MA_MOD_LEVERAGE_PROB = 0.05;
    /** Fallback probability if using cash. */
    public const MA_CASH_FALLBACK_PROB = 0.05;
    /** Threshold for cash fallback, at the District's $12T scale. */
    public const MA_CASH_FALLBACK_THRESHOLD = 7_500_000_000.0;

    /** Minimum buying power for empire builders, at the District's $12T scale. */
    public const MA_EMPIRE_BUILDER_MIN_POWER = 1_000_000_000.0;
    /** Minimum buying power for LBO, at the District's $12T scale. */
    public const MA_LBO_MIN_POWER = 2_500_000_000.0;
    /** Low utilization threshold. */
    public const MA_LOW_UTIL_THRESHOLD = 0.30;
    /** Moderate utilization threshold. */
    public const MA_MOD_UTIL_THRESHOLD = 0.80;
    /** Low rate ceiling. */
    public const MA_LOW_RATE_CEILING = 0.07;
    /** Moderate rate ceiling. */
    public const MA_MOD_RATE_CEILING = 0.08;

    /** Stock dilution fraction during M&A. */
    public const MA_STOCK_DILUTION_FRACTION = 0.10;
    /** Stock underpricing discount. */
    public const MA_STOCK_UNDERPRICING = 0.10;
    /**
     * Largest deal a financial may fund, as a fraction of its equity; applies to every financial acquirer whatever its
     * style.
     */
    public const MA_FINANCIAL_EQUITY_CAP = 0.15;
    /** Most of a target's net identifiable assets that can be trade cycle rather than plant. */
    public const MA_MAX_WORKING_CAPITAL_SHARE = 0.90;

    // --- Overvaluation Screen (a stock-for-stock merger pays in rich paper) ---
    /** P/E past which the stock counts as rich, as a multiple of its fair P/E (1.5x). */
    public const MA_OVERVALUED_PE_PREMIUM = 1.5;
    /** P/E floor of the screen (25x), so a cheap market's premium alone does not read as rich paper. */
    public const MA_OVERVALUED_MIN_PE = 25.0;
    /** Price-to-book floor of the screen (2x). */
    public const MA_OVERVALUED_MIN_PRICE_TO_BOOK = 2.0;

    // --- Deal Spend (the most of its available capital each strategy puts into one deal) ---
    /** An empire builder, funding on its debt capacity: 55%. */
    public const MA_EMPIRE_BUILDER_SPEND = 0.55;
    /** A stock-for-stock merger, of the paper it may issue: 50%. */
    public const MA_OVERVALUED_SPEND = 0.50;
    /** A mega-hoarder, of its usable cash: 60%. */
    public const MA_MEGA_HOARDER_SPEND = 0.60;
    /** A cash hoarder: 40%. */
    public const MA_HOARDER_SPEND = 0.40;
    /** A buyout at low leverage and cheap borrowing: 40%. */
    public const MA_LOW_LEVERAGE_SPEND = 0.40;
    /** A buyout at moderate leverage: 30%. */
    public const MA_MOD_LEVERAGE_SPEND = 0.30;
    /** The cash fallback, a strategic acquisition out of surplus cash: 20%. */
    public const MA_CASH_FALLBACK_SPEND = 0.20;
    /** Least of the strategy's spend a deal draws: the share is uniform on [50%, 100%] of it. */
    public const MA_SPEND_DRAW_MIN = 0.50;
    /**
     * Largest share of a market one off-board firm holds, and so the most of a new industry a buyer from outside it
     * takes in one deal: 10%, and never more than its largest listed firm sells. The fringe is many firms, the largest
     * of them a regional or family-owned leader, not a third of the market.
     */
    public const MA_LARGEST_PRIVATE_SHARE = 0.10;

    // --- Deal Types (the announcement's headline) ---
    /** A purchase in an industry the buyer already serves: a firm bought from that market's fringe. */
    public const STRATEGIC_ACQUISITION = 'STRATEGIC ACQUISITION';
    /** A purchase that takes the buyer into an industry it did not serve. */
    public const CONGLOMERATE_EXPANSION = 'CONGLOMERATE EXPANSION';

    // --- Deal Sizing Limits (named in the deal announcement) ---
    /** The acquirer's cash, borrowing room or shares times the fraction it chose to spend. */
    public const LIMIT_BUYING_POWER = 'buying power';
    /** The business model's own cap on a single deal (a financial's share of tangible equity). */
    public const LIMIT_SPEND_CAP = 'the per-deal spending cap';
    /** The competitive fringe left in the acquirer's market. */
    public const LIMIT_AVAILABLE_MARKET = 'what is left of its market to buy';
    /** The largest off-board firm in an industry a buyer enters from outside it. */
    public const LIMIT_LARGEST_TARGET = 'the largest firm there is to buy';
    /** The largest share that clears horizontal merger review. */
    public const LIMIT_MERGER_REVIEW = 'the largest deal merger review clears';
    /** The largest deal that keeps the acquirer's tangible capital ratio well capitalized. */
    public const LIMIT_CAPITAL_TEST = 'the largest deal that keeps it well capitalized';
    /** The largest borrowed deal its lenders' covenant allows on the combined firm's normal EBITDA. */
    public const LIMIT_COVENANT = "the largest deal its lenders' covenant allows";

    // --- M&A Synergy & Target Returns (Log-Normal) ---
    /**
     * Median log synergy: zero, so a bidder paying no premium is as likely to destroy value as create it (bidder
     * announcement returns average near zero, about half negative; Betton, Eckbo & Thorburn 2008).
     */
    public const MA_SYNERGY_MU = 0.0;
    /** Sigma of log-normal synergy. */
    public const MA_SYNERGY_SIGMA = 0.10;
    /** Mean of log-normal target ROIC. */
    public const MA_TARGET_ROIC_MU = -2.526;
    /** Sigma of log-normal target ROIC. */
    public const MA_TARGET_ROIC_SIGMA = 0.40;
    /** Floor for target ROIC. */
    public const MA_TARGET_ROIC_FLOOR = 0.02;
    /** Ceiling for target ROIC. */
    public const MA_TARGET_ROIC_CEILING = 0.25;
    /**
     * Lowest operating margin of a target as a share of the acquirer's own (70%): a private bolt-on runs leaner than
     * its buyer.
     */
    public const MA_TARGET_MARGIN_SHARE_MIN = 0.70;
    /** Highest operating margin of a target as a share of the acquirer's own (95%). */
    public const MA_TARGET_MARGIN_SHARE_MAX = 0.95;

    /** Ceiling on the acquisition hazard, cached: the styles and constants it is built from never change. */
    private static ?float $acquisitionHazardCeiling = null;

    /**
     * The largest annual acquisition hazard any firm can carry, in any state, under any manager.
     *
     * The bound the rejection gate is struck at. Every branch of determineAcquisitionStrategy() is a base
     * rate times the manager's acquisition bias (or 1.0 where the branch is priced by style), and the cash
     * fallback can fire independently on top, so the ceiling is the largest primary rate plus the fallback
     * rate, both at the most acquisitive manager the profile can produce. Exact, not tuned: a hazard above
     * this cannot occur, and one below it is reproduced exactly by the two-stage draw.
     */
    public static function acquisitionHazardCeiling(): float
    {
        if (self::$acquisitionHazardCeiling !== null) {
            return self::$acquisitionHazardCeiling;
        }

        $maxBias = 1.0;
        foreach (\App\Data\Company\ManagementStyle::cases() as $style) {
            $maxBias = max(
                $maxBias,
                \App\Data\Company\ManagementProfile::forStyle($style, \App\Data\Company\ManagementProfile::MAX_INTENSITY)->acquisitionBias(),
            );
        }

        $primary = max(
            self::MA_EMPIRE_BUILDER_PROB,
            self::MA_OVERVALUED_PROB * $maxBias,
            self::MA_MEGA_HOARDER_PROB * $maxBias,
            self::MA_HOARDER_PROB * $maxBias,
            self::MA_LOW_LEVERAGE_PROB * $maxBias,
            self::MA_MOD_LEVERAGE_PROB * $maxBias,
        );

        return self::$acquisitionHazardCeiling = $primary + (self::MA_CASH_FALLBACK_PROB * $maxBias);
    }

    public function __construct(
        private MarketEventPublisher $marketEvent,
        private DebtEngine $debtEngine,
        private MathUtility $mathUtility,
        private CorporateMetrics $corporateMetrics,
        /** Industry capacity balance; null (unit tests) leaves a deal's plant to be read as a build. */
        private ?IndustryShareLedger $industryShareLedger = null,
    ) {}

    public function evaluatePrivateAcquisition(Stock $acquirer, MacroStateDTO $macroState, float $dt, int $tickCount = 0, int $ticksPerYear = 252): ?array
    {
        if ($acquirer->isBankrupt()) {
            return null;
        }

        // Rejection sampling gate: early-reject low-probability acquisition checks before full context initialization.
        if (!$this->mathUtility->checkProbability(self::acquisitionHazardCeiling() * $dt)) {
            return null;
        }

        $ctx = new AcquisitionContext($acquirer, $macroState, $dt, $tickCount, $ticksPerYear);

        $this->initializeAcquisitionContext($ctx);

        if ($this->shouldAbortAcquisition($ctx)) {
            return null;
        }

        $this->determineAcquisitionStrategy($ctx);
        if (!$ctx->dealExecuted) {
            return null;
        }

        $this->fundAcquisition($ctx);
        $this->applyAcquisitionSynergies($ctx);
        return $this->finalizeAcquisitionEvent($ctx);
    }

    private function initializeAcquisitionContext(AcquisitionContext $ctx): void
    {
        $stock = $ctx->acquirer;

        $ctx->treasury = (float) $stock->getCorporateTreasury();
        $ctx->price = (float) $stock->getPrice();
        $ctx->shares = (float) $stock->getSharesOutstanding();
        $ctx->debtRatio = (float) $stock->getDebtToEquityRatio();

        $ctx->operatingBase = $this->corporateMetrics->calculateOperatingBase((float) $stock->getTotalRevenue(), (float) $stock->getTotalEquity());
        $ctx->equity = (float) $stock->getTotalEquity();
        $ctx->preDealInvestedCapital = $stock->getInvestedCapital();
        $ctx->currentDebt = (float) $stock->getTotalDebt();

        // Underwritten on the margin the firm last reported, as the treasury's own lender test is.
        $ctx->health = $this->debtEngine->analyzeTrailingDebtHealth($stock, $ctx->macroState);

        $ctx->industry = $stock->getIndustry() ?: 'General';
        $ctx->businessModel = \App\Data\Company\Sectors::businessModelFor($ctx->industry);
        $ctx->strategy = \App\Data\Company\Sectors::getBusinessModelStrategy($ctx->businessModel);
    }

    private function shouldAbortAcquisition(AcquisitionContext $ctx): bool
    {
        if ($ctx->health->wantsToPaydownDebt) {
            return true;
        }
        // A fee manager closed at capacity buys no growth, as it borrows and retains none for it.
        if ($ctx->strategy->getCapacityShare($ctx->acquirer) !== null) {
            return true;
        }
        return false;
    }

    private function determineAcquisitionStrategy(AcquisitionContext $ctx): void
    {
        $stock = $ctx->acquirer;

        // The archetype answers "which kind of manager is this?" and the profile answers "how firmly?" —
        // the branch below is an identity test, everything after it reads dials.
        $style = $stock->getManagementStyle();
        $manager = $stock->getManagementProfile();
        $ctx->costOfNewBorrowing = $ctx->health->rawMetrics->currentMarketRate;

        $equityLimit = \App\Data\Company\Sectors::equityLimit($ctx->industry);

        // A lender sizes acquisition debt the way it sizes any other: the balance sheet AND the interest the
        // firm can cover, so the treasury's own capacity test is the one applied here.
        $ctx->borrowingCapacity = max(0.0, $ctx->strategy->calculateDebtExpansionCapacity(
            $ctx->equity,
            $ctx->currentDebt,
            (float) $stock->getWholesaleDebt(),
            $ctx->health,
            $ctx->costOfNewBorrowing,
            $ctx->health->rawMetrics->ebit,
            $ctx->health->rawMetrics->depreciation,
        ));
        $ctx->totalBuyingPower = $ctx->treasury + $ctx->borrowingCapacity;

        $ctx->targetCash = $stock->getManagementProfile()->managedTargetCash($ctx->strategy, $ctx->operatingBase, (float) $stock->getCustomerDeposits(), (float) $stock->getWholesaleDebt());
        $hoardStatus = $ctx->strategy->evaluateHoardingStatus($ctx->treasury, $ctx->targetCash, $manager->appliedHoardingBase($ctx->operatingBase), $ctx->currentDebt);
        $ctx->excessCash = $hoardStatus['excess_cash'];
        $ctx->isHoarder = $hoardStatus['is_hoarder'];
        $ctx->isMegaHoarder = $hoardStatus['is_mega_hoarder'];

        $ctx->normalizedDebtUtilization = $ctx->debtRatio / max(0.1, $equityLimit);

        $ctx->trueReturn = $ctx->strategy->getTrueReturn($stock);
        $ctx->hurdleRate = $ctx->strategy->getHurdleRate($ctx->health);
        $ctx->economicSpread = $ctx->trueReturn - $ctx->hurdleRate;

        $ctx->fairValuePE = ManagementValuation::fairValuePE($ctx->strategy, $stock, $ctx->health, $ctx->macroState);
        // Overvalued on the earnings the multiple is struck on.
        $normalizedEps = ManagementValuation::normalizedEps($ctx->strategy, $stock, $ctx->health, $ctx->macroState);
        $ctx->currentPE = $normalizedEps > 0.0 ? $ctx->price / $normalizedEps : 0.0;
        $ctx->bookValuePerShare = max(0.01, $ctx->equity / max(1.0, $ctx->shares));
        $ctx->priceToBook = $ctx->price / $ctx->bookValuePerShare;
        $ctx->isOvervalued = $normalizedEps > 0.0 && $ctx->economicSpread > 0.0 && $ctx->currentPE > ($ctx->fairValuePE * self::MA_OVERVALUED_PE_PREMIUM) && $ctx->currentPE > self::MA_OVERVALUED_MIN_PE && $ctx->priceToBook > self::MA_OVERVALUED_MIN_PRICE_TO_BOOK;

        $ctx->isEmpireBuilder = $style === \App\Data\Company\ManagementStyle::EmpireBuilder;
        $ctx->hubrisPremium = $manager->hubrisPremium();

        // Discretionary acquisition strategy: empire builders, overvalued stock mergers, and cash hoarders.
        $config = match (true) {
            // Empire builders fund acquisitions via available debt capacity, falling through to cash if covenants bind.
            $ctx->isEmpireBuilder && $ctx->health->canIssueDebt && $ctx->health->hasLeverageHeadroom && $ctx->totalBuyingPower > self::MA_EMPIRE_BUILDER_MIN_POWER => [
                'prob' => self::MA_EMPIRE_BUILDER_PROB, 'spend' => self::MA_EMPIRE_BUILDER_SPEND, 'type' => $ctx->strategy->getAcquisitionType(self::CONGLOMERATE_EXPANSION), 'use_leverage' => true, 'use_stock' => false, 'style_priced' => true,
            ],
            $ctx->isOvervalued => [
                'prob' => self::MA_OVERVALUED_PROB, 'spend' => self::MA_OVERVALUED_SPEND, 'type' => 'STOCK-FOR-STOCK MERGER', 'use_leverage' => false, 'use_stock' => true,
            ],
            $ctx->isMegaHoarder => [
                'prob' => self::MA_MEGA_HOARDER_PROB, 'spend' => self::MA_MEGA_HOARDER_SPEND, 'type' => $ctx->strategy->getAcquisitionType(self::CONGLOMERATE_EXPANSION), 'use_leverage' => false, 'use_stock' => false,
            ],
            $ctx->isHoarder => [
                'prob' => self::MA_HOARDER_PROB, 'spend' => self::MA_HOARDER_SPEND, 'type' => $ctx->strategy->getAcquisitionType(self::CONGLOMERATE_EXPANSION), 'use_leverage' => false, 'use_stock' => false,
            ],
            $ctx->health->canIssueDebt && $ctx->health->hasLeverageHeadroom && $ctx->normalizedDebtUtilization < self::MA_LOW_UTIL_THRESHOLD && $ctx->totalBuyingPower > self::MA_LBO_MIN_POWER && $ctx->costOfNewBorrowing < self::MA_LOW_RATE_CEILING => [
                'prob' => self::MA_LOW_LEVERAGE_PROB, 'spend' => self::MA_LOW_LEVERAGE_SPEND, 'type' => $ctx->strategy->getAcquisitionType('LEVERAGED BUYOUT'), 'use_leverage' => true, 'use_stock' => false,
            ],
            $ctx->health->canIssueDebt && $ctx->health->hasLeverageHeadroom && $ctx->normalizedDebtUtilization < self::MA_MOD_UTIL_THRESHOLD && $ctx->totalBuyingPower > self::MA_LBO_MIN_POWER && $ctx->costOfNewBorrowing < self::MA_MOD_RATE_CEILING => [
                'prob' => self::MA_MOD_LEVERAGE_PROB, 'spend' => self::MA_MOD_LEVERAGE_SPEND, 'type' => $ctx->strategy->getAcquisitionType('LEVERAGED BUYOUT'), 'use_leverage' => true, 'use_stock' => false,
            ],
            default => null,
        };

        // These hazards are annual and $dt is a fraction of a year. The empire builder's own branch already
        // carries its elevated rate in MA_EMPIRE_BUILDER_PROB, so the style bias must NOT be laid on top of
        // it. Everywhere else the bias is the only thing the style says about deal frequency.
        $hazardBias = ($config['style_priced'] ?? false) ? 1.0 : $manager->acquisitionBias();

        // Second stage of the gate in evaluatePrivateAcquisition(): one uniform on [0, ceiling * dt),
        // which is the draw conditional on having passed. The primary deal takes the low end of the
        // interval and the cash fallback the band above it, sized so that P(fallback) is exactly what an
        // independent second draw would have given: (1 - p1) * p2. Both fire at their own hazards.
        $ctx->dealExecuted = false;
        $primaryHazard = $config ? $config['prob'] * $hazardBias * $ctx->dt : 0.0;
        $fallbackHazard = $ctx->excessCash > self::MA_CASH_FALLBACK_THRESHOLD
            ? self::MA_CASH_FALLBACK_PROB * $manager->acquisitionBias() * $ctx->dt
            : 0.0;
        $draw = $this->mathUtility->generateUniform() * self::acquisitionHazardCeiling() * $ctx->dt;

        if ($config && $draw < $primaryHazard) {
            $ctx->dealExecuted = true;
        } elseif ($fallbackHazard > 0.0 && $draw < $primaryHazard + ((1.0 - $primaryHazard) * $fallbackHazard)) {
            $config = [
                'prob' => self::MA_CASH_FALLBACK_PROB, 'spend' => self::MA_CASH_FALLBACK_SPEND, 'type' => self::STRATEGIC_ACQUISITION, 'use_leverage' => false, 'use_stock' => false,
            ];
            $ctx->dealExecuted = true;
        }

        if (!$ctx->dealExecuted || !$config) {
            $ctx->dealExecuted = false;
            return;
        }

        $minOperatingCash = $ctx->strategy->calculateMinOperatingCash($ctx->operatingBase, (float) $stock->getCustomerDeposits(), (float) $stock->getWholesaleDebt());

        $customerDeposits = (float) $stock->getCustomerDeposits();
        if ($customerDeposits > 0.0) {
            $floatReserve = $customerDeposits;
            $ctx->usableTreasury = max(0.0, $ctx->treasury - max($minOperatingCash, $floatReserve));
        } else {
            $ctx->usableTreasury = max(0.0, $ctx->treasury - $minOperatingCash);
        }

        $ctx->availableCapital = $config['use_stock'] ? ($ctx->price * $ctx->shares * self::MA_STOCK_DILUTION_FRACTION) : ($config['use_leverage'] ? ($ctx->usableTreasury + $ctx->borrowingCapacity) : $ctx->usableTreasury);
        $ctx->spendFraction = $this->mathUtility->generateUniformBetween(self::MA_SPEND_DRAW_MIN, 1.0) * $config['spend'];
        $ctx->purchasePrice = $ctx->availableCapital * $ctx->spendFraction;
        $ctx->bindingLimit = self::LIMIT_BUYING_POWER;

        $this->capPurchasePrice($ctx, $ctx->strategy->applyMaSpendCap($ctx->purchasePrice, $stock->getTangibleEquity()), self::LIMIT_SPEND_CAP);

        if ($ctx->purchasePrice < self::MA_MIN_DEAL_SIZE) {
            $ctx->dealExecuted = false;
            return;
        }

        $ctx->config = $config;
        $ctx->target = PrivateCompany::procedural($this->mathUtility);
        // A company none of whose markets wants more plant buys into an industry next to its own where one is worth
        // it; otherwise a company that keeps plant by segment buys into one of its markets, drawn in proportion to its
        // plant.
        $ctx->entry = $this->chooseEntryMarket($ctx);
        // Drawn for every company that keeps plant by segment, entering or not, so the keyed choice of a new industry
        // moves no later draw on the stream.
        $segmentDraw = SegmentBooks::plant($stock->getEarningsMomentumZ() ?? []) !== null ? $this->mathUtility->generateUniform() : null;
        if ($ctx->entry !== null) {
            $ctx->segment = Segments::enteredKey($ctx->entry['industry']);
        } elseif ($segmentDraw !== null) {
            $ctx->segment = SegmentBooks::acquiringSegment($stock, $segmentDraw);
        }
        $this->drawTargetEconomics($ctx);

        // The target is a private firm in the market the acquirer buys into, so the deal is sized to the largest
        // one there is to buy and that clears merger review.
        $market = $this->resolveLargestAvailableTarget($ctx);
        $this->capPurchasePrice($ctx, $market['price'], $market['limit']);
        $this->capPurchasePrice($ctx, $this->resolveLargestCapitalClearedDeal($ctx), self::LIMIT_CAPITAL_TEST);
        $this->capPurchasePrice($ctx, $this->resolveLargestCovenantClearedDeal($ctx), self::LIMIT_COVENANT);
        if ($ctx->purchasePrice < self::MA_MIN_DEAL_SIZE || !$this->addsToEarningsPerShare($ctx)) {
            $ctx->dealExecuted = false;
        }
    }

    /**
     * Whether a deal that borrows adds to earnings per share: the after-tax operating profit it buys at the price paid
     * against the after-tax interest on the new debt and on the cash it spends. Managers screen deals on accretion
     * (Graham, Harvey & Rajgopal 2005), so borrowed buying follows the acquirer's own credit: a wider spread, a fallen
     * share price or the debt of the last deal makes the next one dilutive. A deal paid from cash or in shares is not
     * screened.
     */
    private function addsToEarningsPerShare(AcquisitionContext $ctx): bool
    {
        if (!($ctx->config['use_leverage'] ?? false) || $ctx->purchasePrice <= $ctx->usableTreasury) {
            return true;
        }

        $debt = $ctx->purchasePrice - $ctx->usableTreasury;
        $interest = ($debt * $this->priceDealDebt($ctx)) + ($ctx->usableTreasury * $ctx->macroState->policyRate);
        $afterTax = 1.0 - $ctx->strategy->getEffectiveTaxRate($ctx->macroState->corporateTaxRate);

        return $this->valueTarget($ctx, $ctx->purchasePrice)['nopat'] > $interest * $afterTax;
    }

    /**
     * The rate the deal's notes price at: the rating the pro-forma balance sheet carries, with the combined EBITDA the
     * acquirer's trailing figure plus the operating income the target is bought for.
     */
    private function priceDealDebt(AcquisitionContext $ctx): float
    {
        $proFormaEbitda = $ctx->health->rawMetrics->ebitda + $this->valueTarget($ctx, $ctx->purchasePrice)['operating_income'];

        return $this->debtEngine->priceAcquisitionDebt($ctx->acquirer, $ctx->macroState, $ctx->currentDebt - $ctx->treasury, $ctx->purchasePrice, $proFormaEbitda);
    }

    /** Cuts the price to a limit below it and records that limit as the one the deal was sized to. */
    private function capPurchasePrice(AcquisitionContext $ctx, float $limit, string $reason): void
    {
        if ($limit < $ctx->purchasePrice) {
            $ctx->purchasePrice = $limit;
            $ctx->bindingLimit = $reason;
        }
    }

    /**
     * The industry a deal takes its buyer into when none of its own businesses earns its cost of capital on its plant
     * (SegmentBooks::paysAnywhere()): one next to a business it runs (EntryMarkets), drawn in proportion to what a
     * business there is worth to it per unit of plant (SegmentEntry::value()). A going concern brings the skills of the
     * trade with it, so the buyer expects the share of the incumbents' margin a private firm earns wherever it buys,
     * with nothing to learn, and a builder's distance from home does not weigh on it: firms build next door and buy far
     * away (Yip 1982; Lee & Lieberman 2010). A holding company, whose business is owning others, looks outside whenever
     * it deals. Either takes on one new industry at a time (SegmentBooks::isDiversifying()).
     * Null where no market is worth entering, or for a buyer that keeps no plant ledger, or a financial, or before a
     * year of reports.
     *
     * @return array{industry: string, closeness: float, margin: float, turnover: float, market: float, room: float, leader: float}|null
     */
    private function chooseEntryMarket(AcquisitionContext $ctx): ?array
    {
        $stock = $ctx->acquirer;
        $ledger = $this->industryShareLedger;
        if ($ledger === null || $ctx->strategy->isFinancial() || $stock->getGrossPpe() === null || !SegmentBooks::canEnter($stock)
            || $ctx->macroState->totalTime < SegmentEntry::FIRST_ENTRY_YEARS || SegmentBooks::isDiversifying($stock)
            || (!IndustryAdjacency::isHoldingCompany((string) $stock->getIndustry()) && SegmentBooks::paysAnywhere($stock))) {
            return null;
        }

        $hurdle = $stock->getManagementProfile()->appliedHurdle($ctx->hurdleRate);
        $longRunGrowth = MacroConstants::TREND_REAL_GROWTH + max(0.0, $ctx->macroState->inflationEma);
        $afterTax = 1.0 - $ctx->strategy->getEffectiveTaxRate($ctx->macroState->corporateTaxRate);
        $expectedShare = (self::MA_TARGET_MARGIN_SHARE_MIN + self::MA_TARGET_MARGIN_SHARE_MAX) / 2.0;
        $markets = [];
        $values = [];
        foreach ((new EntryMarkets($ledger))->survey($stock, $ctx->macroState, $ctx->tickCount, $ctx->ticksPerYear) as $industry => $market) {
            if ($market['room'] <= 0.0) {
                continue;
            }
            $markets[$industry] = ['industry' => $industry, 'closeness' => $market['closeness'], 'margin' => $market['margin'], 'turnover' => $market['turnover'], 'market' => $market['market'], 'room' => $market['room'], 'leader' => $market['leader']];
            $values[$industry] = SegmentEntry::value($afterTax * $market['turnover'] * $market['margin'] * $expectedShare, $hurdle, $longRunGrowth, $market['excess'], 0.0);
        }
        $industry = SegmentEntry::choose($values, MathUtility::hashedUniform($ledger->runSalt(), "entry-deal:{$stock->getTicker()}:{$ctx->tickCount}"));

        return $industry === null ? null : $markets[$industry];
    }

    /** The target's economics: synergy with the acquirer, standalone return and operating margin. */
    private function drawTargetEconomics(AcquisitionContext $ctx): void
    {
        $ctx->synergyMultiplier = $this->mathUtility->calculateLogNormalSynergy(self::MA_SYNERGY_MU, self::MA_SYNERGY_SIGMA);

        $targetRoicDraw = $this->mathUtility->calculateLogNormalSynergy(self::MA_TARGET_ROIC_MU, self::MA_TARGET_ROIC_SIGMA);
        $ctx->targetRoic = max(self::MA_TARGET_ROIC_FLOOR, min(self::MA_TARGET_ROIC_CEILING, $targetRoicDraw));

        // A target earns like the business it joins: the segment bought into, for a company that keeps plant by
        // segment, or in a new industry its incumbents' margin and turnover. One added to a business the company
        // entered in play is an incumbent there too, and earns as they do, not what its new owner turned out to earn.
        $economics = SegmentBooks::companyEconomics($ctx->acquirer->getEarningsMomentumZ() ?? []);
        $entered = $ctx->entry === null ? Segments::enteredIndustry($ctx->segment) : null;
        $peers = $entered !== null ? $this->industryShareLedger?->resolvePeerEconomics($entered, $ctx->acquirer->getTicker(), $ctx->tickCount, $ctx->ticksPerYear) : null;
        $joinedMargin = $ctx->entry['margin'] ?? $peers['margin'] ?? $economics['margins'][$ctx->segment] ?? (float) $ctx->acquirer->getOperatingMargin();
        $ctx->targetMargin = max(0.01, $joinedMargin * $this->mathUtility->generateUniformBetween(self::MA_TARGET_MARGIN_SHARE_MIN, self::MA_TARGET_MARGIN_SHARE_MAX));
        $ctx->targetTurnover = $ctx->entry['turnover'] ?? $peers['turnover'] ?? $economics['turnovers'][$ctx->segment] ?? null;
    }

    /**
     * Price of the largest target the acquirer's market holds for it, and the limit that set it: the fringe still off
     * the board, capped at the share merger review clears. A buyer from outside an industry overlaps no firm in it, so
     * review clears the deal (a conglomerate merger), and it buys the largest firm off the board, no larger than the
     * largest listed one, or what the fringe leaves, whichever is less. Unbounded when the ledger has not priced the
     * firm's market.
     *
     * @return array{price: float, limit: string}
     */
    private function resolveLargestAvailableTarget(AcquisitionContext $ctx): array
    {
        $revenuePerDollar = $this->valueTarget($ctx, 1.0)['revenue'];
        $price = static fn(float $marketRevenue): float => $revenuePerDollar > 0.0 ? $marketRevenue / $revenuePerDollar : INF;
        if ($ctx->entry !== null) {
            $largest = min(self::MA_LARGEST_PRIVATE_SHARE * $ctx->entry['market'], $ctx->entry['leader'] > 0.0 ? $ctx->entry['leader'] : INF);
            $room = $ctx->entry['room'] * $ctx->entry['market'];

            return [
                'price' => $price(min($room, $largest)),
                'limit' => $room < $largest ? self::LIMIT_AVAILABLE_MARKET : self::LIMIT_LARGEST_TARGET,
            ];
        }

        $market = $this->industryShareLedger?->describeMergerMarket(
            $ctx->acquirer,
            $ctx->macroState,
            IndustryShareLedger::secularExcessGrowth($this->segmentStrategy($ctx->acquirer, $ctx->segment), $ctx->acquirer),
            $ctx->tickCount,
            $ctx->ticksPerYear,
            $ctx->segment,
        );
        if ($market === null) {
            return ['price' => INF, 'limit' => self::LIMIT_AVAILABLE_MARKET];
        }

        $reviewShare = MergerReview::maxClearedTargetShare($market['acquirer_share'], $market['herfindahl'], $ctx->macroState->mergerReviewLeniency);

        return [
            'price' => $price(min($market['fringe_share'], $reviewShare) * $market['market_revenue']),
            'limit' => $reviewShare < $market['fringe_share'] ? self::LIMIT_MERGER_REVIEW : self::LIMIT_AVAILABLE_MARKET,
        ];
    }

    /**
     * The largest deal the acquirer's prudential regulator approves (MergerReview::capitalClearedPrice()), struck on
     * its tangible capital base and the goodwill and leases each dollar of price brings, at the floor of the grey
     * zone. Paid in shares a deal adds its net assets to both sides and only raises the ratio, so it is unbounded,
     * as it is for a firm the capital ratio does not govern.
     */
    private function resolveLargestCapitalClearedDeal(AcquisitionContext $ctx): float
    {
        if (!$ctx->strategy->getRatingMethodology()->scoresSolvencyOnCapital() || ($ctx->config['use_stock'] ?? false)) {
            return INF;
        }

        $floor = $ctx->strategy->getWarningEquityThreshold() / 100.0;
        $base = $this->debtEngine->resolveTangibleCapitalBase($ctx->acquirer, (float) $ctx->acquirer->getTotalRevenue());
        $perDollar = $this->valueTarget($ctx, 1.0);
        $goodwillPerDollar = max(0.0, 1.0 - $perDollar['net_assets']);
        $leasePerDollar = $this->corporateMetrics->calculateLeaseLiability($perDollar['revenue'], $ctx->strategy->getLeaseIntensity($ctx->acquirer));

        return MergerReview::capitalClearedPrice($base['capital'], $base['assets'], $floor, $goodwillPerDollar, $leasePerDollar, $ctx->usableTreasury);
    }

    /**
     * The largest borrowed deal after which funded net debt stays at the covenant multiple of the combined firm's
     * normal EBITDA, the acquirer's plus the operating income the target is bought for: a price P moves net debt by P,
     * cash or debt, so ND + P <= k (N + aP). The lender tests the merged business at normal earnings, not the
     * acquirer's trailing book. A cash or stock deal borrows nothing, and a target whose own earnings carry its price
     * at the multiple is not limited by it.
     */
    private function resolveLargestCovenantClearedDeal(AcquisitionContext $ctx): float
    {
        if (!($ctx->config['use_leverage'] ?? false)) {
            return INF;
        }

        $multiple = $this->debtEngine->resolveCovenantLeverageMultiple($ctx->acquirer, $ctx->strategy);
        $normalEbitda = $multiple !== null
            ? $ctx->strategy->getNormalEbitda($ctx->acquirer, $ctx->macroState->corporateTaxRate, $ctx->health->rawMetrics->depreciation)
            : null;
        if ($multiple === null || $normalEbitda === null) {
            return INF;
        }

        return self::covenantClearedPrice($multiple, $normalEbitda, $ctx->currentDebt - $ctx->treasury, $this->valueTarget($ctx, 1.0)['operating_income']);
    }

    /**
     * The price P at which net debt ND + P reaches the multiple k of the combined EBITDA N + aP, where a is the
     * operating income each unit of price buys: P = (kN - ND) / (1 - ka). Unbounded once the target's own earnings
     * carry its price at the multiple (ka >= 1); nothing when the acquirer is already there.
     */
    public static function covenantClearedPrice(float $multiple, float $normalEbitda, float $netDebt, float $ebitdaPerUnitPrice): float
    {
        $slack = 1.0 - ($multiple * $ebitdaPerUnitPrice);
        if ($slack <= 0.0) {
            return INF;
        }

        return max(0.0, (($multiple * $normalEbitda) - $netDebt) / $slack);
    }

    /**
     * What a deal at this price buys: the target's standalone value (the price less the hubris premium, Roll
     * 1986), its after-tax return on that value, the operating income and revenue that return implies at the
     * target's margin, and the identifiable net assets behind it; the rest of the price is goodwill. Sizing a
     * deal and booking it read the same figures.
     *
     * @return array{economic_value: float, nopat: float, operating_income: float, revenue: float, net_assets: float}
     */
    private function valueTarget(AcquisitionContext $ctx, float $price): array
    {
        $economicValue = $price / (1.0 + max(0.0, $ctx->hubrisPremium));
        $nopat = $economicValue * $ctx->targetRoic * $ctx->synergyMultiplier;
        // ROIC is after tax; the earnings engine rebuilds revenue from margin x (1 - t) x turnover, so the
        // target's return is grossed up to operating income before it is split into revenue and cost.
        $operatingIncome = $nopat / max(0.01, 1.0 - $ctx->strategy->getEffectiveTaxRate($ctx->macroState->corporateTaxRate));

        $revenue = $operatingIncome / max(0.01, $ctx->targetMargin);

        return [
            'economic_value' => $economicValue,
            'nopat' => $nopat,
            'operating_income' => $operatingIncome,
            'revenue' => $revenue,
            // Goodwill allocation (ASC 805): the target's assets are worth what they earn at the acquirer's hurdle; a
            // return above the hurdle is franchise value the premium pays for. A target in one of the buyer's segments
            // runs that segment's plant for its sales, and what is paid beyond it is the franchise.
            'net_assets' => $ctx->targetTurnover !== null && $ctx->targetTurnover > 0.0
                ? min($economicValue, $revenue / $ctx->targetTurnover)
                : $economicValue * min(1.0, max(0.01, $ctx->hurdleRate) / max(0.01, $ctx->targetRoic * $ctx->synergyMultiplier)),
        ];
    }

    private function fundAcquisition(AcquisitionContext $ctx): void
    {
        $stock = $ctx->acquirer;
        $config = $ctx->config;

        if ($config['use_stock']) {
            $offeringPrice = $ctx->price * (1.0 - self::MA_STOCK_UNDERPRICING);
            $ctx->sharesIssued = $ctx->purchasePrice / max(0.01, $offeringPrice);
            $stock->setSharesOutstanding((string) ($ctx->shares + $ctx->sharesIssued));
            // Sellers paid in the acquirer's stock distribute it; that supply reaches the tape through the
            // flow channel rather than vanishing into the share count.
            $stock->addCorporateFlowBacklog(-$ctx->sharesIssued);
        } elseif ($ctx->purchasePrice <= $ctx->usableTreasury) {
            $stock->setCorporateTreasury((string) ($ctx->treasury - $ctx->purchasePrice));
        } else {
            $ctx->debtIssued = $ctx->purchasePrice - $ctx->usableTreasury;
            $stock->setCorporateTreasury((string) ($ctx->treasury - $ctx->usableTreasury));

            $ctx->costOfNewDebt = $this->priceDealDebt($ctx);

            $this->debtEngine->issueDebt($stock, $ctx->debtIssued, $ctx->costOfNewDebt);
        }
    }

    private function applyAcquisitionSynergies(AcquisitionContext $ctx): void
    {
        $stock = $ctx->acquirer;
        // A buyer entering a new industry opens books for its plant if it kept none, at its economics before the deal,
        // as one that builds its way in does.
        if ($ctx->entry !== null) {
            (new SegmentBookOpener($this->corporateMetrics, $this->industryShareLedger))->openForEntry($stock, $ctx->strategy, $ctx->macroState, $ctx->tickCount, $ctx->ticksPerYear);
        }

        // Purchase accounting (ASC 805): the deal creates no day-one equity. Cash and debt deals swap cash for
        // net assets plus goodwill; stock deals add the shares issued to equity. Expected synergies only
        // reach the books as they are earned, through the blended ROIC below; the market prices them at once.
        $equityAddedByStock = $ctx->config['use_stock'] ? $ctx->purchasePrice : 0.0;
        // The deal's NPV to the acquirer: what the target is worth to it (its standalone value with the
        // synergies) less what was paid. The hubris premium is paid away (Roll 1986), so an overpayment is
        // value destroyed even when the synergies are real.
        $ctx->synergyValueCreation = ($this->valueTarget($ctx, $ctx->purchasePrice)['economic_value'] * $ctx->synergyMultiplier) - $ctx->purchasePrice;
        $ctx->newEquity = $ctx->equity + $equityAddedByStock;
        $stock->setTotalEquity((string) $ctx->newEquity);

        $oldOperatingMargin = (float) $stock->getOperatingMargin();
        // The acquirer as it stood before the deal. Funding has already moved the cash, debt or shares that
        // paid for it, so invested capital read now would count the purchase price twice.
        $oldCapitalBase = $ctx->strategy->getEvaluationCapital($ctx->equity, $ctx->preDealInvestedCapital);
        $oldRevenueCapital = CorporateMetrics::revenueGeneratingCapital(
            $ctx->preDealInvestedCapital,
            $stock->getTotalCipAmount(),
            (float) $stock->getGoodwill(),
        );
        $acquirerTurnover = $ctx->strategy->isFinancial() ? null : $stock->getAssetTurnover();
        $oldStructuralRevenue = $acquirerTurnover !== null
            ? $oldRevenueCapital * (float) $acquirerTurnover
            : (float) $stock->getTotalRevenue();

        $effectiveTargetRoic = $ctx->targetRoic * $ctx->synergyMultiplier;

        // Hubris hypothesis: purchase price premium over standalone economic value is capitalized into goodwill.
        $acquired = $this->valueTarget($ctx, $ctx->purchasePrice);
        $economicValue = $acquired['economic_value'];
        $roicOnPricePaid = $effectiveTargetRoic * ($economicValue / max(1.0, $ctx->purchasePrice));

        $totalNewCapital = max(1.0, $oldCapitalBase + $ctx->purchasePrice);

        $ctx->strategy->blendAcquisitionDNA($stock, $oldCapitalBase, $ctx->purchasePrice, $roicOnPricePaid, $totalNewCapital);

        // Goodwill allocation: excess of purchase price over fair value of net identifiable assets.
        $netAssetsAcquired = $acquired['net_assets'];
        $ctx->goodwillRecorded = max(0.0, $ctx->purchasePrice - $netAssetsAcquired);
        $stock->setGoodwill((string) ((float) $stock->getGoodwill() + $ctx->goodwillRecorded));

        $this->bookAcquiredNetAssets($stock, $ctx->strategy, $netAssetsAcquired);

        $acquiredNopat = $acquired['nopat'];
        $acquiredOperatingIncome = $acquired['operating_income'];
        $acquiredRevenue = $acquired['revenue'];
        $currentRevenue = (float) $stock->getTotalRevenue();
        $stock->setTotalRevenue((string) ($currentRevenue + $acquiredRevenue));
        // Credit agreements measure a buyer pro forma, as if it had owned the target all year.
        ProForma::restateOperatingHistory($stock, 1.0, $acquiredRevenue, $acquiredOperatingIncome);
        // Each segment the deal buys into books its slice in its own industry (an airline bought brings its holds).
        $plant = SegmentBooks::plant($stock->getEarningsMomentumZ() ?? []);
        $slices = $plant === null ? [$ctx->segment => 1.0] : SegmentBooks::boughtSlices($plant, $ctx->segment, SegmentBooks::jointSegments($stock));
        foreach ($slices as $segment => $slice) {
            $this->industryShareLedger?->recordAcquiredCapacity($stock, $acquiredRevenue * $slice, $ctx->macroState, IndustryShareLedger::secularExcessGrowth($this->segmentStrategy($stock, $segment), $stock), $segment);
        }

        // Operating income adds across the two businesses, so the combined margin is revenue-weighted.
        $blendedMargin = (($oldStructuralRevenue * $oldOperatingMargin) + $acquiredOperatingIncome)
            / max(1.0, $oldStructuralRevenue + $acquiredRevenue);
        $stock->setOperatingMargin((string) max(0.01, $blendedMargin));
        // A private business bought brings no franchise of its own: the buyer's edge is the same profit on more
        // revenue, a smaller shift on the blended margin.
        $franchiseProfit = $oldStructuralRevenue * (FirmEconomics::franchiseMargin($oldOperatingMargin, (float) ($stock->getFranchisePower() ?? 0.0)) - $oldOperatingMargin);
        $newMargin = (float) $stock->getOperatingMargin();
        $stock->setFranchisePower(FirmEconomics::franchisePowerForMargin($newMargin, $newMargin + ($franchiseProfit / max(1.0, $oldStructuralRevenue + $acquiredRevenue))));

        // Turnover is revenue per dollar of operating capital. Only the net identifiable assets join that base:
        // the premium over them is goodwill and runs no plant (null until the engine seeds the turnover).
        if ($acquirerTurnover !== null) {
            $blendedTurnover = (($oldRevenueCapital * (float) $acquirerTurnover) + $acquiredRevenue)
                / max(1.0, $oldRevenueCapital + $netAssetsAcquired);
            $stock->setAssetTurnover((string) max(0.01, $blendedTurnover));
        }

        // A buyer that keeps plant by segment adds what it bought to the segment it bought into, the one the ledger
        // booked it in: its plant, and its revenue and income into that segment's turnover and margin. The company's
        // own are then its segments' blend again.
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        if (SegmentBooks::plant($momentum) !== null) {
            $heldBefore = ((SegmentBooks::plant($momentum) ?? [])[$ctx->segment] ?? 0.0) * $oldRevenueCapital;
            $momentum = SegmentBooks::acquireIntoBooks($momentum, $ctx->segment, $oldRevenueCapital, $netAssetsAcquired, $acquiredRevenue, $acquiredOperatingIncome, SegmentBooks::jointSegments($stock)) + $momentum;
            $stock->setEarningsMomentumZ($momentum);
            // A business bought in a new industry takes its market, its share of the revenue and its ledger record at
            // the deal, so nothing before the next report finds it missing. What it earns under its new owner shows as
            // it is integrated, and the goodwill paid for it, or for a business added to one entered in play, leaves
            // with it if it is sold.
            if ($ctx->entry !== null) {
                $outcome = SegmentEntry::boughtOutcome($ctx->entry['closeness'], MathUtility::hashedUniform($this->industryShareLedger?->runSalt() ?? 0, "entry-deal-outcome:{$stock->getTicker()}:{$ctx->tickCount}"));
                $momentum = $this->enteredMarket($ctx, $ctx->entry['market'], $acquiredRevenue) + $momentum;
                $momentum = SegmentBooks::buyIn($ctx->segment, $outcome, $ctx->goodwillRecorded) + SegmentBooks::boughtTargets($momentum, $ctx->segment) + $momentum;
                $stock->setEarningsMomentumZ($momentum);
                $this->industryShareLedger?->openAcquiredRecord($stock, $ctx->segment, $acquiredRevenue, SegmentBooks::entryMarketShare($acquiredRevenue, $ctx->entry['market'], 0.0), $ctx->macroState, $ctx->tickCount);
            } elseif (Segments::enteredIndustry($ctx->segment) !== null) {
                $key = SegmentBooks::STATE_GOODWILL . $ctx->segment;
                $kept = $heldBefore > 0.0 ? $heldBefore / ($heldBefore + max(0.0, $netAssetsAcquired)) : 0.0;
                $momentum = [$key => (float) ($momentum[$key] ?? 0.0) + $ctx->goodwillRecorded] + SegmentBooks::boughtInto($momentum, $ctx->segment, $kept) + $momentum;
                $stock->setEarningsMomentumZ($momentum);
            }
            $economics = SegmentBooks::companyEconomics($momentum);
            if ($economics !== null && $acquirerTurnover !== null) {
                $stock->setOperatingMargin((string) max(0.01, $economics['margin']));
                $stock->setAssetTurnover((string) max(0.01, $economics['turnover']));
            }
        }

        $newInterestExpense = 0.0;
        if ($ctx->debtIssued > 0) {
            $newInterestExpense = $ctx->debtIssued * $ctx->costOfNewDebt * (1.0 - $ctx->macroState->corporateTaxRate);
        }

        $trueAcquiredNetIncome = $acquiredNopat - $newInterestExpense;

        $currentEps = (float) $stock->getEarningsPerShare();
        $stock->setEarningsPerShare((string) ($currentEps + ($trueAcquiredNetIncome / max(1.0, $ctx->shares))));
    }

    /**
     * Puts the net identifiable assets acquired onto the ledgers they belong to (ASC 805). The target's trade
     * cycle joins the acquirer's receivables, inventory and payables in the acquirer's own proportions, with
     * the credit-loss allowance scaling alongside the receivables it covers. The rest is plant, carried at
     * fair value with no accumulated depreciation and, as an asset purchase, a tax basis stepped up to that
     * same cost. Without the plant the acquirer's own assets would be all it ever depreciated while the
     * acquired revenue was booked in full.
     *
     * Every dollar of net assets has to land on an asset account: that is what keeps the cash (or debt, or
     * shares) that paid for them equal to the goodwill and net assets that came in, so the balance sheet
     * still balances after the deal.
     */
    private function bookAcquiredNetAssets(Stock $stock, BusinessModelInterface $strategy, float $netAssetsAcquired): void
    {
        if ($netAssetsAcquired <= 0.0) {
            return;
        }

        if ($stock->hasEarningAssetLedger()) {
            // A lender buys a loan book and the deposits that fund it; the net assets are loans and securities.
            $stock->setEarningAssets((string) ((float) $stock->getEarningAssets() + $netAssetsAcquired));

            return;
        }

        if ($stock->getGrossPpe() === null) {
            return; // No ledger is open yet: the first report seeds the balance sheet from capital as a whole.
        }

        $acquiredWorkingCapital = 0.0;
        if ($stock->hasWorkingCapitalLedger()) {
            $workingCapitalShare = max(0.0, min(self::MA_MAX_WORKING_CAPITAL_SHARE, $strategy->getWorkingCapitalIntensity($stock)));
            $acquiredWorkingCapital = $netAssetsAcquired * $workingCapitalShare;
            $this->addWorkingCapital($stock, $acquiredWorkingCapital);
        }

        $acquiredPpe = $netAssetsAcquired - $acquiredWorkingCapital;
        $stock->setGrossPpe((string) ((float) $stock->getGrossPpe() + $acquiredPpe));
        if ($stock->getPpeTaxBasis() !== null) {
            $stock->setPpeTaxBasis((string) ((float) $stock->getPpeTaxBasis() + $acquiredPpe));
        }
    }

    /**
     * Grows the trade ledger by a given amount of net working capital, keeping the acquirer's mix of
     * receivables, inventory and payables. A supplier-funded cycle (net working capital at or below zero) has
     * no positive mix to scale, so the acquired balance is booked as billed sales awaiting collection.
     */
    private function addWorkingCapital(Stock $stock, float $amount): void
    {
        if ($amount <= 0.0) {
            return;
        }

        $currentNetWorkingCapital = (float) $stock->getNetWorkingCapital();
        if ($currentNetWorkingCapital <= 0.0) {
            $stock->setReceivables((string) ((float) $stock->getReceivables() + $amount));
            return;
        }

        $scale = 1.0 + ($amount / $currentNetWorkingCapital);
        $stock->setReceivables((string) ((float) $stock->getReceivables() * $scale));
        $stock->setReceivablesAllowance((string) ((float) $stock->getReceivablesAllowance() * $scale));
        $stock->setInventory((string) ((float) $stock->getInventory() * $scale));
        $stock->setInventoryAllowance((string) ((float) $stock->getInventoryAllowance() * $scale));
        $stock->setPayables((string) ((float) $stock->getPayables() * $scale));
    }

    /**
     * The terms a deal announcement states: how it was paid for, what went to goodwill, and, when a rule
     * rather than the buyer's appetite set its size, which rule.
     */
    private function describeDealTerms(AcquisitionContext $ctx): string
    {
        $paidInStock = ($ctx->config['use_stock'] ?? false) ? $ctx->purchasePrice : 0.0;
        $paidInCash = max(0.0, $ctx->purchasePrice - $ctx->debtIssued - $paidInStock);

        $funding = array_filter([
            $paidInCash > 0.0 ? WireFormat::money($paidInCash) . ' in cash' : null,
            $ctx->debtIssued > 0.0 ? WireFormat::money($ctx->debtIssued) . ' in new debt' : null,
            $paidInStock > 0.0 ? WireFormat::money($paidInStock) . ' in new shares' : null,
        ]);
        $terms = 'Funded with ' . implode(' and ', $funding) . '; ' . WireFormat::money($ctx->goodwillRecorded) . ' booked as goodwill.';

        $sizedByRule = in_array($ctx->bindingLimit, [self::LIMIT_SPEND_CAP, self::LIMIT_AVAILABLE_MARKET, self::LIMIT_LARGEST_TARGET, self::LIMIT_MERGER_REVIEW, self::LIMIT_CAPITAL_TEST, self::LIMIT_COVENANT], true);

        return $sizedByRule ? "{$terms} Sized to {$ctx->bindingLimit}." : $terms;
    }

    /**
     * The market of a segment a deal has just bought in a new industry, and the outlook it grows with: one in which its
     * plant holds the share of the industry's market its revenue does (SegmentBooks::open()). Its first pricing anchors
     * its record at that plant with no entry offset, since the plant was the fringe's and already supplied the market.
     *
     * @return array<string, float> Carried state.
     */
    private function enteredMarket(AcquisitionContext $ctx, float $industryMarket, float $acquiredRevenue): array
    {
        $stock = $ctx->acquirer;
        $years = $ctx->macroState->totalTime;
        $key = SegmentBooks::STATE_MARKET . $ctx->segment;
        // Its market grows with its industry's outlook, which the next report files, so it is struck with it in.
        $outlook = [SegmentBooks::STATE_OUTLOOK . $ctx->segment => $this->industryShareLedger?->resolveSegmentBeliefs($stock)[$ctx->segment] ?? 0.0];
        $stock->setEarningsMomentumZ($outlook + ($stock->getEarningsMomentumZ() ?? []));
        $firmShare = $this->corporateMetrics->calculateAddressableShare($stock, $ctx->strategy->getEvaluationCapital((float) $stock->getTotalEquity(), $stock->getInvestedCapital()), $ctx->macroState);
        $state = SegmentBooks::open(
            [$ctx->segment => (SegmentBooks::plant($stock->getEarningsMomentumZ() ?? []) ?? [])[$ctx->segment] ?? 0.0],
            CorporateMetrics::secularMarketGrowth($stock, $years),
            [$ctx->segment => SegmentBooks::marketGrowths($stock, $years)[$ctx->segment] ?? 1.0],
            $firmShare,
            [$ctx->segment => SegmentBooks::entryMarketShare($acquiredRevenue, $industryMarket, 0.0)],
        );

        return $outlook + (isset($state[$key]) ? [$key => $state[$key]] : []);
    }

    /** The model of the market a deal buys into: the acquirer's own, or its segment's industry's. */
    private function segmentStrategy(Stock $acquirer, string $segment): BusinessModelInterface
    {
        return \App\Data\Company\Sectors::strategyFor(Segments::enteredIndustry($segment) ?? SegmentBooks::industries($acquirer)[$segment] ?? (string) $acquirer->getIndustry());
    }

    private function finalizeAcquisitionEvent(AcquisitionContext $ctx): array
    {
        $purchasePrice = WireFormat::money($ctx->purchasePrice);
        $into = match (true) {
            $ctx->entry !== null => ', taking it into ' . lcfirst(SegmentBooks::label($ctx->acquirer, $ctx->segment)),
            $ctx->segment === Segments::MAIN => '',
            default => ' for its ' . lcfirst(SegmentBooks::label($ctx->acquirer, $ctx->segment)) . ' business',
        };
        // A deal that keeps the buyer in the industries it already serves buys a firm from their fringe, whatever the
        // appetite that drove it: only one that takes it into a new industry is an expansion of the group.
        $type = $ctx->config['type'] === self::CONGLOMERATE_EXPANSION && $ctx->entry === null ? self::STRATEGIC_ACQUISITION : $ctx->config['type'];
        $desc = "{$ctx->acquirer->getName()} executed a {$purchasePrice} {$type} of {$ctx->target['name']}{$into}. " . $this->describeDealTerms($ctx);

        // The announcement return is the deal's NPV over the acquirer's market value before it (Moeller,
        // Schlingemann & Stulz 2004 measure acquirer returns in exactly these dollar terms). Limited
        // liability is the only bound: the equity cannot lose more than it was worth.
        $marketCap = max(1.0, $ctx->price * $ctx->shares);
        $ctx->eventShock = max(-100.0, ($ctx->synergyValueCreation / $marketCap) * 100.0);

        $event = $this->marketEvent->publish($ctx->acquirer, $type, $desc, $ctx->eventShock, dealShareOfValue: $ctx->purchasePrice / $marketCap);

        return [
            'event' => $event,
            'shock' => $ctx->eventShock / 100.0,
            'spent' => $ctx->purchasePrice,
        ];
    }
}
