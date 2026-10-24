<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Model\Sector;
use App\Service\Model\Strategy\OperatingStrategyInterface;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Realised inflation over expectations reaches a firm's nominal revenue once: through its plant, which the engine
 * carries at the realised price level (EarningsEngine). An escalator, cost-plus or list-price term on the same surprise
 * in the sector physics counted it a second time, as a level that vanished when the surprise closed.
 */
#[Group('unit')]
final class InflationSurpriseCountedOnceTest extends TestCase
{
    /** @return iterable<string, array{class-string<OperatingStrategyInterface>}> */
    public static function models(): iterable
    {
        yield 'reit' => [Sector\ReitBusinessModel::class];
        yield 'restaurant' => [Sector\RestaurantBusinessModel::class];
        yield 'waste' => [Sector\WasteManagementBusinessModel::class];
        yield 'defence' => [Sector\DefenseContractorBusinessModel::class];
        yield 'security' => [Sector\SecurityProtectionBusinessModel::class];
        yield 'hospital' => [Sector\MedicalCareFacilityBusinessModel::class];
        yield 'auto' => [Sector\AutoManufacturerBusinessModel::class];
    }

    /** @param class-string<OperatingStrategyInterface> $model */
    #[DataProvider('models')]
    public function testTheSectorPhysicsDoesNotReadTheInflationSurprise(string $model): void
    {
        $expected = $this->revenue($model, 0.02);

        $this->assertEqualsWithDelta($expected, $this->revenue($model, 0.05), 1e-6, 'Hot inflation adds no revenue in the physics.');
        $this->assertEqualsWithDelta($expected, $this->revenue($model, -0.01), 1e-6, 'Cold inflation takes none away.');
    }

    /** @param class-string<OperatingStrategyInterface> $model */
    private function revenue(string $model, float $inflation): float
    {
        $math = new ScriptedDraws(generatePersistentZ: 0.0, generateUniform: 1.0);

        $stock = (new Stock())->setTicker('PRICE')->setBeta('1.0')->setTotalEquity('500000000');
        $macro = new MacroStateDTO(inflationEma: $inflation, tipsBreakevenEma: 0.02);

        return (new $model())->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.0, $macro, $math)->actualRevenue;
    }
}
