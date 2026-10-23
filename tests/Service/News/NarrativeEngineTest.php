<?php

declare(strict_types=1);

namespace App\Tests\Service\News;

use App\Service\Event\ShockEvent;
use App\Service\News\NarrativeEngine;
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
            $lore = $this->engine->generateLore($eventType, ['amount' => '12.50'] + self::MACRO_CONTEXT);

            $this->assertIsString($lore, "Lore for {$name} ({$eventType}) must be a string.");
            $this->assertNotEmpty($lore, "Lore for {$name} ({$eventType}) must not be empty.");
            $this->assertNotSame(
                'Experienced an unexpected market event.',
                $lore,
                "ShockEvent::{$name} ({$eventType}) must have a defined lore branch in NarrativeEngine.",
            );
        }
    }

    public function testGenerateLoreWithEmptyContextHandlesFallbacksGracefully(): void
    {
        $loreBankRun = $this->engine->generateLore(ShockEvent::BANK_RUN, []);
        $this->assertStringContainsString('$0.00 ', $loreBankRun);

        $loreFlight = $this->engine->generateLore(ShockEvent::CUSTOMER_DEPOSIT_FLIGHT, []);
        $this->assertStringContainsString('$0.00 ', $loreFlight);

        $loreDeposits = $this->engine->generateLore(ShockEvent::CAPTURED_NEW_DEPOSITS, []);
        $this->assertStringContainsString('$0.00 ', $loreDeposits);
    }

    /** An amount arrives in dollars and is quoted at its own scale: a $4.2M run does not read as "$0.00B". */
    public function testAStoryQuotesASmallAmountInMillions(): void
    {
        $this->assertStringContainsString('$4.20M', $this->engine->generateLore(ShockEvent::BANK_RUN, ['amount' => 4.2e6]));
        $this->assertStringContainsString('$1.25B', $this->engine->generateLore(ShockEvent::CUSTOMER_DEPOSIT_FLIGHT, ['amount' => 1.25e9]));
    }

    public function testGenerateLoreWithCustomContextInterpolation(): void
    {
        $lore = $this->engine->generateLore(ShockEvent::CUSTOMER_DEPOSIT_FLIGHT, ['amount' => 4.2e9]);
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
            'unemployment_pct' => '5.3',
            'gdp_fall_pct' => '0.7',
            'output_gap_pct' => '-2.10',
        ]);
        $this->assertStringContainsString('0.7%', $recession);

        $inversion = $this->engine->generateLore(ShockEvent::YIELD_CURVE_INVERSION_ALARM, [
            'inversion_months' => '9.5',
        ]);
        $this->assertStringContainsString('9.5', $inversion);

        $qe = $this->engine->generateLore(ShockEvent::TITAN_INTERVENTION, [
            'output_gap_pct' => '-1.80',
        ]);
        $this->assertStringContainsString('-1.80%', $qe);

        $deployment = $this->engine->generateLore(ShockEvent::SOVEREIGN_WEALTH_DEPLOYMENT, [
            'swf_trade_pct' => '2.36',
            'swf_weight_pct' => '3.51',
            'swf_target_pct' => '4.93',
            'swf_size_gdp_pct' => '109',
            'swf_months' => '3',
        ]);
        $this->assertStringContainsString('2.36%', $deployment);
        $this->assertStringContainsString('3.51%', $deployment);
        $this->assertStringContainsString('4.93%', $deployment);

        $trim = $this->engine->generateLore(ShockEvent::SOVEREIGN_WEALTH_TRIM, [
            'swf_trade_pct' => '1.10',
            'swf_weight_pct' => '6.40',
            'swf_target_pct' => '4.93',
            'swf_size_gdp_pct' => '115',
            'swf_months' => '3',
        ]);
        $this->assertStringContainsString('1.10%', $trim);
        $this->assertStringContainsString('sell', $trim);
    }

    /** Every macro reading SystemicEventReporter quotes, as it always sends them. */
    private const MACRO_CONTEXT = [
        'interbank_spread_bps' => '187',
        'hy_spread_pct' => '8.40',
        'unemployment_pct' => '6.3',
        'output_gap_pct' => '-2.10',
        'inversion_months' => '7.5',
        'epu_index' => '140',
        'sovereign_spread_bps' => '95',
        'debt_to_gdp_pct' => '71',
        'cat_severity' => '3.4',
        'dsr_pct' => '14.2',
        'debt_to_income_pct' => '128',
        'credit_gap_pct' => '9.8',
        'swf_trade_pct' => '0.85',
        'swf_weight_pct' => '18.20',
        'swf_target_pct' => '22.00',
        'swf_size_gdp_pct' => '64',
        'swf_months' => '9',
    ];

    public function testSystemicEventsDoNotContainFantasyLore(): void
    {
        $eventsToVerify = [
            ShockEvent::SYSTEMIC_LIQUIDITY_FREEZE,
            ShockEvent::CREDIT_MARKET_SEIZURE,
            ShockEvent::RECESSION_DECLARED,
            ShockEvent::YIELD_CURVE_INVERSION_ALARM,
            ShockEvent::TITAN_INTERVENTION,
            ShockEvent::SOVEREIGN_WEALTH_DEPLOYMENT,
            ShockEvent::SOVEREIGN_WEALTH_TRIM,
        ];

        foreach ($eventsToVerify as $eventType) {
            // Run multiple times since phrases are randomly picked
            for ($i = 0; $i < 10; ++$i) {
                $lore = $this->engine->generateLore($eventType, self::MACRO_CONTEXT);
                $this->assertStringNotContainsString('Sovereign wealth funds', $lore);
                $this->assertStringNotContainsString('District Titans', $lore);
                $this->assertStringNotContainsString('District Statistical Office', $lore);
                $this->assertStringNotContainsString('across the district', $lore);
                $this->assertStringNotContainsString('district balance sheets', $lore);
            }
        }
    }
}
