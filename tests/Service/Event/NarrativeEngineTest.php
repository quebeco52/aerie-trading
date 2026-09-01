<?php

declare(strict_types=1);

namespace App\Tests\Service\Event;

use App\Service\Event\NarrativeEngine;
use App\Service\Event\ShockEvent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class NarrativeEngineTest extends TestCase
{
    private NarrativeEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new NarrativeEngine();
    }

    public function testGenerateLoreReturnsValidLoreForAllShockEvents(): void
    {
        $shockClass = new ReflectionClass(ShockEvent::class);
        $constants = $shockClass->getConstants();

        $this->assertNotEmpty($constants, 'ShockEvent constants must not be empty.');

        foreach ($constants as $name => $eventType) {
            $lore = $this->engine->generateLore($eventType, ['amount' => '12.50']);

            $this->assertIsString($lore, "Lore for {$name} ({$eventType}) must be a string.");
            $this->assertNotEmpty($lore, "Lore for {$name} ({$eventType}) must not be empty.");
            $this->assertNotSame(
                'Experienced an unexpected market event.',
                $lore,
                "ShockEvent::{$name} ({$eventType}) must have a defined lore branch in NarrativeEngine."
            );
        }
    }

    public function testGenerateLoreWithEmptyContextHandlesFallbacksGracefully(): void
    {
        $loreBankRun = $this->engine->generateLore(ShockEvent::BANK_RUN, []);
        $this->assertStringContainsString('$0.00B', $loreBankRun);

        $loreFlight = $this->engine->generateLore(ShockEvent::CUSTOMER_DEPOSIT_FLIGHT, []);
        $this->assertStringContainsString('$0.00B', $loreFlight);

        $loreDeposits = $this->engine->generateLore(ShockEvent::CAPTURED_NEW_DEPOSITS, []);
        $this->assertStringContainsString('$0.00B', $loreDeposits);
    }

    public function testGenerateLoreWithCustomContextInterpolation(): void
    {
        $lore = $this->engine->generateLore(ShockEvent::CUSTOMER_DEPOSIT_FLIGHT, ['amount' => '4.20']);
        $this->assertStringContainsString('$4.20B', $lore);
    }

    public function testGenerateLoreUnknownEventReturnsDefault(): void
    {
        $lore = $this->engine->generateLore('totally_unknown_custom_event_xyz');
        $this->assertSame('Experienced an unexpected market event.', $lore);
    }
}
