<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\InitialMarket;
use App\Data\Sectors;
use App\Service\Market\Index\MarketIndex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InitialMarketTest extends TestCase
{
    public static function stockProvider(): array
    {
        $stocks = [];
        foreach (InitialMarket::STOCKS as $stock) {
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
            "Stock {$ticker} specifies unknown sector: '{$stock['sector']}'"
        );

        $this->assertArrayHasKey('industry', $stock, "Missing industry for {$ticker}");
        $this->assertArrayHasKey(
            $stock['industry'],
            Sectors::INDUSTRY_METRICS,
            "Stock {$ticker} specifies unknown industry: '{$stock['industry']}'"
        );

        // Core Financial Bounds
        $this->assertGreaterThan(0, $stock['shares_outstanding'], "Shares outstanding must be positive for {$ticker}");
        $this->assertGreaterThan(0.0, $stock['volatility'], "Volatility must be positive for {$ticker}");
        $this->assertLessThan(2.0, $stock['volatility'], "Volatility unreasonably high for {$ticker}");

        $this->assertGreaterThanOrEqual(-2.0, $stock['beta'], "Beta below -2.0 for {$ticker}");
        $this->assertLessThanOrEqual(4.0, $stock['beta'], "Beta above 4.0 for {$ticker}");

        $this->assertGreaterThanOrEqual(0.0, $stock['jump_intensity'], "Jump intensity negative for {$ticker}");
        $this->assertGreaterThanOrEqual(0.0, $stock['jump_vol'], "Jump volatility negative for {$ticker}");

        $this->assertGreaterThanOrEqual(0.0, $stock['capex_ratio'], "CapEx ratio negative for {$ticker}");
        $this->assertGreaterThanOrEqual(0.0, $stock['target_payout_ratio'], "Target payout ratio negative for {$ticker}");
        $this->assertLessThanOrEqual(1.0, $stock['target_payout_ratio'], "Target payout ratio exceeds 100% for {$ticker}");

        $this->assertGreaterThan(0.0, $stock['dividendSpeed'], "Dividend speed non-positive for {$ticker}");
        $this->assertGreaterThanOrEqual(0.0, $stock['fixed_cost_ratio'], "Fixed cost ratio negative for {$ticker}");
        $this->assertLessThanOrEqual(1.0, $stock['fixed_cost_ratio'], "Fixed cost ratio exceeds 1.0 for {$ticker}");

        $this->assertGreaterThanOrEqual(0.05, $stock['public_float'], "Public float below 5% for {$ticker}");
        $this->assertLessThanOrEqual(1.0, $stock['public_float'], "Public float exceeds 100% for {$ticker}");

        $this->assertGreaterThan(0.0, $stock['sam_ratio'], "SAM ratio non-positive for {$ticker}");

        $this->assertGreaterThanOrEqual(0.0, $stock['floating_debt_ratio'], "Floating debt ratio negative for {$ticker}");
        $this->assertLessThanOrEqual(1.0, $stock['floating_debt_ratio'], "Floating debt ratio exceeds 1.0 for {$ticker}");

        $this->assertGreaterThanOrEqual(0.0, $stock['credit_spread'], "Credit spread negative for {$ticker}");

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
            "Stock {$ticker} in industry {$industry} exceeds equity limit of {$equityLimit} (actual D/E: {$debtToEquity})"
        );
    }
}

