<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class BusinessModelStreamContractTest extends TestCase
{
    private MacroStateDTO $macro;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->macro = new MacroStateDTO();
        $this->mathUtility = new MathUtility();
    }

    public static function businessModelProvider(): array
    {
        $dir = dirname(__DIR__, 3) . '/src/Service/Model';
        $files = glob($dir . '/*BusinessModel.php');
        $models = [];

        foreach ($files as $file) {
            $className = 'App\\Service\\Model\\' . basename($file, '.php');
            if (!class_exists($className)) {
                continue;
            }

            $ref = new ReflectionClass($className);
            if ($ref->isAbstract() || $ref->isTrait() || $ref->isInterface()) {
                continue;
            }

            $models[basename($file, '.php')] = [$className];
        }

        return $models;
    }

    #[DataProvider('businessModelProvider')]
    public function testStreamKeyConsistencyAndPersistence(string $modelClass): void
    {
        $model = new $modelClass();

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
        $q1 = $model->computeActualFinancials($stock, 100000000.0, 0.40, 20000000.0, 0.05, $this->macro, $this->mathUtility);
        $z1 = $q1->streamZ;

        $this->assertNotEmpty($z1, "Model {$modelClass} must emit at least one stream Z-score");

        // Quarter 2: Set previous momentum and verify seamless inheritance
        $mockZ = [];
        foreach ($z1 as $k => $v) {
            $mockZ[$k] = 5.0;
        }
        $stock->setEarningsMomentumZ($mockZ);

        $q2 = $model->computeActualFinancials($stock, 100000000.0, 0.40, 20000000.0, 0.05, $this->macro, $this->mathUtility);
        $z2 = $q2->streamZ;

        $this->assertSame(
            array_keys($z1),
            array_keys($z2),
            "Stream keys in {$modelClass} must be identical between quarter 1 and quarter 2 (no key mismatches)"
        );

        foreach ($z2 as $key => $val) {
            $this->assertIsFloat($val, "Stream Z for {$key} in {$modelClass} must be float");
            $this->assertTrue(is_finite($val), "Stream Z for {$key} in {$modelClass} must be finite");
        }
    }

    #[DataProvider('businessModelProvider')]
    public function testStreamStationarityAcrossHorizons(string $modelClass): void
    {
        $model = new $modelClass();

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
            $result = $model->computeActualFinancials($stock, 100000000.0, 0.40, 20000000.0, 0.05, $this->macro, $this->mathUtility);
            $currentZ = $result->streamZ;

            foreach ($currentZ as $key => $val) {
                $this->assertLessThan(
                    8.0,
                    abs($val),
                    "Stream Z for {$key} in {$modelClass} exploded beyond stationary bounds on quarter {$quarter}: {$val}"
                );
            }
        }
    }
}
