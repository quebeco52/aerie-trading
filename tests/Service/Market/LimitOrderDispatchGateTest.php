<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Service\Market\LimitOrderDispatchGate;
use PHPUnit\Framework\TestCase;

class LimitOrderDispatchGateTest extends TestCase
{
    public function testAnInstrumentDispatchesOncePerRetryWindowWhileItsConditionHolds(): void
    {
        $gate = new LimitOrderDispatchGate();

        $this->assertTrue($gate->allow('G30-043', 1000));
        // Every tick until the worker writes the bounds key used to dispatch again.
        for ($tick = 1001; $tick < 1000 + LimitOrderDispatchGate::RETRY_TICKS; $tick++) {
            $this->assertFalse($gate->allow('G30-043', $tick), "tick $tick");
        }
        // A message that was lost is retried once the window has passed.
        $this->assertTrue($gate->allow('G30-043', 1000 + LimitOrderDispatchGate::RETRY_TICKS));
        $this->assertSame(1, $gate->pending());
    }

    public function testInstrumentsAreGatedIndependently(): void
    {
        $gate = new LimitOrderDispatchGate();

        $this->assertTrue($gate->allow('WREN', 5));
        $this->assertTrue($gate->allow('WEAV', 5));
        $this->assertFalse($gate->allow('WREN', 6));
        $this->assertSame(2, $gate->pending());
    }

    public function testSettlingReopensTheGateAtOnce(): void
    {
        $gate = new LimitOrderDispatchGate();

        // Price crossed a resting order; the handler filled it and rewrote bounds the price sits inside.
        $this->assertTrue($gate->allow('WREN', 10));
        $gate->settle('WREN');
        $this->assertSame(0, $gate->pending());

        // A new order crossed on the very next tick is dispatched without waiting out the window.
        $this->assertTrue($gate->allow('WREN', 11));
    }
}
