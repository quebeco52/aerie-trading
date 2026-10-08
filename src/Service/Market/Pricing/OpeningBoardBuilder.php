<?php

declare(strict_types=1);

namespace App\Service\Market\Pricing;

use App\Data\AnchorHoldings;
use App\Data\ManagementProfile;
use App\Data\ManagementStyle;
use App\Data\Sectors;
use App\Data\StockInfo;
use App\DTO\MacroStateDTO;
use App\DTO\MarketPricingContext;
use App\Entity\Stock;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Corporate\ManagementSuccessionEngine;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;

/**
 * Opens listed companies on their seed rows (App\Data\InitialMarket): the balance sheet, the ledgers the first
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

    public function __construct(
        private readonly MathUtility $mathUtility,
        private readonly DebtEngine $debtEngine,
        private readonly MarketEngine $marketEngine,
        private readonly AnchorStakeLedger $anchorStakes,
    ) {}

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
     * @return array{stock: Stock, invested: float, assetAgeRatio: float}|null The sphere left to finish, or null.
     */
    public function open(Stock $stock, array $stockData, MacroStateDTO $openingMacro): ?array
    {
        // The whole seed row goes on before any engine reads the firm: its industry decides which model's physics
        // price it, and its spread and legacy coupon decide what its debt costs.
        $this->applyListing($stock, $stockData);
        $stock->setSharesOutstanding((string) $stockData['shares_outstanding']);
        $stock->setVolatility((string) $stockData['volatility']);
        $stock->setCurrentVolatility((string) $stockData['volatility']);
        $stock->setBeta((string) $stockData['beta']);
        $stock->setJumpIntensity((string) $stockData['jump_intensity']);
        $stock->setJumpVol((string) $stockData['jump_vol']);
        $stock->setSystemicImportance($stockData['systemic_importance'] ?? 'none');

        // How much of the float changes hands in a year: the structural input behind this name's
        // depth, its spread, and how far a given order size moves it.
        $stock->setTurnoverRatio(LiquidityEngine::structuralTurnoverRatio((float) $stockData['volatility']));
        $stock->setImpactVarianceEma(0.0);
        // Opened at the structural variance rather than at zero. The index screens rank on this, and
        // a market whose whole board reads as perfectly quiet on day one would seat its
        // low-volatility index alphabetically and then spend a year unwinding it.
        $stock->setRealizedVarianceEma((float) $stockData['volatility'] ** 2);
        $stock->setCorporateFlowBacklog(0.0);

        // Not the whole float: most holders do not lend, which is what makes a name hard to borrow
        // long before anything like all of it has been shorted.
        $stock->setLendableSupplyRatio(FinancialConstants::DEFAULT_LENDABLE_SUPPLY_RATIO);
        $stock->setShortInterestShares('0.00');

        $businessModel = Sectors::businessModelFor($stockData['industry'] ?? null);
        $isFinancial = Sectors::isFinancial($businessModel);

        if ($isFinancial) {
            $roe = $stockData['baseline_roe'] ?? $stockData['baseline_roic'] ?? 0.10;
            $stock->setBaselineRoe((string) $roe);
            $stock->setCurrentRoe((string) $roe);
            $stock->setRoeTtm((string) $roe);
        } else {
            $roic = $stockData['baseline_roic'] ?? 0.10;
            $stock->setBaselineRoic((string) $roic);
            $stock->setCurrentRoic((string) $roic);
            $stock->setRoicTtm((string) $roic);
        }

        $stock->setCapexRatio((string) ($stockData['capex_ratio'] ?? 0.20));
        $stock->setTargetPayoutRatio((string) ($stockData['target_payout_ratio'] ?? 0.30));
        $stock->setDividendSpeed((string) ($stockData['dividendSpeed'] ?? 0.20));
        $stock->setDividendAristocrat((bool) ($stockData['dividend_aristocrat'] ?? false));
        $stock->setFixedCostRatio((float) ($stockData['fixed_cost_ratio'] ?? 0.35));
        $stock->setDepreciationRate((string) ($stockData['depreciation_rate'] ?? Sectors::metricsFor($stockData['industry'] ?? null)['depreciation']));

        $stock->setCorporateTreasury((string) ($stockData['corporate_treasury'] ?? 1000000000.00));
        $stock->setFloatingDebtRatio((string) ($stockData['floating_debt_ratio'] ?? 0.30));
        $stock->setOperatingMargin((string) ($stockData['operating_margin'] ?? 0.15));
        // Shares an anchor sphere holds are not tradable, so the float is the declared one less
        // whatever AnchorHoldings locks away. Derived so a stake and a float cannot drift apart.
        $stock->setPublicFloatPercentage((string) AnchorHoldings::tradableFloat(
            $stockData['ticker'],
            (float) ($stockData['public_float'] ?? 0.90)
        ));
        $stock->setTotalEquity((string) ($stockData['total_equity'] ?? 0.00));
        $stock->setWholesaleDebt((string) ($stockData['wholesale_debt'] ?? 0.00));
        $stock->setCustomerDeposits((string) ($stockData['customer_deposits'] ?? 0.00));
        $stock->setRetainedEarnings((string) ($stockData['retained_earnings'] ?? 0.00));
        $stock->setCreditSpread((string) ($stockData['credit_spread'] ?? 0.0100));
        $stock->setHistoricalFixedRate((string) ($stockData['historical_fixed_rate'] ?? 0.04));
        // Provisional, at book, until the market strikes the opening price below: the debt analysis sizes the
        // equity it weighs against the firm's own book rather than the entity's placeholder.
        $stock->setPrice($stock->getBookValuePerShare());
        $stock->setSamRatio((string) ($stockData['sam_ratio'] ?? 1.00));
        $stock->setManagementStyle(ManagementStyle::tryFromNullable($stockData['management_style'] ?? null));
        $stock->setCeoTenureYears(ManagementSuccessionEngine::drawSeedTenure($this->mathUtility));
        $stock->setManagementIntensity(ManagementProfile::drawIntensity($this->mathUtility));

        $margin = $stockData['operating_margin'] ?? 0.15;
        $strategy = Sectors::getBusinessModelStrategy($businessModel);

        // Query the exact structural metrics the engine uses to prevent massive gravity explosions on tick 1
        $targetMetrics = $strategy->getTargetMetrics($stock, $openingMacro, $this->mathUtility);
        $investedCapital = $targetMetrics['invested_capital'];
        $impliedRoic = max(0.01, (float) $targetMetrics['baseline_roic']);
        $taxRate = $openingMacro->corporateTaxRate;
        $preTaxRoic = $impliedRoic / (1.0 - $taxRate);
        $revenue = $margin > 0 ? ($investedCapital * ($preTaxRoic / $margin)) : 0.0;

        $stock->setTotalRevenue((string) $revenue);

        $debtHealth = $this->debtEngine->analyzeDebtHealth($stock, $openingMacro, $revenue, $margin);

        // Rate risk needs a cross-section or every lender lives and dies together. The seed names a
        // duration where a firm's book is distinctive; the rest take their model's. The AOCI
        // election is likewise per firm: it decides whether a capital ratio reacts to the curve at
        // all, and a board where everyone elected the same way has no dispersion in who survives.
        $stock->setSecuritiesDuration((float) ($stockData['securities_duration'] ?? $strategy->getDefaultSecuritiesDuration()));
        $stock->setAociFiltered((bool) ($stockData['aoci_filtered'] ?? (
            // The filter is an election the largest institutions do not get. Sized rather than
            // listed per firm, so the dispersion maintains itself as the seed changes: a bank big
            // enough to be systemically important marks its capital to the curve, and a small one
            // does not. Without the split every lender would react to rates identically.
            ((float) ($stockData['total_equity'] ?? 0.0)
                + (float) ($stockData['wholesale_debt'] ?? 0.0)
                + (float) ($stockData['customer_deposits'] ?? 0.0))
            < FinancialConstants::AOCI_FILTER_SIZE_THRESHOLD
        )));

        if ($isFinancial) {
            $netIncome = ((float) ($stockData['total_equity'] ?? 0.0)) * (float) $stock->getBaselineRoe();
        } else {
            $ebit = $revenue * $margin;
            $interestExpense = $debtHealth->rawMetrics->interestExpense;
            $interestIncome = $strategy->calculateInterestIncome($stock, $openingMacro, $this->mathUtility);
            $ebt = $ebit - $interestExpense + $interestIncome;
            $effectiveTaxRate = $strategy->getEffectiveTaxRate($taxRate);
            $netIncome = max(0.0, $ebt * (1.0 - $effectiveTaxRate));
        }

        $shares = $stockData['shares_outstanding'] ?? 1_000_000_000;
        $annualEps = $shares > 0 ? ($netIncome / $shares) : 0.0;
        $stock->setTotalNetIncome((string) $netIncome);
        $stock->setEarningsPerShare((string) round($annualEps, 2));

        // A board that has been paying opens where its policy has already brought it (Lintner 1956): the target
        // payout, carrying the manager's payout fixed effect, and never below a distribution the law requires.
        $startingDividend = max(0.0, $annualEps / 4.0) * max($stock->getPolicyPayoutRatio(), $strategy->getMinimumDistributionRatio());
        $stock->setLastDividend((string) $startingDividend);

        $sphere = null;

        // Open the fixed-asset ledger so the very first earnings report depreciates a real plant
        // rather than falling back to the capital proxy. Financial balance sheets keep no plant.
        if (!$isFinancial) {
            $metrics = CorporateMetrics::getInstance();
            $metrics->buildWorkingCapitalBalances(
                $stock,
                $strategy->getWorkingCapitalDays($stock),
                $revenue,
                $revenue * (1.0 - $margin)
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
                    $assetAgeRatio
                );
            } else {
                $sphere = ['stock' => $stock, 'invested' => $investedCapital, 'assetAgeRatio' => $assetAgeRatio];
            }
        } else {
            // A balance-sheet business opens its loan book instead, with the allowance already at
            // the lifetime loss it expects so the first report books no phantom provision.
            CorporateMetrics::getInstance()->seedEarningAssetLedger(
                $stock,
                $strategy->getThroughTheCycleCreditLossRate($stock)
                    * $strategy->getCreditLossHorizonYears()
                    * $strategy->getForwardCreditLossMultiplier($stock, $openingMacro)
            );
        }

        $this->strikeOpeningPrice($stock, $openingMacro);

        return $sphere;
    }

    /**
     * Strikes the price the firm opens at, on the inputs StockTracker prices it on at tick 1 — the same mapping and a
     * debt analysis of the finished balance sheet — or the market re-rates the instant it starts.
     *
     * The price and the firm's asset volatility are solved together: the configured equity volatility belongs to this
     * capital structure at this price, so the business risk it implies is calibrated on the price just struck, and the
     * cost of debt that risk sets feeds back into the next price. Iterated until the two agree, as the Merton
     * equity-value and asset-volatility pair is (Vassalou & Xing 2004).
     */
    private function strikeOpeningPrice(Stock $stock, MacroStateDTO $openingMacro): void
    {
        $previous = (float) $stock->getPrice();

        for ($pass = 0; $pass < self::MAX_OPENING_PRICE_PASSES; $pass++) {
            $pricingCtx = MarketPricingContext::forStock($stock, $openingMacro, $this->debtEngine->analyzeDebtHealth($stock, $openingMacro), $this->anchorStakes);
            $price = (float) $this->marketEngine->calculateNextPrice($pricingCtx)['perceived_fair_value'];
            $stock->setPrice((string) $price);
            $this->debtEngine->calibrateAssetVolatility($stock, $openingMacro->policyRateEma);

            if ($previous > 0.0 && abs($price / $previous - 1.0) < self::OPENING_PRICE_TOLERANCE) {
                return;
            }
            $previous = $price;
        }
    }

    /**
     * Finishes the spheres open() handed back. Their holdings are marked against the priced board, which is the
     * first moment the stakes can be valued at all, so a trust opens carrying what its holdings are worth and its
     * plant is carved out of a real portfolio instead of a guess at one.
     *
     * @param list<array{stock: Stock, invested: float, assetAgeRatio: float}> $spheres
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
                    100.0 * AnchorHoldings::MIN_CONSOLIDATED_SHARE
                ));
            }

            CorporateMetrics::getInstance()->seedFixedAssetLedger(
                $stock,
                $strategy->getPlantCapital($stock, $sphere['invested']),
                (float) $stock->getNetWorkingCapital(),
                (float) $stock->getGoodwill(),
                $stock->getTotalCipAmount(),
                $sphere['assetAgeRatio']
            );
        }
    }
}
