<?php

declare(strict_types=1);

namespace App\Service\Corporate;

use App\Entity\Stock;
use App\DTO\MacroStateDTO;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Event\MarketEventPublisher;
use App\Service\Math\MathUtility;
use App\Service\Math\CorporateMetrics;
use App\DTO\AcquisitionContext;
use App\DTO\DivestitureContext;
use App\Service\Model\BusinessModelInterface;

/**
 * Service responsible for executing Mergers and Acquisitions.
 * Injects fresh Market Cap into the simulation by having public titans acquire private, off-board companies.
 */
class MergerAndAcquisitionEngine
{
    // --- M&A Deal Parameters ---
    /** Minimum size for a deal to be executed. */
    public const MA_MIN_DEAL_SIZE = 1_000_000_000.0;
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
    /** Threshold for cash fallback. */
    public const MA_CASH_FALLBACK_THRESHOLD = 15_000_000_000.0;
    
    /** Minimum buying power for empire builders. */
    public const MA_EMPIRE_BUILDER_MIN_POWER = 2_000_000_000.0;
    /** Minimum buying power for LBO. */
    public const MA_LBO_MIN_POWER = 5_000_000_000.0;
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
    /** Equity cap for financial M&A. */
    public const MA_FINANCIAL_EQUITY_CAP = 0.15;
    /** Penalty to margin for indigestion. */
    public const MA_INDIGESTION_PENALTY = 0.10;
    /** Most of a target's net identifiable assets that can be trade cycle rather than plant. */
    public const MA_MAX_WORKING_CAPITAL_SHARE = 0.90;
    
    /** Loss given default for financials. */
    public const MA_LGD_FINANCIAL = 0.30;
    /** Loss given default for corporates. */
    public const MA_LGD_CORPORATE = 0.40;
    /** Maturity used in Merton's distance to default calculation. */
    public const MA_MERTON_MATURITY = 5.0;

    // --- M&A Synergy & Target Returns (Log-Normal) ---
    /** Mean of log-normal synergy. */
    public const MA_SYNERGY_MU = -0.02;
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

    // --- Divestiture Parameters ---
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

    /** P/E threshold for divestiture consideration. */
    public const DIV_PE_THRESHOLD = 30.0;
    /** Minimum net income for divestiture consideration. */
    public const DIV_MIN_NET_INCOME = 5_000_000_000.0;
    /** EVA threshold for distressed divestiture. */
    public const DIV_DISTRESS_EVA_THRESHOLD = -0.02;
    /** Return threshold for dying divestiture. */
    public const DIV_DYING_RETURN_THRESHOLD = 0.00;
    /** EVA threshold for dying divestiture. */
    public const DIV_DYING_EVA_THRESHOLD = -0.05;
    /** Return floor for distress. */
    public const DIV_DISTRESS_RETURN_FLOOR = 0.03;

    /** Cash fortress ratio to prevent divestitures. */
    public const DIV_CASH_FORTRESS_RATIO = 0.10;
    /** ROIC bump from distressed divestiture. */
    public const DIV_DISTRESS_ROIC_BUMP = 0.50;
    /** Margin bump from distressed divestiture. */
    public const DIV_DISTRESS_MARGIN_BUMP = 0.30;

