<?php

declare(strict_types=1);

namespace App\Tests\Data\Company;

use App\Data\Company\CostStructure;
use App\Data\Company\IndustryBetas;
use App\Data\Company\IndustryPayoutPolicies;
use App\Data\Company\InitialMarket;
use App\Data\Company\PayoutPolicy;
use App\Data\Company\Sectors;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Market\Index\MarketIndex;
use App\Service\Market\Pricing\SeedRows;
use App\Service\Math\FinancialConstants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class InitialMarketTest extends TestCase
{
    public static function stockProvider(): array
    {
        $stocks = [];
        foreach (SeedRows::all() as $stock) {
            $ticker = $stock['ticker'] ?? 'UNKNOWN';
            $stocks[$ticker] = [$stock];
        }
        return $stocks;
    }

    public function testInitialMarketStockCountIsNonEmpty(): void
    {
        $this->assertNotEmpty(InitialMarket::STOCKS, 'InitialMarket::STOCKS must contain stock definitions');
        $this->assertGreaterThanOrEqual(10, count(InitialMarket::STOCKS), 'Expected at least 10 baseline stocks in initial district');
    }

    public function testNoDuplicateTickersOrNames(): void
    {
        $tickers = [];
        $names = [];

        foreach (InitialMarket::STOCKS as $stock) {
            $ticker = $stock['ticker'];
            $name = $stock['name'];

            $this->assertArrayNotHasKey($ticker, $tickers, "Duplicate stock ticker found: {$ticker}");
            $this->assertArrayNotHasKey($name, $names, "Duplicate stock name found: {$name}");

            $tickers[$ticker] = true;
            $names[$name] = true;
        }
    }

    // --- The Fund Lineup ---

    /**
     * Every published index has a fund to trade it, and every fund tracks a published index.
     *
     * The two lists are maintained in different files and nothing else checks them against each other. An
     * index without a fund is struck every tick against a row that does not exist; a fund without an index
     * is a tradable instrument nothing ever prices.
     */
    public function testEveryPublishedIndexHasExactlyOneFund(): void
    {
        $funds = array_column(InitialMarket::ETFS, null, 'ticker');

        $this->assertCount(count(MarketIndex::cases()), InitialMarket::ETFS);

        foreach (MarketIndex::cases() as $index) {
            $this->assertArrayHasKey($index->value, $funds, "No fund is seeded for {$index->value}.");
        }
    }

    /**
     * A fund is named the way real funds are named: sponsor, then mandate, then vehicle.
     *
     * The convention is the realism. "Skein Low Volatility ETF" says who runs it, what it does and what it
     * is; "Lakebird Low Volatility" said only the middle one and read like the index it tracks. The index
     * family name is deliberately NOT what a fund is called — that is checked on the enum side.
     */
    public function testFundNamesCarryTheirSponsorAndTheirVehicle(): void
    {
        foreach (InitialMarket::ETFS as $fund) {
            $this->assertStringStartsWith('Skein ', $fund['name'], 'the sponsor leads the name');
            $this->assertStringEndsWith(' ETF', $fund['name'], 'the vehicle closes it');
            $this->assertGreaterThanOrEqual(3, count(explode(' ', $fund['name'])));
        }

        $names = array_column(InitialMarket::ETFS, 'name');
        $this->assertSame($names, array_unique($names), 'two funds may not share a name');
    }

    /**
     * Every fund charges something, and none of them charges a lot.
     *
     * The sponsor is mutually owned by the funds it runs, so there are no shareholders to earn for and the
     * fee is what the mandate actually costs to operate. A zero would mean a fund that runs itself; a fat
     * one would mean a house quietly taking a margin the structure says it cannot take.
     */
    public function testEveryFundChargesAPlausibleAtCostFee(): void
    {
        foreach (InitialMarket::ETFS as $fund) {
            $this->assertArrayHasKey('expense_ratio', $fund, "{$fund['ticker']} has no fee.");
            $this->assertGreaterThan(0.0, $fund['expense_ratio'], "{$fund['ticker']} must cost something to run.");
            $this->assertLessThanOrEqual(0.0030, $fund['expense_ratio'], "{$fund['ticker']} is dearer than an at-cost house can justify.");
        }

        $fees = array_column(InitialMarket::ETFS, 'expense_ratio', 'ticker');

        // The ordering is structural, not decorative. A whole-board fund that admits and drops nothing has
        // almost nothing to trade; a fund that re-ranks the market on a risk measure every quarter has to
        // trade its whole book to match, and that is what its holders are paying for.
        $this->assertLessThan($fees['LBI'], $fees['LBC'], 'the whole-board fund is the cheapest to run');
        $this->assertLessThan($fees['LBS'], $fees['LBI'], 'a sector mandate costs more than a broad one');
        $this->assertLessThan($fees['LBV'], $fees['LBS'], 'the rules-based fund turns over most and costs most');
    }

    #[DataProvider('stockProvider')]
    public function testStockSchemaAndInvariants(array $stock): void
    {
        $ticker = $stock['ticker'] ?? '';
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{2,6}$/', $ticker, "Invalid ticker format for {$ticker}");
        $this->assertNotEmpty($stock['name'] ?? '', "Stock name must not be empty for {$ticker}");

        // Sector & Industry validation
        $this->assertArrayHasKey('sector', $stock, "Missing sector for {$ticker}");
        $this->assertArrayHasKey(
            $stock['sector'],
            Sectors::MACRO_SECTORS,
            "Stock {$ticker} specifies unknown sector: '{$stock['sector']}'",
        );

        $this->assertArrayHasKey('industry', $stock, "Missing industry for {$ticker}");
        $this->assertArrayHasKey(
            $stock['industry'],
            Sectors::INDUSTRY_METRICS,
            "Stock {$ticker} specifies unknown industry: '{$stock['industry']}'",
        );

        // Core Financial Bounds
        $this->assertGreaterThan(0, $stock['shares_outstanding'], "Shares outstanding must be positive for {$ticker}");
        foreach (\App\Data\Company\IndustryRisks::DERIVED_KEYS as $derivedKey) {
            $this->assertArrayNotHasKey($derivedKey, $stock, "{$ticker}: {$derivedKey} is derived at opening from IndustryRisks and leverage");
        }

        $this->assertArrayNotHasKey('beta', $stock, "{$ticker}: lore sets a position in the industry's band, not a beta");
        $this->assertLessThanOrEqual(IndustryBetas::LORE_BAND, abs((float) ($stock['beta_tilt'] ?? 0.0)), "{$ticker}: beta_tilt outside the industry band");

        $this->assertGreaterThanOrEqual(0.0, $stock['capex_ratio'] ?? 0.0, "CapEx ratio negative for {$ticker}");

        $this->assertGreaterThanOrEqual(0.0, $stock['fixed_cost_ratio'], "Fixed cost ratio negative for {$ticker}");
        $this->assertLessThanOrEqual(1.0, $stock['fixed_cost_ratio'], "Fixed cost ratio exceeds 1.0 for {$ticker}");

        $this->assertGreaterThanOrEqual(0.05, $stock['public_float'], "Public float below 5% for {$ticker}");
        $this->assertLessThanOrEqual(1.0, $stock['public_float'], "Public float exceeds 100% for {$ticker}");

        $this->assertGreaterThan(0.0, $stock['sam_ratio'], "SAM ratio non-positive for {$ticker}");

        if (isset($stock['floating_debt_ratio'])) {
            $this->assertGreaterThanOrEqual(0.0, $stock['floating_debt_ratio'], "Floating debt ratio negative for {$ticker}");
            $this->assertLessThanOrEqual(1.0, $stock['floating_debt_ratio'], "Floating debt ratio exceeds 1.0 for {$ticker}");
        }

        foreach (SeedRows::DERIVED_KEYS as $derivedKey) {
            $this->assertArrayNotHasKey($derivedKey, $stock, "{$ticker}: {$derivedKey} is derived at opening from rating and LGD");
        }

        // Balance sheet non-negatives
        $this->assertGreaterThanOrEqual(0.0, $stock['corporate_treasury'], "Corporate treasury negative for {$ticker}");
        $this->assertGreaterThan(0.0, $stock['total_equity'], "Total equity non-positive for {$ticker}");
        $this->assertGreaterThanOrEqual(0.0, $stock['wholesale_debt'], "Wholesale debt negative for {$ticker}");
        $this->assertGreaterThanOrEqual(0.0, $stock['retained_earnings'] ?? 0.0, "Retained earnings negative for {$ticker}");
        $this->assertLessThanOrEqual($stock['total_equity'], $stock['retained_earnings'] ?? 0.0, "Retained earnings exceed total equity for {$ticker}");

        if (isset($stock['customer_deposits'])) {
            $this->assertGreaterThanOrEqual(0.0, $stock['customer_deposits'], "Customer deposits negative for {$ticker}");
        }

        // Debt-to-Equity limit invariant
        $industry = $stock['industry'];
        $equityLimit = Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? 1.5;
        $debtToEquity = $stock['wholesale_debt'] / $stock['total_equity'];
        $this->assertLessThanOrEqual(
            $equityLimit + 1e-6,
            $debtToEquity,
            "Stock {$ticker} in industry {$industry} exceeds equity limit of {$equityLimit} (actual D/E: {$debtToEquity})",
        );
    }

    /**
     * A raw row states what sets the firm apart, not a default: no share count, a depreciation rate or dividend policy
     * only where it differs from its industry's, no dividend speed (its model's), and a reinvestment rate (capex_ratio)
     * only on a lender, the one firm that reads it.
     */
    public function testEveryRawRowStatesOnlyWhatSetsItApart(): void
    {
        foreach (InitialMarket::STOCKS as $row) {
            $ticker = $row['ticker'];
            $industry = (string) $row['industry'];
            $this->assertArrayNotHasKey('shares_outstanding', $row, "{$ticker} restates the share count every firm lists with.");
            if (isset($row['depreciation_rate'])) {
                $this->assertNotEqualsWithDelta(Sectors::metricsFor($industry)['depreciation'], $row['depreciation_rate'], 1e-12, "{$ticker} restates its industry's depreciation rate.");
            }
            if (isset($row['payout'])) {
                $this->assertNotSame(IndustryPayoutPolicies::forIndustry($industry), PayoutPolicy::from((string) $row['payout']), "{$ticker} restates its industry's dividend policy.");
            }
            $this->assertArrayNotHasKey('target_payout_ratio', $row, "{$ticker} states a payout ratio; it names a dividend policy step.");
            $this->assertArrayNotHasKey('dividendSpeed', $row, "{$ticker} states a dividend speed; its business model sets it.");
            $this->assertArrayNotHasKey('credit_spread', $row, "{$ticker} states credit_spread; it is derived from the opening rating and LGD.");
            $this->assertArrayNotHasKey('systemic_importance', $row, "{$ticker} states systemic_importance; standing is retired and read from live size.");
            $strategy = Sectors::strategyFor($industry);
            $isLender = Sectors::isFinancial(Sectors::businessModelFor($industry));
            $this->assertSame($isLender, isset($row['capex_ratio']), $isLender ? "{$ticker} is a lender with no reinvestment rate." : "{$ticker} states a reinvestment rate its capital budget never reads.");
            $this->assertSame(
                !$strategy->derivesOpeningCoupon(),
                array_key_exists('historical_fixed_rate', $row),
                "{$ticker} historical_fixed_rate presence must match whether its model derives it at opening.",
            );
            $this->assertSame(
                !$strategy->derivesOpeningFloatingShare(),
                array_key_exists('floating_debt_ratio', $row),
                "{$ticker} floating_debt_ratio presence must match whether its model derives it at opening.",
            );
            $this->assertSame(
                !$strategy->derivesOpeningFixedCostRatio(),
                array_key_exists('fixed_cost_ratio', $row),
                "{$ticker} fixed_cost_ratio presence must match whether its model derives it at opening.",
            );
            if (isset($row['cost_structure'])) {
                $this->assertNotSame(CostStructure::Typical->value, $row['cost_structure'], "{$ticker} states typical cost_structure; omit the default.");
            }
        }
    }

    /**
     * Each raw row states its books, or a character its own model builds books from: a character on a model that
     * builds none would open a firm with no balance sheet.
     */
    public function testEveryRawRowStatesItsBooksOrACharacterItsModelBuilds(): void
    {
        $bookKeys = ['total_equity', 'wholesale_debt', 'corporate_treasury', 'operating_margin', 'sam_ratio'];
        foreach (InitialMarket::STOCKS as $row) {
            $ticker = $row['ticker'];
            if (isset($row['revenue'])) {
                $this->assertArrayNotHasKey('total_equity', $row, "{$ticker} states a character and a book at once.");
                $this->assertArrayHasKey('total_equity', SeedRows::resolve($row), "{$ticker} states a character its model builds no books from.");

                continue;
            }
            foreach ($bookKeys as $key) {
                $this->assertArrayHasKey($key, $row, "{$ticker} states neither its {$key} nor a character.");
            }
            $this->assertTrue(isset($row['baseline_roe']) || isset($row['baseline_roic']), "{$ticker} states no return.");
        }
    }

    /**
     * A financial's scale is its equity, and past half its market every unit of it earns less: no lender, insurer or
     * fund opens past that share, or its profile return could never be earned from the first report.
     */
    public function testNoFinancialOpensPastTheShareItsReturnsHoldTo(): void
    {
        foreach (SeedRows::all() as $row) {
            if (!Sectors::strategyFor($row['industry'])->isFinancial()) {
                continue;
            }
            $share = $row['total_equity'] / (CorporateMetrics::BASELINE_SECTOR_TAM * $row['sam_ratio']);
            $this->assertLessThanOrEqual(FinancialConstants::DISECONOMY_OPTIMAL_SHARE_THRESHOLD, $share, $row['ticker']);
        }
    }

    /** A card lender opens inside the leverage its own sector steers it to, or its first year is spent deleveraging. */
    public function testACardLenderOpensInsideItsSectorsLeverageTarget(): void
    {
        $lenders = 0;
        foreach (SeedRows::all() as $row) {
            if ($row['industry'] !== 'Credit Services') {
                continue;
            }
            $lenders++;
            $target = Sectors::strategyFor($row['industry'])->getLeverageTarget(Sectors::INDUSTRY_METRICS[$row['industry']]['equity_limit']);
            $debtToEquity = ($row['customer_deposits'] + $row['wholesale_debt']) / $row['total_equity'];
            $this->assertLessThanOrEqual($target + 1e-9, $debtToEquity, $row['ticker']);
        }
        $this->assertGreaterThan(0, $lenders);
    }

    /** SeedRows refuses any seed row that states credit_spread, or states derived debt terms on an in-scope firm. */
    public function testSeedRowsRefusesCreditSpreadAndDerivedDebtTermKeys(): void
    {
        $byTicker = array_column(InitialMarket::STOCKS, null, 'ticker');
        foreach (['WING', 'LAKE', 'BRKW'] as $ticker) {
            try {
                SeedRows::resolve($byTicker[$ticker] + ['credit_spread' => 0.0150]);
                $this->fail("SeedRows::resolve() should refuse 'credit_spread' on {$ticker}.");
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        foreach (['WING', 'SHOR', 'BRKW', 'SAFE', 'ACC', 'FINC', 'SWAN'] as $ticker) {
            try {
                SeedRows::resolve($byTicker[$ticker] + ['historical_fixed_rate' => 0.0450]);
                $this->fail("SeedRows::resolve() should refuse 'historical_fixed_rate' on {$ticker}.");
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        foreach (['WING', 'SHOR', 'BRKW', 'SAFE', 'ACC'] as $ticker) {
            try {
                SeedRows::resolve($byTicker[$ticker] + ['floating_debt_ratio' => 0.20]);
                $this->fail("SeedRows::resolve() should refuse 'floating_debt_ratio' on {$ticker}.");
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        // Lenders keep both keys; brokers and asset managers keep floating_debt_ratio.
        foreach (['LAKE', 'FINC', 'SWAN'] as $ticker) {
            $resolved = SeedRows::resolve($byTicker[$ticker]);
            $this->assertArrayHasKey('floating_debt_ratio', $resolved, $ticker);
        }
        $this->assertArrayHasKey('historical_fixed_rate', SeedRows::resolve($byTicker['LAKE']));

        foreach (['WING', 'LAKE', 'BRKW'] as $ticker) {
            try {
                SeedRows::resolve($byTicker[$ticker] + ['systemic_importance' => 'titan']);
                $this->fail("SeedRows::resolve() should refuse retired 'systemic_importance' on {$ticker}.");
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * A row states only what sets the firm apart: never the sector its industry files under, the default float or a
     * zero tilt.
     */
    public function testNoSeedRowStatesWhatItsIndustryOrTheDefaultsAlreadyGive(): void
    {
        foreach (InitialMarket::STOCKS as $row) {
            $ticker = $row['ticker'];
            if (isset($row['sector'])) {
                $this->assertNotSame(Sectors::sectorFor($row['industry']), $row['sector'], "{$ticker} states the sector its industry already files under.");
            }
            if (isset($row['public_float'])) {
                $this->assertNotEquals(SeedRows::DEFAULT_PUBLIC_FLOAT, $row['public_float'], "{$ticker} states the default float.");
            }
            if (isset($row['beta_tilt'])) {
                $this->assertNotEquals(0.0, (float) $row['beta_tilt'], "{$ticker} states a zero beta tilt.");
            }
        }

        $this->assertSame(0.90, SeedRows::DEFAULT_PUBLIC_FLOAT);
        $this->assertSame(0.90, SeedRows::for('OSPR')['public_float']);
        $this->assertSame(0.90, SeedRows::for('HUMM')['public_float']);
        $this->assertSame(0.85, SeedRows::for('CORV')['public_float']);
        $this->assertSame(0.95, SeedRows::for('GRIP')['public_float']);
    }
}
