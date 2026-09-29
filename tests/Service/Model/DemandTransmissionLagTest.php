<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\MacroFieldRegistry;
use App\DTO\MacroStateDTO;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\LuxuryBusinessModel;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use App\Service\Model\Strategy\OperatingStrategyInterface;
use App\Tests\Support\Model\ConfiguredStandardModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Demand does not reach every business at the same speed. A restaurant feels a recession the week it
 * starts; a builder is still working through a backlog ordered into a different economy, and only meets
 * the downturn when the next round of budgets is set. The delay is the economy's, so the macro publishes
 * the gap at each delay a model can declare and the model reads the one it declares.
 */
final class DemandTransmissionLagTest extends TestCase
{
    private const BOOM = 0.04;
    private const SLUMP = -0.04;

    /** A state that has sat in a boom long enough for every order book to reflect it, and has just turned into a slump. */
    private function turnIntoSlump(): MacroState
    {
        $state = new MacroState();
        $state->outputGap = self::SLUMP;
        $state->outputGapEma = self::SLUMP;
        foreach (array_keys(MacroAggregateSubsystem::DEMAND_TRANSMISSION_LAGS) as $field) {
            $state->$field = self::BOOM;
        }

        return $state;
    }

    /**
     * @return array<string, float> Lag field => its reading after holding the slump for the given time.
     */
    private function holdSlump(float $years, int $ticksPerYear): array
    {
        $aggregate = new MacroAggregateSubsystem(new MathUtility());
        $state = $this->turnIntoSlump();
        $dt = 1.0 / $ticksPerYear;
        for ($tick = 0, $ticks = (int) round($years * $ticksPerYear); $tick < $ticks; $tick++) {
            $aggregate->updateExponentialMovingAverages($state, $dt);
        }

        $readings = [];
        foreach (array_keys(MacroAggregateSubsystem::DEMAND_TRANSMISSION_LAGS) as $field) {
            $readings[$field] = $state->$field;
        }

        return $readings;
    }

    /** A long-lag book meets a downturn gradually, and the longer the lag the further behind it runs. */
    public function testLongerLagsTrailTheCycleFurtherIntoADownturn(): void
    {
        $readings = $this->holdSlump(0.75, 52);

        $previous = self::SLUMP;
        foreach ($readings as $field => $reading) {
            $this->assertGreaterThan($previous, $reading, "{$field} does not trail the shorter lags.");
            $this->assertLessThan(self::BOOM, $reading, "{$field} has not moved toward the slump at all.");
            $previous = $reading;
        }
    }

    /** Given enough time at one level, every lag arrives: it delays the cycle, it does not dampen it. */
    public function testEveryLagConvergesRatherThanPermanentlyDamping(): void
    {
        foreach ($this->holdSlump(15.0, 12) as $field => $reading) {
            $this->assertEqualsWithDelta(self::SLUMP, $reading, 1e-4, "{$field} never delivers the permanent shift in full.");
        }
    }

    /** An exact exponential lag: a quarter of weekly ticks reaches where one quarterly tick does. */
    public function testTheLagDoesNotDependOnTheTickRate(): void
    {
        $quarterly = $this->holdSlump(1.0, 4);
        $daily = $this->holdSlump(1.0, 252);

        foreach ($quarterly as $field => $reading) {
            $this->assertEqualsWithDelta($reading, $daily[$field], 1e-12, "{$field} depends on the tick rate.");
        }
    }

    /** A spot business reads the cycle as it is. */
    public function testZeroLagSectorTracksTheCycleContemporaneously(): void
    {
        $model = new LuxuryBusinessModel();

        $this->assertSame(0.0, $model->getDemandLagYears(), 'Luxury demand is immediate by declaration.');
        $this->assertSame(
            self::BOOM,
            $model->resolveLaggedOutputGap(new MacroStateDTO(outputGapEma: self::BOOM, outputGapLag12m: self::SLUMP)),
            'A zero-lag firm must see the macro series untouched.'
        );
    }

    /**
     * @return array<string, array{class-string<OperatingStrategyInterface>}>
     */
    public static function laggedModelProvider(): array
    {
        $models = ['ConfiguredStandardModel' => [ConfiguredStandardModel::class]];
        foreach (glob(dirname(__DIR__, 3) . '/src/Service/Model/Sector/*BusinessModel.php') ?: [] as $file) {
            /** @var class-string<OperatingStrategyInterface> $className */
            $className = 'App\\Service\\Model\\Sector\\' . basename($file, '.php');
            $ref = new ReflectionClass($className);
            if ($ref->isAbstract() || !$ref->hasConstant('DEMAND_LAG_YEARS')) {
                continue;
            }
            $models[basename($file, '.php')] = [$className];
        }

        return $models;
    }

    /**
     * A declared lag has to be one the macro publishes, and has to reach the demand path: the model reads the gap at
     * its own delay, not the gap as it stands.
     *
     * @param class-string<OperatingStrategyInterface> $modelClass
     */
    #[DataProvider('laggedModelProvider')]
    public function testDeclaredLagIsPublishedAndReadByTheModel(string $modelClass): void
    {
        $model = new $modelClass();
        $lagYears = $model->getDemandLagYears();
        $this->assertGreaterThan(0.0, $lagYears, $modelClass . ' declares a lag of zero, which is the default.');

        $field = MacroAggregateSubsystem::demandTransmissionLagField($lagYears);
        $macroState = MacroStateDTO::fromArray(array_replace(
            (new MacroStateDTO(outputGapEma: self::SLUMP))->toArray(),
            [MacroFieldRegistry::wireKeys()[$field] => self::BOOM]
        ));

        $this->assertSame(self::BOOM, $model->resolveLaggedOutputGap($macroState), $modelClass . ' declares a demand lag but does not read it.');
    }

    /** An off-ladder delay is a configuration error, not a silent fallback to some other lag. */
    public function testALagTheMacroDoesNotPublishIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MacroAggregateSubsystem::demandTransmissionLagField(0.60);
    }

    /** The generic model is contemporaneous, so the fallback stays the neutral case. */
    public function testStandardCorporateIsContemporaneousByDefault(): void
    {
        $this->assertSame(0.0, (new StandardCorporateBusinessModel())->getDemandLagYears());
    }
}
