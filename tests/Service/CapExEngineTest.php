<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Stock;
use App\Service\Corporate\CapExEngine;
use PHPUnit\Framework\TestCase;

class CapExEngineTest extends TestCase
{
    private CapExEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new CapExEngine();
    }

    public function testAllocateGrowthCapExIncrementsCipBalance(): void
    {
        $stock = new Stock();
        $stock->setCipBalance('500000.00');

        $this->engine->allocateGrowthCapEx($stock, 250000.00);

        $this->assertEquals('750000', $stock->getCipBalance());
    }

    public function testProcessCipQueueWithZeroBalanceReturnsZero(): void
    {
        $stock = new Stock();
        $stock->setCipBalance('0.00');

        $placedInService = $this->engine->processCipQueue($stock);

        $this->assertSame(0.0, $placedInService);
        $this->assertEquals(0.0, (float) $stock->getCipBalance());
    }

    public function testProcessCipQueueAmortizesBasedOnSectorCompletionRate(): void
    {
        $stock = new Stock();
        $stock->setIndustry('Software - Infrastructure'); // business_model: tech, completion_rate = 0.50
        $stock->setCipBalance('1000000.00');

        $placedInService = $this->engine->processCipQueue($stock);

        $this->assertEquals(500000.00, $placedInService);
        $this->assertEquals('500000', $stock->getCipBalance());
    }

    public function testProcessCipQueueSnapToZeroTailHandling(): void
    {
        $stock = new Stock();
        $stock->setIndustry('Semiconductors'); // business_model: semiconductor, completion_rate = ~0.20
        // If remaining balance after completion is < 1000, snap to zero
        $stock->setCipBalance('1050.00');

        $placedInService = $this->engine->processCipQueue($stock);

        // With 1050 balance and 20% completion (210), remaining 840 is < 1000 -> snap all 1050 into completed
        $this->assertEquals(1050.00, $placedInService);
        $this->assertEquals('0', $stock->getCipBalance());
    }
}
