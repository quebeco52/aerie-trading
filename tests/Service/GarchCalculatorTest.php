<?php

namespace App\Tests\Service;

use App\Service\GarchCalculator;
use PHPUnit\Framework\TestCase;

class GarchCalculatorTest extends TestCase
{
    private GarchCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new GarchCalculator();
    }

    public function testFallbackWithInsufficientData(): void
    {
        // The calculator needs at least 10 data points.
        // Anything less should immediately return the 0.15 baseline.
        $prices = [100.0, 101.0, 102.0, 103.0, 104.0];
        
        $volatility = $this->calculator->calculateLongTermVolatility($prices);
        
        $this->assertSame(0.15, $volatility, 'Expected fallback of 0.15 for insufficient data.');
    }

    public function testFlatlinePricesAreClampedToFloor(): void
    {
        // A completely flatlined stock has 0 variance. 
        // The calculator should prevent log(0) crashes and clamp the result to the 0.05 floor.
        $prices = array_fill(0, 20, 100.0);
        
        $volatility = $this->calculator->calculateLongTermVolatility($prices);
        
        $this->assertSame(0.05, $volatility, 'Expected 0 variance to be clamped to the 0.05 floor.');
    }

    public function testExtremeSwingsAreClampedToCeiling(): void
    {
        // A stock jumping by 1000% back and forth every single tick.
        // This would normally result in a volatility well over 1000%.
        // We want to ensure the 0.80 (80%) hard ceiling is respected.
        $prices = [];
        for ($i = 0; $i < 20; $i++) {
            $prices[] = ($i % 2 === 0) ? 10.0 : 1000.0;
        }
        
        $volatility = $this->calculator->calculateLongTermVolatility($prices);
        
        $this->assertSame(0.80, $volatility, 'Expected extreme variance to be clamped to the 0.80 ceiling.');
    }

    public function testHandlesZeroOrNegativePricesGracefully(): void
    {
        // GARCH calculates log-returns: log(curr / prev).
        // If prices ever drop to 0 or negative (due to a bug elsewhere), log() will crash.
        // The calculator's `max($price, 0.0001)` clamp should catch this safely.
        $prices = [100.0, 50.0, 0.0, -10.0, 0.0, 50.0, 100.0, 0.0, -5.0, 10.0, 20.0];
        
        $volatility = $this->calculator->calculateLongTermVolatility($prices);
        
        $this->assertIsFloat($volatility);
        $this->assertGreaterThanOrEqual(0.05, $volatility);
        $this->assertLessThanOrEqual(0.80, $volatility);
    }

    public function testTrendingDatasetDoesNotInflateVolatility(): void
    {
        // Simulate a stock that grows by exactly 1% every single tick (perfectly smooth trend).
        // Mathematically, the variance of a constant return is exactly 0.
        // Should be recognized as 0 variance and clamped to the 0.05 floor.
        $prices = [];
        $currentPrice = 100.0;
        
        for ($i = 0; $i < 50; $i++) {
            $prices[] = $currentPrice;
            $currentPrice *= exp(0.01); 
        }
        
        $volatility = $this->calculator->calculateLongTermVolatility($prices, 252);
        
        $this->assertSame(0.05, $volatility, 'Expected a perfectly smooth trend to have 0 variance, clamped to the 0.05 floor.');
    }

    public function testNormalMarketVolatility(): void
    {
        // Let's simulate a highly predictable, "normal" stock.
        // A stock zigzagging up and down by exactly 1% per day.
        // Daily Variance ~ 0.0001. Annualized Volatility = sqrt(0.0001 * 252) ≈ 0.158 (15.8%).
        $prices = [];
        $currentPrice = 100.0;
        
        for ($i = 0; $i < 50; $i++) {
            $prices[] = $currentPrice;
            // Alternate up 1% and down 1% in log space
            $currentPrice *= exp(0.01 * (($i % 2 === 0) ? 1 : -1)); 
        }
        
        $volatility = $this->calculator->calculateLongTermVolatility($prices, 252);
        
        // Using assertIsFloat and bounds is generally safer for complex statistical algorithms,
        // but we know it should mathematically land extremely close to ~15.8%.
        $this->assertGreaterThan(0.15, $volatility);
        $this->assertLessThan(0.17, $volatility);
        $this->assertEqualsWithDelta(0.158, $volatility, 0.01, 'Expected ~15.8% volatility for a 1% daily zigzag.');
    }

    public function testTicksPerYearScalingAppliesCorrectly(): void
    {
        // Annualization depends heavily on the 'frequency' of the data points.
        $prices = [];
        $currentPrice = 100.0;
        
        for ($i = 0; $i < 50; $i++) {
            $prices[] = $currentPrice;
            $currentPrice *= exp(0.01 * (($i % 2 === 0) ? 1 : -1)); 
        }
        
        // Calculate assuming data represents daily ticks (252 per year)
        $dailyVol = $this->calculator->calculateLongTermVolatility($prices, 252);
        
        // Calculate assuming data represents 1-minute ticks (e.g., 100,000 per year)
        $minuteVol = $this->calculator->calculateLongTermVolatility($prices, 100000);
        
        // Higher frequency scaling should result in a much larger annualized volatility
        // (Though the minuteVol will likely hit the 0.80 ceiling)
        $this->assertGreaterThan($dailyVol, $minuteVol, 'Higher ticksPerYear should scale the resulting volatility upwards.');
    }
}