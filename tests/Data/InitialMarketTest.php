<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\InitialMarket;
use App\Data\Sectors;
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

        if (isset($stock['customer_deposits'])) {
            $this->assertGreaterThanOrEqual(0.0, $stock['customer_deposits'], "Customer deposits negative for {$ticker}");
        }
    }
}
