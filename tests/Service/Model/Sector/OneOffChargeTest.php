<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A one-off charge (a fine, a recall, a clean-up, a write-down, a reach-forward loss) is a special item: in EBIT, kept
 * out of the run rate, the Street figure and the variable cost ratio. Booked in the cost ratio, one fine was carried
 * into the run rate and the trailing return as though it would recur, and valued that way.
 */
final class OneOffChargeTest extends TestCase
{
    /** @return iterable<string, array{0: class-string, 1: float, 2: float}> Model, every draw, the uniform draw. */
    public static function events(): iterable
    {
        yield 'investment bank fine' => [Sector\InvestmentBankBusinessModel::class, 0.0, 0.0];
        yield 'tech antitrust fine' => [Sector\TechBusinessModel::class, -3.5, 1.0];
        yield 'restaurant food scandal' => [Sector\RestaurantBusinessModel::class, -2.4, 1.0];
        yield 'auto recall' => [Sector\AutoManufacturerBusinessModel::class, -2.3, 1.0];
        yield 'mine disaster' => [Sector\MiningBusinessModel::class, -2.8, 1.0];
        yield 'oil field blowout' => [Sector\OilGasProducerBusinessModel::class, -2.8, 1.0];
        yield 'refinery outage' => [Sector\RefiningBusinessModel::class, -2.8, 1.0];
        yield 'landfill clean-up' => [Sector\WasteManagementBusinessModel::class, -2.7, 1.0];
        yield 'grid failure' => [Sector\UtilityBusinessModel::class, -2.7, 1.0];
        yield 'security breach' => [Sector\SecurityProtectionBusinessModel::class, -2.7, 1.0];
        yield 'billing clawback' => [Sector\MedicalCareFacilityBusinessModel::class, -2.4, 1.0];
        yield 'conglomerate write-down' => [Sector\ConglomerateBusinessModel::class, -2.5, 1.0];
        yield 'investment company write-down' => [Sector\InvestmentCompanyBusinessModel::class, -2.5, 1.0];
        yield 'merchant house write-down' => [Sector\MerchantHouseBusinessModel::class, -2.5, 1.0];
        yield 'construction overrun' => [Sector\ConstructionBusinessModel::class, -2.2, 1.0];
        yield 'defence programme failure' => [Sector\DefenseContractorBusinessModel::class, -2.7, 1.0];
    }

    #[DataProvider('events')]
    public function testTheChargeIsASpecialItemAndNotACost(string $model, float $z, float $uniform): void
    {
        $quiet = $this->report($model, 0.0, 1.0);
        $event = $this->report($model, $z, $uniform);

        $this->assertSame(0.0, $quiet->specialCharge, 'No event, no charge.');
        $this->assertNotNull($event->eventType);
        $this->assertGreaterThan(0.0, $event->specialCharge, 'The charge reaches the special-item channel.');
    }

    private function report(string $model, float $z, float $uniform): \App\DTO\ActualFinancialsDTO
    {
        $math = $this->createStub(MathUtility::class);
        $math->method('generatePersistentZ')->willReturn($z);
        $math->method('generateStandardNormal')->willReturn($z);
        $math->method('generateUniform')->willReturn($uniform);

        $stock = (new Stock())->setTicker('ONEOFF')->setBeta('1.0')->setTotalEquity('500000000');

        return (new $model())->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.0, new MacroStateDTO(), $math);
    }
}
