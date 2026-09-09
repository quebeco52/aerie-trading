<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\ModelParam;
use App\Data\StockModelTuning;
use App\DTO\MacroStateDTO;
use App\DTO\ModelParameters;
use App\Entity\Stock;
use App\Service\Model\Sector\ConsumerStaplesBusinessModel;
use App\Service\Model\Sector\HeavyManufacturingBusinessModel;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Equity beta is a market statistic. Operating physics must read a declared operating cyclicality instead,
 * so a defensive name with a noisy beta does not inherit a cyclical cost base, and per-ticker tuning can
 * still make one machinery maker more cyclical than another.
 */
class OperatingCyclicalityTest extends TestCase
{
    public function testSectorConstantIsTheDefaultAndBetaIsIgnored(): void
    {
        $model = new HeavyManufacturingBusinessModel();
        $lowBeta = new Stock();
        $lowBeta->setTicker('LOWB');
        $lowBeta->setBeta('0.20');
        $highBeta = new Stock();
        $highBeta->setTicker('HIGHB');
        $highBeta->setBeta('2.50');

        $macro = new MacroStateDTO(outputGapEma: 0.03);

        $this->assertSame(HeavyManufacturingBusinessModel::OPERATING_CYCLICALITY, $model->getOperatingCyclicality($lowBeta));
        $this->assertEqualsWithDelta(
            $model->getMacroPhysics($lowBeta, $macro)['macro_demand_shift'],
            $model->getMacroPhysics($highBeta, $macro)['macro_demand_shift'],
            1e-12
        );
    }

    public function testTickerOverrideReplacesTheSectorConstant(): void
    {
        $model = new class extends HeavyManufacturingBusinessModel {
            protected function resolveModelParameters(Stock $stock, array $defaults = []): ModelParameters
            {
                return StockModelTuning::resolve($stock->getTicker(), [ModelParam::OperatingCyclicality->value => 0.55] + $defaults);
            }
        };
        $stock = new Stock();
        $stock->setTicker('TUNED');

        $this->assertEqualsWithDelta(0.55, $model->getOperatingCyclicality($stock), 1e-12);
    }

    public function testDefensiveSectorsDeclareLowerCyclicalityThanCyclicalOnes(): void
    {
        $this->assertLessThan(HeavyManufacturingBusinessModel::OPERATING_CYCLICALITY, ConsumerStaplesBusinessModel::OPERATING_CYCLICALITY);
        $this->assertSame(1.0, StandardCorporateBusinessModel::OPERATING_CYCLICALITY);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sectorModelFiles(): iterable
    {
        foreach (glob(dirname(__DIR__, 3) . '/src/Service/Model/Sector/*BusinessModel.php') ?: [] as $file) {
            yield basename($file) => [$file];
        }
    }

    #[DataProvider('sectorModelFiles')]
    public function testNoSectorModelReadsEquityBetaInOperatingPhysics(string $file): void
    {
        $this->assertStringNotContainsString('getBeta()', (string) file_get_contents($file), basename($file) . ' must declare OPERATING_CYCLICALITY instead of reading equity beta');
    }
}
