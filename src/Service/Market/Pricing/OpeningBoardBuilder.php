<?php

declare(strict_types=1);

namespace App\Service\Market\Pricing;

use App\Data\Company\AnchorHoldings;
use App\Data\Company\BusinessRisk;
use App\Data\Company\DividendTier;
use App\Data\Company\EventRisk;
use App\Data\Company\IndustryBetas;
use App\Data\Company\IndustryPayoutPolicies;
use App\Data\Company\IndustryRisks;
use App\Data\Company\ManagementProfile;
use App\Data\Company\ManagementStyle;
use App\Data\Company\Sectors;
use App\Data\Company\StockInfo;
use App\DTO\DebtHealthDTO;
use App\DTO\MacroStateDTO;
use App\DTO\MarketPricingContext;
use App\Entity\Stock;
use App\Service\Corporate\CapitalAllocationEngine;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\Earnings\FixedAssetLedger;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Corporate\ManagementSuccessionEngine;
use App\Service\Market\Bond\CreditRatingAgency;
use App\Service\Math\Decimal;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\Valuation;
use App\Service\Model\BusinessModelInterface;

/**
 * Opens listed companies on their seed rows (App\Data\Company\InitialMarket): the balance sheet, the ledgers the first
 * report rolls forward, and the price the market opens each one at. The seed and the reset both open the board
 * here, so a reset market is the market a fresh seed would have opened.
 */
class OpeningBoardBuilder
{
    // --- Opening Price ---
    /** Relative move between passes below which the opening price and the asset volatility calibrated on it agree. */
    public const OPENING_PRICE_TOLERANCE = 0.001;
    /** Most passes of the joint price and asset-volatility solve before the opening price is taken as it stands. */
    public const MAX_OPENING_PRICE_PASSES = 6;

    // --- Investment Securities & AOCI (ASC 320 / Basel III) ---
    /**
     * Balance-sheet size above which an institution loses the AOCI filter and marks its capital to the curve. The
     * advanced-approaches rule in form, set to this district's scale rather than a US figure: it takes the systemically
     * important lenders and leaves the mid-tier the election.
     */
    public const AOCI_FILTER_SIZE_THRESHOLD = 750_000_000_000.0;

    /** @var array<string, float>|null Each seed payer's quantile in the board's distribution of opening records. */
    private static ?array $recordQuantiles = null;

    private int $lastOpeningPricePasses = 0;

    public function __construct(
        private readonly MathUtility $mathUtility,
        private readonly DebtEngine $debtEngine,
        private readonly MarketEngine $marketEngine,
        private readonly AnchorStakeLedger $anchorStakes,
    ) {}

    public function getLastOpeningPricePasses(): int
    {
        return $this->lastOpeningPricePasses;
    }

    /**
     * The listing itself, which a reseed refreshes even on a firm it does not reopen.
     *
     * @param array<string, mixed> $stockData The firm's seed row.
     */
    public function applyListing(Stock $stock, array $stockData): void
    {
        $stock->setName($stockData['name']);
        $stock->setSector($stockData['sector']);
        $stock->setIndustry($stockData['industry'] ?? null);
        $stock->setDescription(StockInfo::DESCRIPTIONS[$stockData['ticker']] ?? null);
    }

