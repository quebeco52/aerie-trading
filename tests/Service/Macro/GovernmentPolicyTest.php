<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro;

use App\DTO\GovernmentPolicyDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Covers what the economy does with the policy the government hands it: the levers go into force as handed over, and a
 * tariff's change costs productivity (Furceri, Hannan, Ostry & Rose 2018).
 */
class GovernmentPolicyTest extends TestCase
{
    private MacroEngine $engine;
    private ReflectionMethod $enactPolicy;

    protected function setUp(): void
    {
        // The policy step reads and writes MacroState only, so no collaborators need to be wired up.
        $this->engine = (new ReflectionClass(MacroEngine::class))->newInstanceWithoutConstructor();
        $this->enactPolicy = new ReflectionMethod(MacroEngine::class, 'enactPolicy');
    }

    public function testTheLeversAndThePulseGoIntoForceAsHandedOver(): void
    {
        $state = new MacroState();

        $this->enact($state, tariff: 0.0, corporateTax: -0.02, laborGrowth: 0.007, leniency: 0.6, pulse: 0.4);

        $this->assertSame(-0.02, $state->corporateTaxPolicyShift);
        $this->assertSame(0.007, $state->laborForceGrowthRate);
        $this->assertSame(0.6, $state->mergerReviewLeniency);
        $this->assertSame(0.4, $state->electionPulse);
        $this->assertSame(0.0, $state->tfpShockLevel, 'No tariff moved, so no productivity did.');
    }

    public function testATariffCostsProductivityAndItsRepealGivesItBack(): void
    {
        $state = new MacroState();
        $index = $state->totalFactorProductivityIndex;

        $this->enact($state, tariff: 0.1);
        $loss = -MacroEngine::TARIFF_OUTPUT_LOSS * 0.1;
        $this->assertSame(0.1, $state->importTariffRate);
        $this->assertEqualsWithDelta($loss, $state->tfpShockLevel, 1e-15, 'The level potential absorbs falls by the output loss,');
        $this->assertEqualsWithDelta($index * exp($loss), $state->totalFactorProductivityIndex, 1e-9, 'and productivity with it.');

        $this->enact($state, tariff: 0.1);
        $this->assertEqualsWithDelta($loss, $state->tfpShockLevel, 1e-15, 'A tariff already in force costs nothing more.');

        $this->enact($state, tariff: 0.0);
        $this->assertEqualsWithDelta(0.0, $state->tfpShockLevel, 1e-15, 'Repeal gives it back.');
        $this->assertEqualsWithDelta($index, $state->totalFactorProductivityIndex, 1e-9);
    }

    private function enact(MacroState $state, float $tariff, float $corporateTax = 0.0, float $laborGrowth = MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE, float $leniency = 0.0, float $pulse = 0.0): void
    {
        $this->enactPolicy->invokeArgs($this->engine, [$state, new GovernmentPolicyDTO(
            corporateTaxPolicyShift: $corporateTax,
            importTariffRate: $tariff,
            laborForceGrowthRate: $laborGrowth,
            mergerReviewLeniency: $leniency,
            electionPulse: $pulse,
        )]);
    }
}
