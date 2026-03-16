<?php

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use App\Service\EarningsEngine;
use App\Service\MathUtility;
use App\Entity\Stock;
use App\Entity\StockEvent;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;

class EarningsEngineTest extends TestCase
{
    private EntityManagerInterface|MockObject $entityManagerMock;
    private MathUtility|MockObject $mathUtilityMock;
    private EarningsEngine $engine;

    protected function setUp(): void
    {
        // 1. Mock the Database Connection
        $this->entityManagerMock = $this->createMock(EntityManagerInterface::class);
        
        // 2. Mock MathUtility to control the stochastic Z-scores
        $this->mathUtilityMock = $this->createMock(MathUtility::class);

        // 3. Instantiate the Engine
        $this->engine = new EarningsEngine($this->entityManagerMock, $this->mathUtilityMock);
    }

    public function testCalculateReturnsNullWhenNoEventOccurs()
    {
        $stock = new Stock();
        
        // A dt of 0.0 guarantees the probability check (mt_rand / max < 4.0 * dt) will fail
        $result = $this->engine->calculate($stock, 0.0);
        
        $this->assertNull($result, 'Engine should return null when the earnings probability check fails.');
    }

    public function testStandardPositiveEarningsReport()
    {
        $stock = new Stock();
        $stock->setTicker('TEST');
        $stock->setEarningsPerShare('10.00');
        $stock->setSharesOutstanding('1000000');
        $stock->setVolatility('0.20');
        $stock->setCurrentVolatility('0.20');

        // Force a mildly positive business quarter
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.5);

        // Assert that the StockEvent is persisted
        $this->entityManagerMock->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(StockEvent::class));

        // Suppress the echo output to keep PHPUnit clean, but assert it happened
        $this->expectOutputRegex('/BREAKING NEWS: TEST reported earnings!/');

        // A dt of 1.0 guarantees the earnings event triggers
        $result = $this->engine->calculate($stock, 1.0);

        $this->assertNotNull($result);
        $this->assertEquals('EARNINGS', $result['type']);
        $this->assertEquals('TEST', $result['ticker']);
        
        // EPS should have increased
        $this->assertGreaterThan(10.00, (float) $stock->getEarningsPerShare());
        
        // Volatility should remain unchanged since Z-score (0.5) is <= 1.5
        $this->assertEquals(0.20, (float) $stock->getCurrentVolatility());
    }

    public function testExtremeEarningsTriggersVolatilityShock()
    {
        $stock = new Stock();
        $stock->setTicker('SHOCK');
        $stock->setEarningsPerShare('10.00');
        $stock->setSharesOutstanding('1000000');
        $stock->setVolatility('0.20');
        $stock->setCurrentVolatility('0.20');

        // Force an extreme blowout quarter (Z > 1.5 triggers the shock)
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(2.0);

        $this->expectOutputRegex('/BREAKING NEWS:/');
        $this->engine->calculate($stock, 1.0);

        // The math: shockMultiplier = 1.0 + (abs(2.0) * 0.15) = 1.3
        // newVol = 0.20 * 1.3 = 0.26
        $this->assertEquals(0.26, (float) $stock->getCurrentVolatility(), 'Volatility should have spiked due to the extreme Z-score.');
    }

    public function testNegativeEpsBenefitsFromRecoveryBoost()
    {
        $stock = new Stock();
        $stock->setTicker('RECOV');
        $stock->setEarningsPerShare('-10.00'); // Company is bleeding cash
        $stock->setSharesOutstanding('1000000');
        $stock->setVolatility('0.20');
        $stock->setCurrentVolatility('0.20');

        // Neutral quarter (0.0) isolates the recovery boost math
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $this->expectOutputRegex('/BREAKING NEWS:/');
        $this->engine->calculate($stock, 1.0);

        // Recovery boost adds 10% of the absolute loss to the bottom line (abs(-10) * 0.10 = +$1.00)
        $this->assertGreaterThan(-10.00, (float) $stock->getEarningsPerShare(), 'A company with negative EPS should receive a recovery boost.');
    }
}