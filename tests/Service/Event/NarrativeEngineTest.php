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

    public function testSystemicEventsInterpolateMacroMetrics(): void
    {
        $freeze = $this->engine->generateLore(ShockEvent::SYSTEMIC_LIQUIDITY_FREEZE, [
            'interbank_spread_bps' => '125',
        ]);
        $this->assertStringContainsString('125 bps', $freeze);

        $seizure = $this->engine->generateLore(ShockEvent::CREDIT_MARKET_SEIZURE, [
            'hy_spread_pct' => '10.50',
        ]);
        $this->assertStringContainsString('10.50%', $seizure);

        $recession = $this->engine->generateLore(ShockEvent::RECESSION_DECLARED, [
            'recession_prob_pct' => '65.0',
            'output_gap_pct' => '-2.10',
        ]);
        $this->assertStringContainsString('65.0%', $recession);
        $this->assertStringContainsString('-2.10%', $recession);

        $inversion = $this->engine->generateLore(ShockEvent::YIELD_CURVE_INVERSION_ALARM, [
            'inversion_months' => '9.5',
        ]);
        $this->assertStringContainsString('9.5', $inversion);

        $qe = $this->engine->generateLore(ShockEvent::TITAN_INTERVENTION, [
            'qe_intensity_pct' => '1.50',
            'output_gap_pct' => '-1.80',
        ]);
        $this->assertStringContainsString('1.50%', $qe);

        $valuation = $this->engine->generateLore(ShockEvent::SOVEREIGN_WEALTH_DEPLOYMENT, [
            'erp_pct' => '7.50',
            'output_gap_pct' => '-1.20',
        ]);
        $this->assertStringContainsString('7.50%', $valuation);
        $this->assertStringContainsString('-1.20%', $valuation);
    }

    public function testSystemicEventsDoNotContainFantasyLore(): void
    {
        $eventsToVerify = [
            ShockEvent::SYSTEMIC_LIQUIDITY_FREEZE,
            ShockEvent::CREDIT_MARKET_SEIZURE,
            ShockEvent::RECESSION_DECLARED,
            ShockEvent::YIELD_CURVE_INVERSION_ALARM,
            ShockEvent::TITAN_INTERVENTION,
            ShockEvent::SOVEREIGN_WEALTH_DEPLOYMENT,
        ];

        foreach ($eventsToVerify as $eventType) {
            // Run multiple times since phrases are randomly picked
            for ($i = 0; $i < 10; ++$i) {
                $lore = $this->engine->generateLore($eventType, []);
                $this->assertStringNotContainsString('Sovereign wealth funds', $lore);
                $this->assertStringNotContainsString('District Titans', $lore);
                $this->assertStringNotContainsString('District Statistical Office', $lore);
                $this->assertStringNotContainsString('across the district', $lore);
                $this->assertStringNotContainsString('district balance sheets', $lore);
            }
        }
    }
}