    /**
     * Opens a firm on its seed row: balance sheet, ledgers, and the fair value the market opens it at.
     *
     * A sphere's plant is what its portfolio leaves, and the portfolio cannot be valued until the board it holds
     * has prices, so a sphere is handed back to be finished by openSpheres() once the whole board is open.
     *
     * @param array<string, mixed> $stockData The firm's seed row.
     * @return array{stock: Stock, invested: float, assetAgeRatio: float, businessRisk: BusinessRisk, eventRisk: EventRisk, dividendStreak: int|null}|null The sphere left to finish, or null.
     */
    public function open(Stock $stock, array $stockData, MacroStateDTO $openingMacro): ?array
    {
        // A row that states the firm's character has its books built by its model first (SeedRows).
        $stockData = SeedRows::resolve($stockData, $openingMacro);
        ['business' => $businessRisk, 'events' => $eventRisk] = IndustryRisks::forRow($stockData);

        // The whole seed row goes on before any engine reads the firm: its industry decides which model's physics
        // price it, and its rating sets its derived coupon and floating mix inside strikeOpeningPrice(). Risk starts at
        // the unlevered business step until strikeOpeningPrice() derives the levered equity vol and budgeted jump
        // scale from DebtEngine's opening beta and leverage.
        $this->applyListing($stock, $stockData);
        $stock->setSharesOutstanding((string) $stockData['shares_outstanding']);
        $provisionalVol = Decimal::format($businessRisk->vol(), 4);
        $stock->setVolatility($provisionalVol);
        $stock->setCurrentVolatility($provisionalVol);
        // The industry's beta, Hamada-levered by DebtEngine at the firm's own net debt, plus the lore's position in its
        // band.
        $stock->setBeta((string) IndustryBetas::seedBetaFor($stockData));
        $stock->setJumpIntensity(Decimal::format($eventRisk->intensity(), 2));
        $stock->setJumpVol(Decimal::format(VarianceBudget::jumpScaleForVarianceShare($businessRisk->vol(), $eventRisk->intensity()), 4));
        $stock->setSystemicImportance($stockData['systemic_importance'] ?? 'none');

        $stock->setImpactVarianceEma(0.0);
        $stock->setAnnouncementVarianceEma(0.0);
        $stock->setCorporateFlowBacklog(0.0);

        // Not the whole float: most holders do not lend, which is what makes a name hard to borrow
        // long before anything like all of it has been shorted.
        $stock->setLendableSupplyRatio(FinancialConstants::DEFAULT_LENDABLE_SUPPLY_RATIO);
        $stock->setShortInterestShares('0.00');

        $businessModel = Sectors::businessModelFor($stockData['industry'] ?? null);
        $isFinancial = Sectors::isFinancial($businessModel);
        $strategy = Sectors::getBusinessModelStrategy($businessModel);

        if ($isFinancial) {
            $roe = $stockData['baseline_roe'] ?? $stockData['baseline_roic'] ?? 0.10;
            $stock->setBaselineRoe((string) $roe);
            $stock->setCurrentRoe((string) $roe);
            $stock->setRoeTtm((string) $roe);
            $stock->setLongRunReturn((string) $roe);
        } else {
            $roic = $stockData['baseline_roic'] ?? 0.10;
            $stock->setBaselineRoic((string) $roic);
            $stock->setCurrentRoic((string) $roic);
            $stock->setRoicTtm((string) $roic);
            $stock->setLongRunReturn((string) $roic);
        }

        if (isset($stockData['capex_ratio'])) {
            $stock->setCapexRatio((string) $stockData['capex_ratio']);
        }
        // The board opens on its policy and adjusts its dividend at the speed its business model sets.
        $payoutPolicy = IndustryPayoutPolicies::forRow($stockData);
        $stock->setBasePayoutPolicy($payoutPolicy);
        $stock->setPayoutPolicy($payoutPolicy);
        $stock->setDividendSpeed((string) $strategy->getDividendAdjustmentSpeed());
        $stock->setFixedCostRatio((float) $stockData['fixed_cost_ratio']);
        $stock->setDepreciationRate((string) $stockData['depreciation_rate']);

        $stock->setCorporateTreasury((string) $stockData['corporate_treasury']);
        if (!$strategy->derivesOpeningFloatingShare()) {
            $stock->setFloatingDebtRatio((string) $stockData['floating_debt_ratio']);
        }
        $stock->setOperatingMargin((string) $stockData['operating_margin']);
        // Shares an anchor sphere holds are not tradable, so the float is the declared one less
        // whatever AnchorHoldings locks away. Derived so a stake and a float cannot drift apart.
        $stock->setPublicFloatPercentage((string) AnchorHoldings::tradableFloat(
            $stockData['ticker'],
            (float) $stockData['public_float'],
        ));
        $stock->setTotalEquity(Decimal::of($stockData['total_equity'] ?? 0.0));
        $stock->setWholesaleDebt((string) ($stockData['wholesale_debt'] ?? 0.00));
        $stock->setCustomerDeposits((string) ($stockData['customer_deposits'] ?? 0.00));
        $stock->setRetainedEarnings(Decimal::of($stockData['retained_earnings'] ?? 0.0));
        if (!$strategy->derivesOpeningCoupon()) {
            $stock->setHistoricalFixedRate((string) $stockData['historical_fixed_rate']);
        }
        // Provisional, at book, until the market strikes the opening price below: the debt analysis sizes the
        // equity it weighs against the firm's own book rather than the entity's placeholder.
        $stock->setPrice($stock->getBookValuePerShare());
        $stock->setSamRatio((string) $stockData['sam_ratio']);
        $stock->setManagementStyle(ManagementStyle::tryFromNullable($stockData['management_style'] ?? null));
        $stock->setCeoTenureYears(ManagementSuccessionEngine::drawSeedTenure($this->mathUtility));
        $stock->setManagementIntensity(ManagementProfile::drawIntensity($this->mathUtility));

        $margin = $stockData['operating_margin'];

        // Query the exact structural metrics the engine uses to prevent massive gravity explosions on tick 1
        $targetMetrics = $strategy->getTargetMetrics($stock, $openingMacro, $this->mathUtility);
        $investedCapital = $targetMetrics['invested_capital'];
        $effectiveTaxRate = $strategy->getEffectiveTaxRate($openingMacro->corporateTaxRate);
        $bookDepRate = FixedAssetLedger::depreciationRateFor($stock);
        $revenue = $margin > 0
            ? $investedCapital * $strategy->resolveStructuralAssetTurnover((float) $targetMetrics['baseline_roic'], $margin, $effectiveTaxRate, $bookDepRate)
            : 0.0;

        $stock->setTotalRevenue((string) $revenue);

        // Rate risk needs a cross-section or every lender lives and dies together. The seed names a
        // duration where a firm's book is distinctive; the rest take their model's. The AOCI
        // election is likewise per firm: it decides whether a capital ratio reacts to the curve at
        // all, and a board where everyone elected the same way has no dispersion in who survives.
        $stock->setSecuritiesDuration((float) ($stockData['securities_duration'] ?? $strategy->getDefaultSecuritiesDuration()));
        $stock->setSecuritiesHtmShare((float) ($stockData['securities_htm_share'] ?? $strategy->getDefaultSecuritiesHtmShare($stock)));
        $stock->setAociFiltered((bool) ($stockData['aoci_filtered'] ?? (
            // The filter is an election the largest institutions do not get. Sized rather than
            // listed per firm, so the dispersion maintains itself as the seed changes: a bank big
            // enough to be systemically important marks its capital to the curve, and a small one
            // does not. Without the split every lender would react to rates identically.
            ((float) ($stockData['total_equity'] ?? 0.0)
                + (float) ($stockData['wholesale_debt'] ?? 0.0)
                + (float) ($stockData['customer_deposits'] ?? 0.0))
            < self::AOCI_FILTER_SIZE_THRESHOLD
        )));

        $seededDividendStreak = isset($stockData['dividend_streak']) ? (int) $stockData['dividend_streak'] : null;
        $sphere = null;

        // Open the fixed-asset ledger so the very first earnings report depreciates a real plant
        // rather than falling back to the capital proxy. Financial balance sheets keep no plant.
        if (!$isFinancial) {
            $metrics = CorporateMetrics::getInstance();
            $metrics->buildWorkingCapitalBalances(
                $stock,
                $strategy->getWorkingCapitalDays($stock),
                $revenue,
                $revenue * (1.0 - $margin),
            );
            $metrics->seedReceivablesAllowance($stock, $openingMacro->corporateDefaultRateEma);

            $assetAgeRatio = (float) ($stockData['asset_age_ratio'] ?? FinancialConstants::SEED_ASSET_AGE_RATIO);

            if (AnchorHoldings::forHolder($stockData['ticker']) === []) {
                $metrics->seedFixedAssetLedger(
                    $stock,
                    $strategy->getPlantCapital($stock, $investedCapital),
                    (float) $stock->getNetWorkingCapital(),
                    (float) $stock->getGoodwill(),
                    $stock->getTotalCipAmount(),
                    $assetAgeRatio,
                );
            } else {
                $sphere = [
                    'stock' => $stock,
                    'invested' => $investedCapital,
                    'assetAgeRatio' => $assetAgeRatio,
                    'businessRisk' => $businessRisk,
                    'eventRisk' => $eventRisk,
                    'dividendStreak' => $seededDividendStreak,
                ];
            }
        } else {
            // A balance-sheet business opens its loan book instead, with the allowance already at
            // the lifetime loss it expects so the first report books no phantom provision.
            CorporateMetrics::getInstance()->seedEarningAssetLedger(
                $stock,
                $strategy->getThroughTheCycleCreditLossRate($stock)
                    * $strategy->getCreditLossHorizonYears()
                    * $strategy->getForwardCreditLossMultiplier($stock, $openingMacro),
            );
        }

        $this->strikeOpeningPrice($stock, $openingMacro, $businessRisk, $eventRisk, $seededDividendStreak);

        return $sphere;
    }

