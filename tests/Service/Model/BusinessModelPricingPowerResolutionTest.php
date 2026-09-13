<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Model\Sector\RailroadBusinessModel;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Keeps the pricing-power index resolvable from exactly one place.
 *
 * Pricing power is the most consequential dial a sector model declares: it sets the inflation pass-through
 * elasticity (0.5 + p), the share of an input-cost move recovered in selling prices (p x 0.80) and the macro
 * demand sensitivity (0.5 + p). StandardOperatingPhysicsTrait::resolvePricingPower is the single funnel that
 * layers a ticker override over the sector constant over the median 0.5. Call sites that inline
 * resolveModelParameters() with a hardcoded 0.5 instead silently discard the sector constant, which is how
 * Railroad (0.80) and FinancialData (0.85) spent a release priced as median firms.
 */
final class BusinessModelPricingPowerResolutionTest extends TestCase
{
    /** A ticker carrying no StockModelTuning override, so resolution falls through to the sector constant. */
    private const UNTUNED_TICKER = 'ZZZZ';

    /**
     * @return array<string, array{class-string}>
     */
    public static function pricingPowerModelProvider(): array
    {
        $dir = dirname(__DIR__, 3) . '/src/Service/Model/Sector';
        $models = [];

        foreach (glob($dir . '/*BusinessModel.php') ?: [] as $file) {
            /** @var class-string $className */
            $className = 'App\\Service\\Model\\Sector\\' . basename($file, '.php');
            $ref = new ReflectionClass($className);
            if ($ref->isAbstract() || !$ref->hasConstant('PRICING_POWER_INDEX')) {
                continue;
            }
            $models[basename($file, '.php')] = [$className];
        }

        return $models;
    }

    /**
     * A declared PRICING_POWER_INDEX must survive resolution rather than being replaced by the median.
     */
    #[DataProvider('pricingPowerModelProvider')]
    public function testDeclaredPricingPowerReachesResolution(string $modelClass): void
    {
        $model = new $modelClass();
        $declared = (float) (new ReflectionClass($modelClass))->getConstant('PRICING_POWER_INDEX');

        $stock = new Stock();
        $stock->setTicker(self::UNTUNED_TICKER);

        $resolve = new ReflectionMethod($modelClass, 'resolvePricingPower');

        $this->assertSame(
            $declared,
            $resolve->invoke($model, $stock),
            sprintf('%s declares PRICING_POWER_INDEX = %.2f but resolves to a different value.', $modelClass, $declared)
        );
    }

    /**
     * The sector constant has to reach the macro demand path too, not just the pass-through path. Railroad
     * inherits StandardCorporate::getMacroPhysics wholesale, so it is the honest witness for that route.
     */
    public function testSectorPricingPowerScalesInheritedMacroDemandShift(): void
    {
        $model = new RailroadBusinessModel();

        $stock = new Stock();
        $stock->setTicker(self::UNTUNED_TICKER);

        $outputGap = 0.02;
        // Exchange rate at its 100.0 base leaves the FX term at zero, isolating the pricing-power multiplier.
        $macro = new MacroStateDTO(outputGapEma: $outputGap, exchangeRateIndexEma: 100.0);

        $beta = $model->getOperatingCyclicality($stock);
        $expected = $outputGap
            * (StandardCorporateBusinessModel::MIN_BETA_PRICING_POWER_FLOOR + RailroadBusinessModel::PRICING_POWER_INDEX)
            * $beta;

        $this->assertEqualsWithDelta(
            $expected,
            $model->getMacroPhysics($stock, $macro)['macro_demand_shift'],
            1e-12,
            'Railroad declares pricing power 0.80; the inherited macro demand shift must use 0.5 + 0.80, not the median 0.5 + 0.5.'
        );
    }

    /**
     * Source guard: nothing may re-introduce a call site that hardcodes the median default and so bypasses
     * the sector constant. Pricing power is resolved through resolvePricingPower() or not at all.
     */
    public function testNoModelHardcodesTheMedianPricingPowerDefault(): void
    {
        $offenders = [];

        foreach (glob(dirname(__DIR__, 3) . '/src/Service/Model/**/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match('/PricingPowerIndex->value\s*=>\s*0\.50?\b/', $source) === 1) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These files default PricingPowerIndex inline instead of calling resolvePricingPower(), which discards the sector PRICING_POWER_INDEX: '
                . implode(', ', $offenders)
        );
    }

    /** A ticker override still has to win over the sector constant. */
    public function testTickerOverrideStillBeatsTheSectorConstant(): void
    {
        $model = new RailroadBusinessModel();

        $stock = new Stock();
        $stock->setTicker(self::UNTUNED_TICKER);

        $resolve = new ReflectionMethod(RailroadBusinessModel::class, 'resolvePricingPower');

        $this->assertSame(RailroadBusinessModel::PRICING_POWER_INDEX, $resolve->invoke($model, $stock));
        $this->assertArrayNotHasKey(
            ModelParam::PricingPowerIndex->value,
            \App\Data\StockModelTuning::OVERRIDES[self::UNTUNED_TICKER] ?? [],
            'The fixture ticker must stay untuned for this test to prove sector-constant fallthrough.'
        );
    }
}
