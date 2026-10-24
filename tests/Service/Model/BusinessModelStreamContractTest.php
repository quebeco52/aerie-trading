<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Strategy\OperatingStrategyInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class BusinessModelStreamContractTest extends TestCase
{
    /** The draw streams every contract runs on; each is its own case, so a failure names its seed. */
    private const SEEDS = [1, 2, 3];

    private MacroStateDTO $macro;

    protected function setUp(): void
    {
        $this->macro = new MacroStateDTO();
    }

    /**
     * @return array<string, array{class-string<OperatingStrategyInterface>}>
     */
    public static function businessModelProvider(): array
    {
        $dir = dirname(__DIR__, 3) . '/src/Service/Model/Sector';
        $files = glob($dir . '/*BusinessModel.php');
        self::assertNotFalse($files, "Could not list the sector models in {$dir}.");
        $models = [];

        foreach ($files as $file) {
            $className = 'App\\Service\\Model\\Sector\\' . basename($file, '.php');
            self::assertTrue(is_subclass_of($className, OperatingStrategyInterface::class), "{$className} has no operating strategy.");

            $ref = new ReflectionClass($className);
            if ($ref->isAbstract() || $ref->isInterface()) {
                continue;
            }

            $models[basename($file, '.php')] = [$className];
        }

        return $models;
    }

    /**
     * @return iterable<string, array{class-string<OperatingStrategyInterface>, int}>
     */
    public static function businessModelAndSeedProvider(): iterable
    {
        foreach (self::businessModelProvider() as $name => [$className]) {
            foreach (self::SEEDS as $seed) {
                yield "{$name} seed {$seed}" => [$className, $seed];
            }
        }
    }

    /** @param class-string<OperatingStrategyInterface> $modelClass */
    #[DataProvider('businessModelAndSeedProvider')]
    public function testStreamKeyConsistencyAndPersistence(string $modelClass, int $seed): void
    {
        $model = new $modelClass();
        $draws = MathUtility::ownStream($seed);

        $stock = new Stock();
        $stock->setTicker('TEST');
        $stock->setName('Test Corp');
        $stock->setBeta('1.0');
        $stock->setTotalEquity('1000000000');
        $stock->setWholesaleDebt('500000000');
        $stock->setFixedCostRatio(0.2);
        $stock->setCustomerDeposits('0');

        // Quarter 1: Initial invocation without prior momentum
        $stock->setEarningsMomentumZ(null);
        $q1 = $model->computeActualFinancials($stock, 100000000.0, 0.40, 20000000.0, 0.05, $this->macro, $draws);
        $z1 = $q1->streamZ;

        $this->assertNotEmpty($z1, "Model {$modelClass} must emit at least one stream Z-score");

        // Quarter 2: Set previous momentum and verify seamless inheritance
        $mockZ = [];
        foreach ($z1 as $k => $v) {
            $mockZ[$k] = 5.0;
        }
        $stock->setEarningsMomentumZ($mockZ);

        $q2 = $model->computeActualFinancials($stock, 100000000.0, 0.40, 20000000.0, 0.05, $this->macro, $draws);
        $z2 = $q2->streamZ;

        // A launch adds a cohort to a pipeline model's state, so launch-cohort keys may grow between quarters.
        $keys = static fn(array $z): array => array_values(array_filter(array_keys($z), static fn(string $k): bool => !str_starts_with($k, 'state:cohort:')));
        $this->assertSame(
            $keys($z1),
            $keys($z2),
            "Stream keys in {$modelClass} must be identical between quarter 1 and quarter 2 (no key mismatches)",
        );

        foreach ($z2 as $key => $val) {
            $this->assertIsFloat($val, "Stream Z for {$key} in {$modelClass} must be float");
            $this->assertTrue(is_finite($val), "Stream Z for {$key} in {$modelClass} must be finite");
        }
    }

    /** @param class-string<OperatingStrategyInterface> $modelClass */
    #[DataProvider('businessModelAndSeedProvider')]
    public function testStreamStationarityAcrossHorizons(string $modelClass, int $seed): void
    {
        $model = new $modelClass();
        $draws = MathUtility::ownStream($seed);

        $stock = new Stock();
        $stock->setTicker('TEST');
        $stock->setName('Test Corp');
        $stock->setBeta('1.0');
        $stock->setTotalEquity('1000000000');
        $stock->setWholesaleDebt('500000000');
        $stock->setFixedCostRatio(0.2);
        $stock->setCustomerDeposits('0');

        $currentZ = null;
        for ($quarter = 1; $quarter <= 50; $quarter++) {
            $stock->setEarningsMomentumZ($currentZ);
            $result = $model->computeActualFinancials($stock, 100000000.0, 0.40, 20000000.0, 0.05, $this->macro, $draws);
            $currentZ = $result->streamZ;

            foreach ($currentZ as $key => $val) {
                if (str_starts_with((string) $key, 'state:')) {
                    // Structural state (exclusivity clocks, franchise indices) is carried in the same
                    // map but is not a Z-score, so it is bounded by its own model rails, not by N(0,1).
                    $this->assertTrue(
                        is_finite($val),
                        "Structural state {$key} in {$modelClass} diverged on quarter {$quarter}: {$val}",
                    );
                    continue;
                }

                $this->assertLessThan(
                    8.0,
                    abs($val),
                    "Stream Z for {$key} in {$modelClass} exploded beyond stationary bounds on quarter {$quarter}: {$val}",
                );
            }
        }
    }
}