    /**
     * Strikes the price the firm opens at, on the inputs StockTracker prices it on at tick 1 — the same mapping and a
     * debt analysis of the finished balance sheet — or the market re-rates the instant it starts.
     *
     * Debt terms, earnings, price, equity volatility and asset volatility are solved together: each pass sets any
     * derived fixed coupon and floating share from the firm's current rating, runs DebtEngine on those terms, strikes
     * opening net income, EPS and the Lintner opening dividend, derives equity volatility and jump scale, calibrates
     * Merton asset volatility on the price just struck, and rates the firm until price, volatility and rating agree.
     * Turnover ratio, realizedVarianceEma and creditSpread are set once the loop has converged.
     */
    private function strikeOpeningPrice(Stock $stock, MacroStateDTO $openingMacro, BusinessRisk $businessRisk, EventRisk $eventRisk, ?int $seededDividendStreak = null): void
    {
        $strategy = Sectors::strategyFor($stock->getIndustry());
        $lossGivenDefault = $strategy->getLossGivenDefault();
        $previousPrice = (float) $stock->getPrice();
        $previousVol = (float) $stock->getVolatility();
        $lambda = $eventRisk->intensity();
        $stock->setBusinessVolatility($businessRisk->vol());
        $stock->setJumpIntensity(Decimal::format($lambda, 2));

        for ($pass = 0; $pass < self::MAX_OPENING_PRICE_PASSES; $pass++) {
            $this->lastOpeningPricePasses = $pass + 1;
            if ($strategy->derivesOpeningFloatingShare()) {
                $stock->setFloatingDebtRatio((string) CreditRatingAgency::openingFloatingDebtRatio($stock->getCreditRating()));
            }
            if ($strategy->derivesOpeningCoupon()) {
                $stock->setHistoricalFixedRate((string) DebtEngine::openingFixedCoupon($stock, $openingMacro, $lossGivenDefault));
            }
            $health = $this->debtEngine->analyzeDebtHealth($stock, $openingMacro);
            $this->strikeOpeningEarnings($stock, $openingMacro, $strategy, $health, $seededDividendStreak);

            $equityVol = VarianceBudget::equityVolatilityFromBusinessRisk(
                $health->leveredBeta,
                $health->equityLeverageMultiplier,
                $businessRisk->vol(),
            );
            $storedVol = Decimal::format($equityVol, 4);
            $stock->setVolatility($storedVol);
            $stock->setCurrentVolatility($storedVol);
            $stock->setLeveredVolatility((float) $storedVol);

            // Derive the jump scale on the stored volatility's idiosyncratic variance and floor to the 4-decimal
            // column scale so calibratedJumpParameters() never rescales an opening row on rounding noise.
            $idiosyncraticEquityVol = sqrt(VarianceBudget::longTermIdiosyncraticVariance((float) $storedVol, $health->leveredBeta));
            $jumpScale = VarianceBudget::jumpScaleForVarianceShare($idiosyncraticEquityVol, $lambda);
            $flooredScale = floor($jumpScale * 10000.0) / 10000.0;
            $stock->setJumpVol(Decimal::format($flooredScale, 4));

            $pricingCtx = MarketPricingContext::forStock($stock, $openingMacro, $health, $this->anchorStakes);
            $price = $this->marketEngine->strikeFairValue($pricingCtx)['perceived_fair_value'];
            $stock->setPrice((string) $price);
            $this->debtEngine->calibrateAssetVolatility($stock, $openingMacro->policyRateEma);
            $rerated = $this->debtEngine->seedCreditRating($stock, $openingMacro);

            $volConverged = $previousVol > 0.0 && abs((float) $storedVol / $previousVol - 1.0) < self::OPENING_PRICE_TOLERANCE;
            $priceConverged = $previousPrice > 0.0 && abs($price / $previousPrice - 1.0) < self::OPENING_PRICE_TOLERANCE;
            if (!$rerated && $priceConverged && $volConverged) {
                break;
            }
            $previousPrice = $price;
            $previousVol = (float) $storedVol;
        }

        // How much of the float changes hands in a year and the opening variance EMA: read off the converged equity
        // volatility so turnover and low-volatility index screens start on the risk the firm actually carries.
        $convergedVol = (float) $stock->getVolatility();
        $stock->setTurnoverRatio(LiquidityEngine::structuralTurnoverRatio($convergedVol));
        $stock->setRealizedVarianceEma($convergedVol ** 2);

        // Record the opening through-the-cycle spread at the converged rating (for pages and admin, not an engine
        // input).
        $stock->setCreditSpread(Decimal::format(
            DebtEngine::throughTheCycleCreditSpread($stock, $openingMacro, $lossGivenDefault),
            4,
        ));
    }

