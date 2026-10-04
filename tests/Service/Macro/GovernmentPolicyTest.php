<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro;

use App\DTO\GovernmentPolicyDTO;
use App\DTO\MacroStateDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\LaborMarketSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Covers what the economy does with the policy the government hands it: the levers go into force as handed over, a
 * tariff's change costs productivity (Furceri, Hannan, Ostry & Rose 2018), and the Monetary Authority giving ground to
 * the cabinet lowers the rate and lifts expectations.
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

    /** The Authority's concession goes into force as handed over, and none is in force before the Authority has formed. */
    public function testTheConcessionGoesIntoForceAsHandedOver(): void
    {
        $state = new MacroState();
        $this->enactPolicyDto($state, self::policy(1.0));
        $this->assertSame(1.0, $state->authorityConcession);
        $this->enactPolicyDto($state, self::policy(null));
        $this->assertSame(0.0, $state->authorityConcession);
    }

    /**
     * Two economies on the same draws, one whose Authority gives ground for a quarter: it ends the quarter with a lower
     * policy rate, the public expecting the Authority to tolerate more inflation, and breakevens up with it.
     */
    public function testAQuarterOfGivingGroundLowersTheRateAndLiftsExpectations(): void
    {
        $quarter = static function (?float $concession): MacroStateDTO {
            mt_srand(11);
            $math = new MathUtility();
            $engine = new MacroEngine($math, self::inMemoryRedis(), new MacroSnapshotRecorder(), new MonetaryPolicySubsystem($math), new LaborMarketSubsystem(),
                new MacroAggregateSubsystem($math), new CommodityLogisticsSubsystem($math), new AssetMarketSubsystem($math), new CreditFiscalSubsystem($math));
            $macro = null;
            for ($tick = 0; $tick < 63; ++$tick) {
                $macro = $engine->updateMacroState(1.0 / 252.0, policy: self::policy($concession));
            }

            return $macro;
        };
        $firm = $quarter(0.0);
        $giving = $quarter(1.0);

        $this->assertSame(0.0, $firm->inflationAnchorDrift);
        $this->assertEqualsWithDelta(MonetaryPolicySubsystem::PRESSURE_ANCHOR_DRIFT_RATE * 0.25, $giving->inflationAnchorDrift, 0.001);
        $this->assertGreaterThan($firm->tipsBreakeven + (0.8 * $giving->inflationAnchorDrift), $giving->tipsBreakeven);
        $this->assertLessThan($firm->policyRate - 0.003, $giving->policyRate);
    }

    private static function policy(?float $concession): GovernmentPolicyDTO
    {
        return new GovernmentPolicyDTO(
            corporateTaxPolicyShift: 0.0,
            importTariffRate: 0.0,
            laborForceGrowthRate: MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE,
            mergerReviewLeniency: 0.0,
            greenBeltStringency: 0.0,
            carbonPrice: 0.0,
            extractionStringency: 0.0,
            stampDutyRate: FinancialConstants::STAMP_DUTY_RATE,
            bankLevyRate: 0.0,
            electionPulse: 0.0,
            authorityConcession: $concession,
        );
    }

    private function enactPolicyDto(MacroState $state, GovernmentPolicyDTO $policy): void
    {
        $this->enactPolicy->invokeArgs($this->engine, [$state, $policy]);
    }

    /** The bootstrap's Redis stand-in forgets everything; the engine needs its state back each tick. */
    private static function inMemoryRedis(): \Redis
    {
        return new class extends \Redis {
            /** @var array<string, string> */
            private array $store = [];

            public function get(mixed $key): mixed
            {
                return $this->store[(string) $key] ?? false;
            }

            public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool
            {
                $this->store[$key] = (string) $value;

                return true;
            }
        };
    }

    private function enact(MacroState $state, float $tariff, float $corporateTax = 0.0, float $laborGrowth = MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE, float $leniency = 0.0, float $pulse = 0.0): void
    {
        $this->enactPolicy->invokeArgs($this->engine, [$state, new GovernmentPolicyDTO(
            corporateTaxPolicyShift: $corporateTax,
            importTariffRate: $tariff,
            laborForceGrowthRate: $laborGrowth,
            mergerReviewLeniency: $leniency,
            greenBeltStringency: 0.0,
            carbonPrice: 0.0,
            extractionStringency: 0.0,
            stampDutyRate: FinancialConstants::STAMP_DUTY_RATE,
            bankLevyRate: 0.0,
            electionPulse: $pulse,
        )]);
    }
}