    /** Minimum cents on the dollar for fire sale. */
    public const DIV_FIRE_SALE_MIN_CENTS = 0.40;
    /** Maximum cents on the dollar for fire sale. */
    public const DIV_FIRE_SALE_MAX_CENTS = 0.80;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MarketEventPublisher $marketEvent,
        private DebtEngine $debtEngine,
        private MathUtility $mathUtility,
        private CorporateMetrics $corporateMetrics
    ) {}

    // =========================================================================
    // ACQUISITION PIPELINE
    // =========================================================================

    public function evaluatePrivateAcquisition(Stock $acquirer, MacroStateDTO $macroState, float $dt): ?array
    {
        if ($acquirer->isBankrupt()) {
            return null;
        }

        $ctx = new AcquisitionContext($acquirer, $macroState, $dt);

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
        $ctx->currentDebt = (float) $stock->getTotalDebt();
        $ctx->policyRate = $ctx->macroState->policyRate;
        $ctx->yield5y = $ctx->macroState->yield5yEma;

        $ctx->health = $this->debtEngine->analyzeDebtHealth($stock, $ctx->macroState);
        
        $ctx->industry = $stock->getIndustry() ?: 'General';
        $ctx->businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$ctx->industry]['business_model'] ?? 'none';
        $ctx->strategy = \App\Data\Sectors::getBusinessModelStrategy($ctx->businessModel);
    }

    private function shouldAbortAcquisition(AcquisitionContext $ctx): bool
    {
        if ($ctx->health->wantsToPaydownDebt) {
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
        $ctx->costOfNewBorrowing = $ctx->health->rawMetrics->currentMarketRate ?? ($ctx->yield5y + (float) $stock->getCreditSpread());

        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$ctx->industry]['equity_limit'] ?? 1.0;
        
        $ctx->maxAllowableDebt = $ctx->equity * $equityLimit;
        $ctx->borrowingCapacity = max(0.0, $ctx->maxAllowableDebt - $ctx->currentDebt);
        $ctx->totalBuyingPower = $ctx->treasury + $ctx->borrowingCapacity;

        $ctx->targetCash = $stock->getManagementProfile()->appliedTargetCash($ctx->strategy->calculateTargetOperatingCash($ctx->operatingBase, (float) $stock->getCustomerDeposits(), (float) $stock->getWholesaleDebt()));
        $hoardStatus = $ctx->strategy->evaluateHoardingStatus($ctx->treasury, $ctx->targetCash, $manager->appliedHoardingBase($ctx->operatingBase), $ctx->currentDebt);
        $ctx->excessCash = $hoardStatus['excess_cash'];
        $ctx->isHoarder = $hoardStatus['is_hoarder'];
        $ctx->isMegaHoarder = $hoardStatus['is_mega_hoarder'];
        
        $ctx->normalizedDebtUtilization = $ctx->debtRatio / max(0.1, $equityLimit);
        
        $ctx->eps = (float) $stock->getEarningsPerShare();
        $ctx->currentPE = $ctx->eps > 0 ? ($ctx->price / $ctx->eps) : 9999.0;
        
        $ctx->trueReturn = $ctx->strategy->getTrueReturn($stock);
        $ctx->hurdleRate = $ctx->strategy->getHurdleRate($ctx->health);
        $ctx->economicSpread = $ctx->trueReturn - $ctx->hurdleRate;
        
        $ctx->fairValuePE = $this->mathUtility->calculateManagementFairValuePE(
            $ctx->hurdleRate,
            $ctx->trueReturn,
            $ctx->strategy->getSecularGrowthRate($stock),
            $ctx->macroState->outputGap,
            $ctx->health->leveredBeta,
            $ctx->macroState->inflation,
            $ctx->strategy->getMoatSpread(),
            \App\Data\Sectors::baselineIndustryPe($stock->getIndustry()),
            (float) ($stock->getAccrualsRatio() ?? 0.0)
        );
        $ctx->bookValuePerShare = max(0.01, $ctx->equity / max(1.0, $ctx->shares));
        $ctx->priceToBook = $ctx->price / $ctx->bookValuePerShare;
        $ctx->isOvervalued = $ctx->economicSpread > 0.0 && $ctx->currentPE > ($ctx->fairValuePE * 1.5) && $ctx->currentPE > 25.0 && $ctx->priceToBook > 2.0;
        
        $ctx->aggression = 1.0;
        $ctx->isEmpireBuilder = $style === \App\Data\ManagementStyle::EmpireBuilder;
        $ctx->hubrisPremium = $manager->hubrisPremium();
        
        $config = match (true) {
            // Morck, Shleifer & Vishny (1990): for this manager the deal IS the objective, not a use for
            // spare capacity, so the branch is read ahead of the hoarding and leverage reads rather than
            // behind them. Both constants below were already declared and had been unreachable for as long
            // as the flag above was hardcoded false — this is the branch they were written for.
            $ctx->isEmpireBuilder && $ctx->totalBuyingPower > self::MA_EMPIRE_BUILDER_MIN_POWER => [
                'prob' => self::MA_EMPIRE_BUILDER_PROB, 'spend' => 0.55, 'type' => $ctx->strategy->getAcquisitionType('CONGLOMERATE EXPANSION'), 'use_leverage' => true, 'use_stock' => false, 'style_priced' => true
            ],
            $ctx->isOvervalued => [
                'prob' => self::MA_OVERVALUED_PROB, 'spend' => 0.50, 'type' => 'STOCK-FOR-STOCK MERGER', 'use_leverage' => false, 'use_stock' => true
            ],
            $ctx->isMegaHoarder => [
                'prob' => self::MA_MEGA_HOARDER_PROB, 'spend' => 0.60, 'type' => $ctx->strategy->getAcquisitionType('CONGLOMERATE EXPANSION'), 'use_leverage' => false, 'use_stock' => false
            ],
            $ctx->isHoarder => [
                'prob' => self::MA_HOARDER_PROB, 'spend' => 0.40, 'type' => $ctx->strategy->getAcquisitionType('CONGLOMERATE EXPANSION'), 'use_leverage' => false, 'use_stock' => false
            ],
            $ctx->health->canIssueDebt && $ctx->normalizedDebtUtilization < self::MA_LOW_UTIL_THRESHOLD && $ctx->totalBuyingPower > self::MA_LBO_MIN_POWER && $ctx->costOfNewBorrowing < self::MA_LOW_RATE_CEILING => [
                'prob' => self::MA_LOW_LEVERAGE_PROB, 'spend' => 0.40, 'type' => $ctx->strategy->getAcquisitionType('LEVERAGED BUYOUT'), 'use_leverage' => true, 'use_stock' => false
            ],
            $ctx->health->canIssueDebt && $ctx->normalizedDebtUtilization < self::MA_MOD_UTIL_THRESHOLD && $ctx->totalBuyingPower > self::MA_LBO_MIN_POWER && $ctx->costOfNewBorrowing < self::MA_MOD_RATE_CEILING => [
                'prob' => self::MA_MOD_LEVERAGE_PROB, 'spend' => 0.30, 'type' => $ctx->strategy->getAcquisitionType('LEVERAGED BUYOUT'), 'use_leverage' => true, 'use_stock' => false
            ],
            default => null,
        };

        $ctx->dealExecuted = false;

        // These hazards are annual and $dt is a fraction of a year. The empire builder's own branch already
        // carries its elevated rate in MA_EMPIRE_BUILDER_PROB, so the style bias must NOT be laid on top of
        // it — that counted the same behaviour twice and put a $1B+ transformative deal on the tape nearly
        // twice a year. Everywhere else the bias is the only thing the style says about deal frequency.
        $hazardBias = ($config['style_priced'] ?? false) ? 1.0 : $manager->acquisitionBias();

        if ($config && $this->mathUtility->checkProbability($config['prob'] * $hazardBias * $ctx->dt)) {
            $ctx->dealExecuted = true;
        }
        
        if (!$ctx->dealExecuted && $ctx->excessCash > self::MA_CASH_FALLBACK_THRESHOLD) {
            $config = [
                'prob' => self::MA_CASH_FALLBACK_PROB, 'spend' => 0.20, 'type' => 'STRATEGIC ACQUISITION', 'use_leverage' => false, 'use_stock' => false
            ];
            if ($this->mathUtility->checkProbability($config['prob'] * $manager->acquisitionBias() * $ctx->dt)) {
                $ctx->dealExecuted = true;
            }
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
        $ctx->spendFraction = $this->mathUtility->generateUniformBetween(0.50, 1.0) * $config['spend'];
        $ctx->purchasePrice = $ctx->availableCapital * $ctx->spendFraction;

        $ctx->maxPrivateCompanyValue = max((float) mt_rand(100, 500) * 1_000_000_000.0, $ctx->availableCapital * 0.50);
        $ctx->purchasePrice = min($ctx->purchasePrice, $ctx->maxPrivateCompanyValue);
        
        $ctx->purchasePrice = $ctx->strategy->applyMaSpendCap($ctx->purchasePrice, $ctx->equity, $ctx->isMegaHoarder, $ctx->isEmpireBuilder);
        
        if ($ctx->purchasePrice < self::MA_MIN_DEAL_SIZE) {
            $ctx->dealExecuted = false;
            return;
        }

        $ctx->config = $config;
        $ctx->target = $this->generateProceduralTarget();
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
            
            $equityVolatility = (float) ($stock->getCurrentVolatility() ?? $stock->getVolatility());
            $equityVolatility = max(0.05, $equityVolatility);
            
            $marketCap = max(1.0, (float) $stock->getPrice() * max(1.0, (float) $stock->getSharesOutstanding()));
            $currentNetDebt = max(0.0, $ctx->currentDebt - $ctx->treasury);
            $newNetDebt = $currentNetDebt + $ctx->debtIssued;
            
            $newAssetValue = $marketCap + $currentNetDebt + $ctx->purchasePrice;
            $newAssetVolatility = $equityVolatility * ($marketCap / $newAssetValue);
            $newAssetVolatility = max(0.02, $newAssetVolatility);
            
            $lossGivenDefault = $ctx->strategy->getLossGivenDefault();
            
            $distanceToDefault = $this->mathUtility->calculateDistanceToDefault(
                $newAssetValue,
                max(0.01, $newNetDebt),
                $newAssetVolatility,
                $ctx->policyRate,
                self::MA_MERTON_MATURITY
            );
            
            $projectedSpread = $this->mathUtility->calculateMertonCreditSpread($distanceToDefault, $lossGivenDefault, self::MA_MERTON_MATURITY);
            
            $baselineCreditSpread = (float) $stock->getCreditSpread();
            $dynamicSpread = $baselineCreditSpread + $projectedSpread;
            
            $ctx->costOfNewDebt = $ctx->yield5y + $dynamicSpread;
            
            $this->debtEngine->issueDebt($stock, $ctx->debtIssued, $ctx->costOfNewDebt);
        }
    }

    private function applyAcquisitionSynergies(AcquisitionContext $ctx): void
    {
        $stock = $ctx->acquirer;

        $mu = self::MA_SYNERGY_MU;
        $sigma = self::MA_SYNERGY_SIGMA;

        $ctx->synergyMultiplier = $this->mathUtility->calculateLogNormalSynergy($mu, $sigma);

        // Purchase accounting (ASC 805): the deal creates no day-one equity. Cash and debt deals swap cash for
        // net assets plus goodwill; stock deals add the shares issued to equity. Expected synergies only
        // reach the books as they are earned, through the blended ROIC below; the market prices them at once.
        $equityAddedByStock = $ctx->config['use_stock'] ? $ctx->purchasePrice : 0.0;
        $ctx->synergyValueCreation = $ctx->purchasePrice * ($ctx->synergyMultiplier - 1.0);
        $ctx->newEquity = $ctx->equity + $equityAddedByStock;
        $stock->setTotalEquity((string) $ctx->newEquity);

        $oldOperatingMargin = (float) $stock->getOperatingMargin();
        $oldInvestedCapital = $stock->getInvestedCapital();
        $oldCapitalBase = $ctx->strategy->getEvaluationCapital($ctx->equity, $oldInvestedCapital);
        
        $targetRoicDraw = $this->mathUtility->calculateLogNormalSynergy(self::MA_TARGET_ROIC_MU, self::MA_TARGET_ROIC_SIGMA);
        $targetRoic = max(self::MA_TARGET_ROIC_FLOOR, min(self::MA_TARGET_ROIC_CEILING, $targetRoicDraw));
        $effectiveTargetRoic = $targetRoic * $ctx->synergyMultiplier;
        
        $targetMargin = max(0.01, $oldOperatingMargin * (mt_rand(70, 95) / 100.0));

        // Roll's (1986) hubris hypothesis: the winning bidder is the one that most overestimates the target,
        // and the difference is simply paid. So the price and the thing bought are two separate quantities
        // here — the business is worth its standalone value and earns on that, while the acquirer's capital
        // goes out at the price. The overpayment buys no earnings at all: it lands in goodwill, drags the
        // blended return down by exactly the capital it consumed, and waits for the annual impairment test.
        // The realised synergy above is drawn independently and is NOT touched, because hubris is an error
        // in the estimate rather than in the outcome.
        $economicValue = $ctx->purchasePrice / (1.0 + max(0.0, $ctx->hubrisPremium));
        $roicOnPricePaid = $effectiveTargetRoic * ($economicValue / max(1.0, $ctx->purchasePrice));

        $totalNewCapital = max(1.0, $oldCapitalBase + $ctx->purchasePrice);
        
        $ctx->strategy->blendAcquisitionDNA($stock, $oldCapitalBase, $ctx->purchasePrice, $roicOnPricePaid, $totalNewCapital);

        // Goodwill is the premium over the fair value of net identifiable assets. At a no-growth justified
        // price-to-book of ROIC / hurdle (residual income identity), net assets acquired are the target's
        // standalone value scaled by hurdle / ROIC and the remainder is goodwill, tested annually for
        // impairment. Net assets scale with what was bought, never with what was paid — that is what puts
        // the whole overpayment into goodwill instead of quietly capitalising it as plant.
        $netAssetsAcquired = $economicValue * min(1.0, max(0.01, $ctx->hurdleRate) / max(0.01, $effectiveTargetRoic));
        $ctx->goodwillRecorded = max(0.0, $ctx->purchasePrice - $netAssetsAcquired);
        $stock->setGoodwill((string) ((float) $stock->getGoodwill() + $ctx->goodwillRecorded));

        $this->bookAcquiredNetAssets($stock, $ctx->strategy, $netAssetsAcquired);
        
        // Operating margin blends on the revenue-generating base, which is the business acquired and not the
        // cheque written for it: overpaying destroys return on capital, it does not make the target's costs
        // any worse. Both weights therefore run on standalone value, and with no premium this is the
        // identity it always was.
        $marginBlendCapital = max(1.0, $oldCapitalBase + $economicValue);
        $blendedMargin = (($oldCapitalBase * $oldOperatingMargin) + ($economicValue * $targetMargin)) / $marginBlendCapital;
        $stock->setOperatingMargin((string) max(0.01, $blendedMargin * (1.0 - self::MA_INDIGESTION_PENALTY)));
        
        $acquiredOperatingIncome = $economicValue * $effectiveTargetRoic;
        $acquiredRevenue = $acquiredOperatingIncome / max(0.01, $targetMargin);
        $currentRevenue = (float) $stock->getTotalRevenue();
        $stock->setTotalRevenue((string) ($currentRevenue + $acquiredRevenue));

        // The acquired capital keeps generating the revenue booked above, so the target's revenue per dollar of
        // capital joins the acquirer's structural turnover capital-weighted (null until the engine seeds it).
        $acquirerTurnover = $stock->getAssetTurnover();
        if ($acquirerTurnover !== null) {
            $blendedTurnover = (($oldCapitalBase * (float) $acquirerTurnover) + $acquiredRevenue) / $totalNewCapital;
            $stock->setAssetTurnover((string) max(0.01, $blendedTurnover));
        }

        $newInterestExpense = 0.0;
        if ($ctx->debtIssued > 0) {
            $newInterestExpense = $ctx->debtIssued * $ctx->costOfNewDebt * (1.0 - $ctx->macroState->corporateTaxRate);
        }
        
        $trueAcquiredNetIncome = $acquiredOperatingIncome - $newInterestExpense;
        
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
     * still balances after the deal. Computing the working-capital share and then not booking it anywhere
     * shrank the asset side by that share on every cash deal with no claim moving to match.
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

    private function finalizeAcquisitionEvent(AcquisitionContext $ctx): array
    {
        $purchasePriceB = number_format($ctx->purchasePrice / 1_000_000_000, 1);
        $desc = "{$ctx->acquirer->getName()} executed a \${$purchasePriceB}B {$ctx->config['type']} of {$ctx->target['name']}.";

        if ($ctx->synergyMultiplier < 1.0) {
            $ctx->eventShock = -1.0 * (mt_rand(200, 600) / 100.0); 
        } else {
            $marketCap = $ctx->price * $ctx->shares;
            $calculatedShock = ($ctx->synergyValueCreation / max($marketCap, 1)) * 100;
            $ctx->eventShock = min(15.0, max(2.0, $calculatedShock));
        }

        $event = $this->marketEvent->publish($ctx->acquirer, $ctx->config['type'], $desc, $ctx->eventShock);

        return [
            'event' => $event,
            'shock' => $ctx->eventShock / 100.0,
            'spent' => $ctx->purchasePrice
        ];
    }

    // =========================================================================
    // DIVESTITURE PIPELINE
    // =========================================================================

    public function evaluateCorporateDivestiture(Stock $seller, MacroStateDTO $macroState, float $dt): ?array
    {
        if ($seller->isBankrupt()) {
            return null;
        }

        $ctx = new DivestitureContext($seller, $macroState, $dt);

        $this->initializeDivestitureContext($ctx);
        
        if ($this->shouldAbortDivestiture($ctx)) {
            return null;
        }

        $this->determineDivestitureStrategy($ctx);
        if (!$ctx->dealExecuted) {
            return null;
        }

        $this->executeDivestitureSale($ctx);
        $this->applyDivestitureAccounting($ctx);
        return $this->finalizeDivestitureEvent($ctx);
    }

    private function initializeDivestitureContext(DivestitureContext $ctx): void
    {
        $stock = $ctx->seller;
        
        $ctx->eps = (float) $stock->getEarningsPerShare();
        $ctx->price = (float) $stock->getPrice();
        $ctx->shares = max(1.0, (float) $stock->getSharesOutstanding());
        $ctx->netIncome = (float) $stock->getTotalNetIncome();

        $ctx->health = $this->debtEngine->analyzeDebtHealth($stock, $ctx->macroState);
        $ctx->wacc = $ctx->health->wacc ?? 0.08;

        $ctx->industry = $stock->getIndustry() ?: 'General';
        $ctx->businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$ctx->industry]['business_model'] ?? 'none';
        $ctx->strategy = \App\Data\Sectors::getBusinessModelStrategy($ctx->businessModel);
        
        $ctx->currentReturn = $ctx->strategy->getTrueReturn($stock);
        if ($ctx->currentReturn === 0.0) {
            $ctx->currentReturn = $ctx->strategy->calculateEconomicReturn($stock, $ctx->netIncome, $stock->getInvestedCapital());
        }
        $ctx->hurdleRate = $ctx->strategy->getHurdleRate($ctx->health);

        $ctx->evaSpread = $ctx->currentReturn - $ctx->hurdleRate;

        $ctx->isDistressed = $ctx->evaSpread < self::DIV_DISTRESS_EVA_THRESHOLD || $ctx->currentReturn < self::DIV_DISTRESS_RETURN_FLOOR;
        // Life-cycle pressure (Dickinson 2011): shake-out and decline stage firms shed assets to fund operations.
        if ($stock->getLifecycleStage()?->isShedding() === true) {
            $ctx->isDistressed = true;
        }
        $ctx->isDying = $ctx->currentReturn < self::DIV_DYING_RETURN_THRESHOLD || $ctx->evaSpread < self::DIV_DYING_EVA_THRESHOLD;

        $ctx->treasury = (float) $stock->getCorporateTreasury();
        $ctx->operatingBase = $this->corporateMetrics->calculateOperatingBase((float) $stock->getTotalRevenue(), (float) $stock->getTotalEquity());
        $ctx->hasCashBuffer = $ctx->treasury > ($ctx->operatingBase * self::DIV_CASH_FORTRESS_RATIO);

        $ctx->currentEquity = (float) $stock->getTotalEquity();
        $ctx->investedCapital = $stock->getInvestedCapital();
        $ctx->evaluationCapital = $ctx->strategy->getEvaluationCapital($ctx->currentEquity, $ctx->investedCapital);
        
        $ctx->structuralNetIncome = $ctx->evaluationCapital * $ctx->currentReturn;
        $ctx->normalizedNetIncome = ($ctx->netIncome * 0.50) + ($ctx->structuralNetIncome * 0.50);
        
        $ctx->normalizedEps = $ctx->normalizedNetIncome / $ctx->shares;
        $ctx->currentPE = $ctx->normalizedEps > 0 ? $ctx->price / $ctx->normalizedEps : 0.0;
        
        $ctx->targetCash = $stock->getManagementProfile()->appliedTargetCash($ctx->strategy->calculateTargetOperatingCash($ctx->operatingBase, (float) $stock->getCustomerDeposits(), (float) $stock->getWholesaleDebt()));
        $ctx->hoardStatus = $ctx->strategy->evaluateHoardingStatus($ctx->treasury, $ctx->targetCash, $stock->getManagementProfile()->appliedHoardingBase($ctx->operatingBase), (float) $stock->getTotalDebt());
        
        $ctx->nominalGdpIndex = $ctx->macroState->nominalGdpIndex;
        $ctx->samRatio = (float) $stock->getSamRatio();
        $ctx->scaleRatio = $this->corporateMetrics->calculateScaleRatio($ctx->evaluationCapital, $ctx->nominalGdpIndex, $ctx->samRatio);
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
            $ctx->saleMultiple = $this->mathUtility->generateUniformBetween(self::DIV_DYING_MULTIPLE_MIN, self::DIV_DYING_MULTIPLE_MAX);
            $ctx->annualProbability = self::DIV_DYING_ANNUAL_PROB;
        } elseif ($ctx->isDistressed) {
            $ctx->divestedFraction = $this->mathUtility->generateUniformBetween(self::DIV_DISTRESSED_FRACTION_MIN, self::DIV_DISTRESSED_FRACTION_MAX);
            $ctx->saleMultiple = $this->mathUtility->generateUniformBetween(self::DIV_DISTRESSED_MULTIPLE_MIN, self::DIV_DISTRESSED_MULTIPLE_MAX);
            $ctx->annualProbability = self::DIV_DISTRESSED_ANNUAL_PROB;
        } else {
            $ctx->divestedFraction = $this->mathUtility->generateUniformBetween(self::DIV_PREMIUM_FRACTION_MIN, self::DIV_PREMIUM_FRACTION_MAX);
            $ctx->sectorPE = \App\Data\Sectors::MACRO_SECTORS[$ctx->seller->getSector()] ?? 20.0;
            $blendedMultiple = ($ctx->currentPE + $ctx->sectorPE) / 2.0;
            $ctx->saleMultiple = min(self::DIV_PREMIUM_MAX_MULTIPLE, max(self::DIV_PREMIUM_MIN_MULTIPLE, $blendedMultiple));
            $ctx->annualProbability = self::DIV_PREMIUM_ANNUAL_PROB;
        }

        if (!$this->mathUtility->checkProbability($ctx->annualProbability * $ctx->dt)) {
            $ctx->dealExecuted = false;
            return;
        }
        
        $ctx->dealExecuted = true;
    }

    private function executeDivestitureSale(DivestitureContext $ctx): void
    {
        $ctx->lostNetIncome = $ctx->normalizedNetIncome * $ctx->divestedFraction;
        $ctx->currentDebt = (float) $ctx->seller->getWholesaleDebt();
        $ctx->lostDebt = $ctx->currentDebt * $ctx->divestedFraction;
        
        // The book value leaving with the division is what its ledgers actually carry, less the debt the buyer
        // assumes. Only a balance sheet with no modelled asset side (a bank's loan book) falls back to the
        // strategy's capital-proxy estimate; anything else would state a gain against assets never retired.
        $ctx->lostEquity = $ctx->seller->hasBalanceSheetLedger()
            ? $this->calculateBookValueDisposed($ctx->seller, $ctx->divestedFraction) - $ctx->lostDebt
            : $ctx->strategy->calculateDivestedEquity($ctx->seller, $ctx->divestedFraction, $ctx->currentEquity, $ctx->currentDebt, $ctx->treasury, $ctx->investedCapital, $ctx->lostDebt);

        // What the assets fetch on their own. A buyer of a plant pays at least its distressed liquidation
        // value whatever the seller's trailing earnings say, so the earnings-based price is floored at the
        // fire-sale value of the book that leaves. Without the floor a firm whose net income had collapsed
        // sold a division at 3-5x that collapsed figure against a book carried at cost, booked the gap as a
        // loss on sale, and destroyed more equity in one disposal than the downturn that forced it.
        $ctx->baseDistressValue = max($ctx->currentEquity, $ctx->investedCapital * 0.25);
        $bookDisposed = $ctx->lostEquity > 0.0 ? $ctx->lostEquity : $ctx->baseDistressValue * $ctx->divestedFraction;
        $fireSaleValue = $bookDisposed * $this->mathUtility->generateUniformBetween(self::DIV_FIRE_SALE_MIN_CENTS, self::DIV_FIRE_SALE_MAX_CENTS);

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
        $stock->setEarningsPerShare((string) ($currentEps * (1.0 - $ctx->divestedFraction)));

        $stock->setWholesaleDebt((string) max(0.0, $ctx->currentDebt - $ctx->lostDebt));

        $ctx->strategy->shedDivestedLiabilities($stock, $ctx->divestedFraction, $ctx->treasury);
        
        $currentRevenue = (float) $stock->getTotalRevenue();
        $stock->setTotalRevenue((string) max(1.0, $currentRevenue * (1.0 - $ctx->divestedFraction)));

        if ($stock->hasBalanceSheetLedger()) {
            $this->retireDivestedLedgers($stock, $ctx->divestedFraction);
        }

        // Equity moves by the gain or loss on sale and nothing else: proceeds in, book value out. No floor,
        // because a floor would invent equity with no asset behind it and unbalance the sheet.
        $newEquity = $ctx->currentEquity - $ctx->lostEquity + $ctx->salePrice;
        $stock->setTotalEquity((string) $newEquity);
        
        $ctx->gainOnSale = $ctx->salePrice - $ctx->lostEquity;
        $currentRetained = (float) $stock->getRetainedEarnings();
        $stock->setRetainedEarnings((string) ($currentRetained + $ctx->gainOnSale));

        if ($ctx->isDistressed || $ctx->isDying) {
            $ctx->strategy->boostStructuralEfficiency($stock, $ctx->divestedFraction, self::DIV_DISTRESS_ROIC_BUMP);
            $operatingMargin = (float) $stock->getOperatingMargin();
            $marginBump = $operatingMargin * ($ctx->divestedFraction * self::DIV_DISTRESS_MARGIN_BUMP); 
            $stock->setOperatingMargin((string) ($operatingMargin + $marginBump));
        }
    }

    /**
     * Book value of the assets a divested division takes with it, before the debt the buyer assumes: a pro
     * rata slice of every operating ledger. Goodwill follows the business it was paid for (ASC 350-20-40-1
     * allocates it on relative value, which a fraction of the whole approximates), the trade cycle goes net of
     * its credit-loss allowance and of the payables suppliers are owed, and the deferred tax on the plant's
     * timing difference is a liability the buyer inherits with the plant.
     */
    private function calculateBookValueDisposed(Stock $stock, float $fraction): float
    {
        $assets = $stock->getNetPpe()
            + (float) $stock->getCipBalance()
            + (float) $stock->getGoodwill()
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

        return ($assets - $liabilities) * $fraction;
    }

    /**
     * Retires the divested fraction of every operating ledger, the same slice calculateBookValueDisposed()
     * valued. Gross cost and accumulated depreciation leave together, which keeps the plant that stays at the
     * same average age: selling a division tells you nothing about how worn the plant you kept is. The tax
     * basis and deferred tax go with the plant, goodwill with the business, and the trade balances with the
     * customers and suppliers who move to the buyer. Cash stays: the proceeds are already in treasury.
     */
    private function retireDivestedLedgers(Stock $stock, float $fraction): void
    {
        $retained = max(0.0, 1.0 - $fraction);

        if ($stock->hasEarningAssetLedger()) {
            $stock->setEarningAssets((string) ((float) $stock->getEarningAssets() * $retained));
            $stock->setCreditLossAllowance((string) ((float) $stock->getCreditLossAllowance() * $retained));
        }
        if ($stock->getGrossPpe() !== null) {
            $stock->setGrossPpe((string) ((float) $stock->getGrossPpe() * $retained));
            $stock->setAccumulatedDepreciation((string) ((float) $stock->getAccumulatedDepreciation() * $retained));
        }
        if ($stock->getPpeTaxBasis() !== null) {
            $stock->setPpeTaxBasis((string) ((float) $stock->getPpeTaxBasis() * $retained));
        }
        $stock->setCipBalance((string) ((float) $stock->getCipBalance() * $retained));
        $stock->setGoodwill((string) ((float) $stock->getGoodwill() * $retained));
        $stock->setDeferredTaxLiability((string) ((float) $stock->getDeferredTaxLiability() * $retained));

        if ($stock->hasWorkingCapitalLedger()) {
            $stock->setReceivables((string) ((float) $stock->getReceivables() * $retained));
            $stock->setReceivablesAllowance((string) ((float) $stock->getReceivablesAllowance() * $retained));
            $stock->setInventory((string) ((float) $stock->getInventory() * $retained));
            $stock->setInventoryAllowance((string) ((float) $stock->getInventoryAllowance() * $retained));
            $stock->setPayables((string) ((float) $stock->getPayables() * $retained));
        }
    }

    private function finalizeDivestitureEvent(DivestitureContext $ctx): array
    {
        $salePriceB = number_format($ctx->salePrice / 1_000_000_000, 1);
        $gainOnSaleB = number_format($ctx->gainOnSale / 1_000_000_000, 1);
        $target = $this->generateProceduralTarget();
        $desc = "{$ctx->seller->getName()} sold its {$target['name']} division for \${$salePriceB}B in cash, generating a \${$gainOnSaleB}B gain on sale.";

        if ($ctx->isDistressed) {
            $ctx->eventShock = mt_rand(300, 600) / 100.0; 
        } else {
            $ctx->eventShock = mt_rand(100, 300) / 100.0; 
        }

        $event = $this->marketEvent->publish($ctx->seller, 'DIVESTITURE', $desc, $ctx->eventShock);

        return ['event' => $event, 'shock' => $ctx->eventShock / 100.0];
    }

    /**
     * Procedurally generates a realistic sounding private company.
     *
     * @return array{name: string} An array containing the generated company name.
     */
    private function generateProceduralTarget(): array
    {
        $prefixes = ['Apex', 'Horizon', 'Vertex', 'Quantum', 'Cipher', 'Aegis', 'Omni', 'Vanguard', 'Pinnacle', 'Meridian'];
        $suffixes = ['Dynamics', 'Holdings', 'Labs', 'Logistics', 'Networks', 'Heavy Industries', 'Capital', 'Technologies', 'Resources', 'Ventures'];

        $name = $prefixes[array_rand($prefixes)] . ' ' . $suffixes[array_rand($suffixes)];

        return ['name' => $name];
    }
}
