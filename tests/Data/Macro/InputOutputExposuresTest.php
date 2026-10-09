<?php

declare(strict_types=1);

namespace App\Tests\Data\Macro;

use App\Data\Macro\InputOutputExposures;
use App\Service\Model\Sector\ChemicalBusinessModel;
use App\Service\Model\Sector\RefiningBusinessModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The input-cost baskets are measured from the BEA input-output accounts (var/harness/io_weights.py writes
 * InputOutputExposures). These claims come straight from the tables, so a regeneration that breaks one has changed
 * the economics, not the formatting.
 */
final class InputOutputExposuresTest extends TestCase
{
    private const COMMODITY_CHANNELS = ['energy', 'gas', 'electricity', 'metals', 'agri', 'freight'];

    /**
     * @return array<string, array{string, array<string, float>}>
     */
    public static function measuredModelProvider(): array
    {
        $cases = [];
        foreach (glob(__DIR__ . '/../../../src/Service/Model/Sector/*BusinessModel.php') ?: [] as $file) {
            $class = 'App\\Service\\Model\\Sector\\' . basename($file, '.php');
            $source = (string) file_get_contents($file);
            if (str_contains($source, 'INPUT_COST_EXPOSURES = InputOutputExposures::')) {
                $cases[basename($file, '.php')] = [$class, constant($class . '::INPUT_COST_EXPOSURES')];
            }
        }

        return $cases;
    }

