<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\InitialMarket;
use App\Data\StockInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StockInfoTest extends TestCase
{
    public static function tickerProvider(): array
    {
        $tickers = [];
        foreach (InitialMarket::STOCKS as $stock) {
            $ticker = $stock['ticker'] ?? 'UNKNOWN';
            $tickers[$ticker] = [$ticker];
        }
        return $tickers;
    }

    public function testDescriptionsArrayIsNotEmpty(): void
    {
        $this->assertNotEmpty(StockInfo::DESCRIPTIONS, 'StockInfo::DESCRIPTIONS must not be empty.');
    }

    #[DataProvider('tickerProvider')]
    public function testStockHasValidDescription(string $ticker): void
    {
        if (isset(StockInfo::DESCRIPTIONS[$ticker])) {
            $desc = StockInfo::DESCRIPTIONS[$ticker];
            $this->assertIsString($desc);
            $this->assertGreaterThan(50, strlen($desc), "Description for {$ticker} is too short.");
        } else {
            $this->markTestSkipped("No description yet defined for {$ticker}.");
        }
    }

    public function testStarlingDescriptionQuality(): void
    {
        $this->assertArrayHasKey('STAR', StockInfo::DESCRIPTIONS);
        $starDesc = StockInfo::DESCRIPTIONS['STAR'];

        $this->assertStringContainsString('Starling Academic Systems', $starDesc);
        $this->assertStringContainsString('District', $starDesc);
        $this->assertGreaterThan(500, strlen($starDesc));
    }
}
