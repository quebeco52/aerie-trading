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

    public function testEiderDescriptionQuality(): void
    {
        $this->assertArrayHasKey('EIDR', StockInfo::DESCRIPTIONS);
        $eiderDesc = StockInfo::DESCRIPTIONS['EIDR'];

        $this->assertStringContainsString('Eider Motor Group', $eiderDesc);
        $this->assertStringContainsString('Granite Fleet', $eiderDesc);
        $this->assertGreaterThan(500, strlen($eiderDesc));

        $this->assertArrayHasKey('EIDR', StockInfo::QUOTES);
        $this->assertNotEmpty(StockInfo::QUOTES['EIDR']);
    }

    public function testErneDescriptionQuality(): void
    {
        $this->assertArrayHasKey('ERNE', StockInfo::DESCRIPTIONS);
        $erneDesc = StockInfo::DESCRIPTIONS['ERNE'];

        $this->assertStringContainsString('Erne Network Systems', $erneDesc);
        $this->assertStringContainsString('Masthead', $erneDesc);
        $this->assertGreaterThan(500, strlen($erneDesc));

        $this->assertArrayHasKey('ERNE', StockInfo::QUOTES);
        $this->assertNotEmpty(StockInfo::QUOTES['ERNE']);
    }

    public function testSanderlingDescriptionQuality(): void
    {
        $this->assertArrayHasKey('SNDR', StockInfo::DESCRIPTIONS);
        $sanderlingDesc = StockInfo::DESCRIPTIONS['SNDR'];

        $this->assertStringContainsString('Sanderling Rock Dynamics', $sanderlingDesc);
        $this->assertStringContainsString('cemented carbide', $sanderlingDesc);
        $this->assertGreaterThan(500, strlen($sanderlingDesc));

        $this->assertArrayHasKey('SNDR', StockInfo::QUOTES);
        $this->assertNotEmpty(StockInfo::QUOTES['SNDR']);
    }

    public function testNuthatchDescriptionQuality(): void
    {
        $this->assertArrayHasKey('NUTH', StockInfo::DESCRIPTIONS);
        $nuthDesc = StockInfo::DESCRIPTIONS['NUTH'];

        $this->assertStringContainsString('Nuthatch Climate Systems', $nuthDesc);
        $this->assertStringContainsString('heat pumps', $nuthDesc);
        $this->assertGreaterThan(500, strlen($nuthDesc));

        $this->assertArrayHasKey('NUTH', StockInfo::QUOTES);
        $this->assertNotEmpty(StockInfo::QUOTES['NUTH']);
    }

    public function testAlcaDescriptionQuality(): void
    {
        $this->assertArrayHasKey('ALCA', StockInfo::DESCRIPTIONS);
        $alcaDesc = StockInfo::DESCRIPTIONS['ALCA'];

        $this->assertStringContainsString('Alca Compression Dynamics', $alcaDesc);
        $this->assertStringContainsString('compressed air', $alcaDesc);
        $this->assertGreaterThan(500, strlen($alcaDesc));

        $this->assertArrayHasKey('ALCA', StockInfo::QUOTES);
        $this->assertNotEmpty(StockInfo::QUOTES['ALCA']);
    }
}