    public function testEveryHandSetBasketButTheRefinersIsNowMeasured(): void
    {
        $handSet = [];
        foreach (glob(__DIR__ . '/../../../src/Service/Model/Sector/*BusinessModel.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match('/public const INPUT_COST_EXPOSURES = \[/', $source) === 1) {
                $handSet[] = basename($file, '.php');
            }
        }

        // Refining keeps its opex basket: crude and refinery fuel gas share one BEA row, and its basket sits on the
        // non-feedstock cost base the table does not isolate.
        $this->assertSame(['RefiningBusinessModel'], $handSet);
        $this->assertCount(35, self::measuredModelProvider());
        $this->assertArrayHasKey('gas', RefiningBusinessModel::INPUT_COST_EXPOSURES);
    }

    /**
     * @param array<string, float> $exposures
     */
    #[DataProvider('measuredModelProvider')]
    public function testMeasuredWeightsAreMaterialSharesOfTheCostBase(string $class, array $exposures): void
    {
        $commodityTotal = 0.0;
        foreach ($exposures as $channel => $share) {
            $this->assertGreaterThan(0.0, $share, "{$class} {$channel}");
            $this->assertLessThan(1.0, $share, "{$class} {$channel}");
            if (in_array($channel, self::COMMODITY_CHANNELS, true)) {
                $this->assertGreaterThanOrEqual(InputOutputExposures::MATERIALITY_FLOOR, $share, "{$class} {$channel} is under the materiality floor");
                $commodityTotal += $share;
            }
        }

        $this->assertArrayNotHasKey('ppi', $exposures, 'wholesale-goods inflation is retired: its commodity content is measured directly');
        $this->assertLessThan(0.5, $commodityTotal, "{$class}: tracked commodities are part of the cost base, never most of it");
    }

    public function testFuelIsMaterialToCarriersAndNotToChipsOrSoftware(): void
    {
        foreach (['SHIPPING', 'RAILROAD', 'LOGISTICS'] as $carrier) {
            $this->assertGreaterThan(0.05, $this->share($carrier, 'energy'), $carrier);
        }
        foreach (['SEMICONDUCTOR', 'TECH', 'LAW_FIRM'] as $office) {
            $this->assertSame(0.0, $this->share($office, 'energy'), $office);
        }
    }

    public function testMetalsLeadForMillsAndMachineryAndAreAbsentForServices(): void
    {
        $metals = [];
        foreach (array_keys((new \ReflectionClass(InputOutputExposures::class))->getConstants()) as $name) {
            $value = constant(InputOutputExposures::class . '::' . $name);
            if (is_array($value)) {
                $metals[$name] = (float) ($value['metals'] ?? 0.0);
            }
        }
        arsort($metals);

        // Scrap and ore make steel the heaviest buyer; HVAC, machinery and assemblers follow.
        $this->assertSame('STEEL_MANUFACTURING', array_key_first($metals));
        foreach (['HEAVY_MANUFACTURING', 'SPECIALTY_INDUSTRIAL_MACHINERY', 'AUTO_MANUFACTURER'] as $metalBuyer) {
            $this->assertGreaterThan(0.10, $metals[$metalBuyer], $metalBuyer);
        }
        foreach (['TECH', 'LAW_FIRM', 'EDUCATION', 'MEDICAL_CARE_FACILITY'] as $service) {
            $this->assertSame(0.0, $metals[$service], $service);
        }
    }

    public function testFarmContentLeadsForFoodAndIsModestForRestaurants(): void
    {
        $this->assertGreaterThan($this->share('CHEMICAL', 'agri'), $this->share('CONSUMER_STAPLES', 'agri'));
        $this->assertGreaterThan($this->share('RESTAURANT', 'agri'), $this->share('CHEMICAL', 'agri'));
        // The USDA food dollar puts the farm share of food eaten away from home at ~4-5 cents.
        $this->assertEqualsWithDelta(0.04, $this->share('RESTAURANT', 'agri'), 0.02);
    }

    public function testGeneratorFuelComesFromEiaReceiptsAndFeedstockStaysInTheChemicalModel(): void
    {
        // 9,952 TBtu at $3.37 in 2017: 7.4% of electric power output, blended with the water system WADE runs.
        $this->assertGreaterThan(0.05, $this->share('UTILITY', 'gas'));
        $this->assertArrayNotHasKey('gas', ChemicalBusinessModel::INPUT_COST_EXPOSURES);
        $this->assertArrayNotHasKey('energy', ChemicalBusinessModel::INPUT_COST_EXPOSURES);
    }

    /**
     * @param array<string, float> $exposures
     */
    #[DataProvider('measuredModelProvider')]
    public function testLaborIsMeasuredForEveryModelAndPricesItsFixedBaseToo(string $class, array $exposures): void
    {
        $this->assertArrayHasKey('labor', $exposures, "{$class} has no measured payroll share");
        $this->assertGreaterThan(0.10, $exposures['labor'], $class);
        $this->assertLessThan(0.70, $exposures['labor'], $class);
        // One share per industry: the fixed-cost wage factor and severance read the same number the basket does.
        $model = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $this->assertSame($exposures['labor'], $model->getLaborCostShare(), $class);
    }

    public function testPayrollLeadsForPeopleBusinessesAndTrailsForProcessIndustries(): void
    {
        foreach (['LAW_FIRM', 'SECURITY_PROTECTION', 'EDUCATION', 'MEDICAL_CARE_FACILITY'] as $people) {
            foreach (['STEEL_MANUFACTURING', 'CHEMICAL', 'AUTO_MANUFACTURER', 'REIT'] as $process) {
                $this->assertGreaterThan($this->share($process, 'labor'), $this->share($people, 'labor'), "{$people} vs {$process}");
            }
        }
        // BEA 722110 + 722211: compensation is ~42% of what a restaurant spends to operate.
        $this->assertEqualsWithDelta(0.42, $this->share('RESTAURANT', 'labor'), 0.02);
    }

    public function testRetailPassThroughIsWhatBusinessesActuallyPay(): void
    {
        // Industrial tariffs barely follow wholesale power (cost-of-service rates, contracts); delivered gas mostly does.
        $this->assertLessThan(0.25, InputOutputExposures::RETAIL_POWER_PASS_THROUGH);
        $this->assertGreaterThan(0.5, InputOutputExposures::RETAIL_GAS_PASS_THROUGH);
        $this->assertLessThan(1.0, InputOutputExposures::RETAIL_GAS_PASS_THROUGH);
    }

    private function share(string $model, string $channel): float
    {
        return (float) (constant(InputOutputExposures::class . '::' . $model)[$channel] ?? 0.0);
    }
}