    /**
     * Opening net income, EPS, dividend record and Lintner starting dividend on the pass's converged debt charges.
     */
    private function strikeOpeningEarnings(Stock $stock, MacroStateDTO $openingMacro, BusinessModelInterface $strategy, DebtHealthDTO $health, ?int $seededDividendStreak): void
    {
        if ($strategy->isFinancial()) {
            $netIncome = (float) $stock->getTotalEquity() * (float) $stock->getBaselineRoe();
        } else {
            $revenue = (float) $stock->getTotalRevenue();
            $margin = (float) $stock->getOperatingMargin();
            $ebit = $revenue * $margin;
            $interestExpense = $health->rawMetrics->interestExpense;
            $interestIncome = $strategy->calculateInterestIncome($stock, $openingMacro);
            $effectiveTaxRate = $strategy->getEffectiveTaxRate($openingMacro->corporateTaxRate);
            $netIncome = max(0.0, ($ebit - $interestExpense + $interestIncome) * (1.0 - $effectiveTaxRate));
            // A trust reports funds from operations (net income with book depreciation added back), so it opens on
            // the same figure its first report files and its dividend policy pays from.
            if ($strategy->reportsFundsFromOperations()) {
                $netIncome += $strategy->getPlantCapital($stock, $stock->getInvestedCapital()) * FixedAssetLedger::depreciationRateFor($stock);
            }
        }

        $shares = (float) $stock->getSharesOutstanding();
        $annualEps = $shares > 0.0 ? ($netIncome / $shares) : 0.0;
        $stock->setTotalNetIncome((string) $netIncome);
        $stock->setEarningsPerShare((string) round($annualEps, 2));

        // A payer opens on a record of increases: the one its row names, or one from the board's long-run distribution
        // at its rank by payout, so the roll opens at the size it keeps rather than filling from empty.
        $isPayer = $annualEps > 0.0 && $stock->getPolicyPayoutRatio() > 0.0;
        $openingStreak = $isPayer ? DividendTier::openingStreak(self::openingRecordQuantile($stock->getTicker())) : 0;
        $stock->setDividendStreakYears($seededDividendStreak ?? $openingStreak);

        // A board that has been paying opens where its policy has already brought it (Lintner 1956): closing part of
        // the gap each year while earnings grow with the economy, it runs a steady step below its target payout, so its
        // first annual review has a raise to make.
        $annualSpeed = Valuation::lintnerAnnualSpeed((float) $stock->getDividendSpeed() * $stock->getDividendTier()->smoothing());
        $trendGrowth = MacroConstants::TREND_REAL_GROWTH + max(0.0, $openingMacro->inflationEma);
        $startingDividend = max(0.0, $annualEps / 4.0) * $stock->getPolicyPayoutRatio() * Valuation::lintnerSteadyDividendShare($annualSpeed, $trendGrowth);
        $stock->setLastDividend((string) $startingDividend);
        // The opening dividend is the bar the first year's must clear to extend the firm's record.
        $stock->setEarningsMomentumZ([CapitalAllocationEngine::STATE_YEAR_END_DIVIDEND => round($startingDividend, 4)] + ($stock->getEarningsMomentumZ() ?? []));
    }

