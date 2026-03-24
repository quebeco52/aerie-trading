<?php

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use App\Service\EarningsEngine;
use App\Service\MathUtility;
use App\Service\MarketEvent;
use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;

class EarningsEngineTest extends TestCase
{
    private EntityManagerInterface|MockObject $entityManagerMock;
    private MathUtility|MockObject $mathUtilityMock;
    private MarketEvent|MockObject $marketEventMock;
    private EarningsEngine $engine;

    protected function setUp(): void
    {
        // 1. Mock the Database Connection
        $this->entityManagerMock = $this->createMock(EntityManagerInterface::class);
        
        // 2. Mock MarketEvent (now responsible for outputs and persisting)
        $this->marketEventMock = $this->createMock(MarketEvent::class);
        $this->marketEventMock->method('publish')->willReturnCallback(function($stock, $type, $desc, $pct) {
            return [
                'type' => $type,
                'ticker' => $stock->getTicker(),
                'description' => $desc,
                'change_percent' => $pct
            ];
        });

        // 3. Mock MathUtility to control the stochastic Z-scores
        $this->mathUtilityMock = $this->createMock(MathUtility::class);

        // 4. Instantiate the Engine
        $this->engine = new EarningsEngine($this->entityManagerMock, $this->marketEventMock, $this->mathUtilityMock);
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

        // A dt of 1.0 guarantees the earnings event triggers
        $result = $this->engine->calculate($stock, 1.0);

        $this->assertNotNull($result);
        $this->assertEquals('EARNINGS', $result['type']);
        $this->assertEquals('TEST', $result['ticker']);
        
        // EPS should have increased
        $this->assertGreaterThan(10.00, (float) $stock->getEarningsPerShare());
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

        $this->engine->calculate($stock, 1.0);

        $this->assertGreaterThan(0.20, (float) $stock->getCurrentVolatility(), 'Volatility should have spiked due to the extreme surprise.');
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

        $this->engine->calculate($stock, 1.0);

        $this->assertNotEquals(-10.00, (float) $stock->getEarningsPerShare(), 'A company with negative EPS should still see EPS changes.');
    }
}