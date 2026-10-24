<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Model\Sector;
use App\Service\Model\Strategy\OperatingStrategyInterface;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Supply-chain pressure is an index in its own standard deviations about normal, and the average year's expediting
 * and spread are already in each firm's cost base. A term read only above normal charged that average a second time;
 * read both ways, a slack chain gives back what a tight one costs.
 */
final class SupplyChainPressureTwoSidedTest extends TestCase
{
    /** @return iterable<string, array{class-string<OperatingStrategyInterface>}> */
    public static function models(): iterable
    {
        yield 'heavy machinery' => [Sector\HeavyManufacturingBusinessModel::class];
        yield 'specialty machinery' => [Sector\SpecialtyIndustrialMachineryBusinessModel::class];
        yield 'telecom equipment' => [Sector\CommunicationEquipmentBusinessModel::class];
        yield 'computer hardware' => [Sector\ComputerHardwareBusinessModel::class];
        yield 'autos' => [Sector\AutoManufacturerBusinessModel::class];
        yield 'merchant house' => [Sector\MerchantHouseBusinessModel::class];
    }

    /** @param class-string<OperatingStrategyInterface> $model */
    #[DataProvider('models')]
    public function testASlackChainGivesBackWhatATightOneMoves(string $model): void
    {
        $normal = $this->costRatio($model, 0.0);
        $tight = $this->costRatio($model, 1.0) - $normal;
        $slack = $this->costRatio($model, -1.0) - $normal;

        // A maker pays for a tight chain; a merchant that owns berths and bonded warehouses earns the dislocation.
        $this->assertNotEqualsWithDelta(0.0, $tight, 1e-6, 'A tight chain moves the cost ratio.');
        $this->assertEqualsWithDelta(-$tight, $slack, abs($tight) * 1e-6, 'A slack one moves it back by as much.');
    }

    /** @param class-string<OperatingStrategyInterface> $model */
    private function costRatio(string $model, float $pressure): float
    {
        $math = new ScriptedDraws(generatePersistentZ: 0.0, generateUniform: 0.5);

        $stock = (new Stock())->setTicker('CHAIN')->setBeta('1.0')->setTotalEquity('500000000');
        $result = (new $model())->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.0, new MacroStateDTO(supplyChainPressureIndexEma: $pressure), $math);

        return $result->actualVariableCosts / $result->actualRevenue;
    }
}