    /**
     * Finishes the spheres open() handed back. Their holdings are marked against the priced board, which is the
     * first moment the stakes can be valued at all, so a trust opens carrying what its holdings are worth and its
     * plant is carved out of a real portfolio instead of a guess at one.
     *
     * @param list<array{stock: Stock, invested: float, assetAgeRatio: float, businessRisk: BusinessRisk, eventRisk: EventRisk, dividendStreak: int|null}> $spheres
     * @param iterable<Stock> $board Every listed firm, the holdings among them.
     * @throws \DomainException When a sphere's portfolio leaves its subsidiaries too little of its capital.
     */
    public function openSpheres(array $spheres, iterable $board, MacroStateDTO $openingMacro): void
    {
        $this->anchorStakes->beginTick($board);

        foreach ($spheres as $sphere) {
            $stock = $sphere['stock'];
            $strategy = Sectors::strategyFor($stock->getIndustry());
            $this->anchorStakes->markToMarket($stock, $strategy->getEffectiveTaxRate($openingMacro->corporateTaxRate));
            $this->anchorStakes->openDividendRate($stock);

            // The stake list is edited by hand in a file that knows nothing about the balance sheet it has
            // to fit inside. Refused rather than logged: a portfolio that swallows the book leaves the
            // subsidiaries no residual, and their stream stops being drawn at all.
            $fit = AnchorHoldings::consolidatedShare((float) ($stock->getListedStakesCarrying() ?? 0.0), $sphere['invested']);

            if ($fit < AnchorHoldings::MIN_CONSOLIDATED_SHARE) {
                throw new \DomainException(sprintf(
                    '%s holds a portfolio worth %.1f%% of its capital employed, leaving %.1f%% for its subsidiaries (minimum %.0f%%). Trim its stakes in AnchorHoldings or raise its balance sheet in InitialMarket.',
                    $stock->getTicker(),
                    100.0 * (1.0 - $fit),
                    100.0 * $fit,
                    100.0 * AnchorHoldings::MIN_CONSOLIDATED_SHARE,
                ));
            }

            CorporateMetrics::getInstance()->seedFixedAssetLedger(
                $stock,
                $strategy->getPlantCapital($stock, $sphere['invested']),
                (float) $stock->getNetWorkingCapital(),
                (float) $stock->getGoodwill(),
                $stock->getTotalCipAmount(),
                $sphere['assetAgeRatio'],
            );

            // Struck again now its net asset value exists: the stakes marked, the subsidiaries carved out of them.
            $this->strikeOpeningPrice($stock, $openingMacro, $sphere['businessRisk'], $sphere['eventRisk'], $sphere['dividendStreak']);
        }
    }

    /**
     * Where a payer stands in the board's distribution of records. The seed's payers are ranked by the share of
     * earnings they open paying, highest first, since the mature firm that pays most has been paying longest (Fama &
     * French 2001), with ties ordered by a hash of the ticker; a firm outside the seed takes its quantile from the hash
     * alone.
     */
    private static function openingRecordQuantile(string $ticker): float
    {
        $hash = static fn(string $symbol): float => crc32('dividend-record:' . $symbol) / 0x100000000;
        if (self::$recordQuantiles === null) {
            $payers = [];
            foreach (SeedRows::all() as $row) {
                $payout = IndustryPayoutPolicies::forRow($row)->ratio();
                if ($payout > 0.0) {
                    $payers[(string) $row['ticker']] = $payout;
                }
            }
            uksort($payers, static fn(string $a, string $b): int => [$payers[$b], $hash($a)] <=> [$payers[$a], $hash($b)]);
            self::$recordQuantiles = [];
            foreach (array_keys($payers) as $rank => $symbol) {
                self::$recordQuantiles[$symbol] = ($rank + 0.5) / count($payers);
            }
        }

        return self::$recordQuantiles[$ticker] ?? $hash($ticker);
    }
}
