<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector;
use App\Service\Model\Strategy\OperatingStrategyInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A contract escalator, a lending-standards gate or a counter-cyclical stream reads its driver both ways: what it pays
 * the firm when the driver runs one side of neutral it gives back when the driver runs the other. Read one-sided, a
 * driver that averages neutral still paid on average, revenue no one forecast.
 */
final class TwoSidedDriverTest extends TestCase
{
    /**
     * @return iterable<string, array{0: class-string<OperatingStrategyInterface>, 1: string, 2: array<string, float>, 3: array<string, float>, 4: array<string, float>, 5: bool}>
     *         Model, stream, driver above neutral, at neutral, below neutral, whether the response is linear.
     */
    public static function drivers(): iterable
    {
        $hot = ['inflationEma' => 0.03, 'tipsBreakevenEma' => 0.02];
        $expected = ['inflationEma' => 0.02, 'tipsBreakevenEma' => 0.02];
        $cold = ['inflationEma' => 0.01, 'tipsBreakevenEma' => 0.02];
        yield 'restaurant rent escalator' => [Sector\RestaurantBusinessModel::class, 'franchise_real_estate_leases', $hot, $expected, $cold, true];
        yield 'waste CPI escalator' => [Sector\WasteManagementBusinessModel::class, '', $hot, $expected, $cold, true];
        yield 'reit rent escalator' => [Sector\ReitBusinessModel::class, '', $hot, $expected, $cold, true];
        yield 'hospital insurance arbitrage' => [Sector\MedicalCareFacilityBusinessModel::class, '', $hot, $expected, $cold, true];
        yield 'security cost-plus' => [Sector\SecurityProtectionBusinessModel::class, '', $hot, $expected, $cold, true];
        yield 'defence cost-plus' => [Sector\DefenseContractorBusinessModel::class, '', $hot, $expected, $cold, true];
        yield 'hypercar list prices' => [Sector\AutoManufacturerBusinessModel::class, 'apex_luxury', $hot, $expected, $cold, true];

        $tight = ['sloosTighteningIndexEma' => 0.20];
        $neutral = ['sloosTighteningIndexEma' => 0.0];
        $loose = ['sloosTighteningIndexEma' => -0.20];
        yield 'construction orders' => [Sector\ConstructionBusinessModel::class, '', $tight, $neutral, $loose, true];
        yield 'private credit' => [Sector\ShadowBankBusinessModel::class, '', $tight, $neutral, $loose, true];
        yield 'card lending' => [Sector\CreditServicesBusinessModel::class, '', $tight, $neutral, $loose, true];
        yield 'buyout carry' => [Sector\PrivateEquityBusinessModel::class, '', $tight, $neutral, $loose, false];

        $boom = ['outputGapEma' => 0.02];
        $flat = ['outputGapEma' => 0.0];
        $slump = ['outputGapEma' => -0.02];
        yield 'law retainers' => [Sector\LawFirmBusinessModel::class, 'corporate_retainers', $boom, $flat, $slump, true];
        yield 'law restructuring' => [Sector\LawFirmBusinessModel::class, 'restructuring_advisory', $boom, $flat, $slump, true];
        yield 'degree enrolment' => [Sector\EducationBusinessModel::class, 'degree_tuition_enrollment', $boom, $flat, $slump, true];

        $rich = ['equityWealthRatio' => 1.20, 'equityWealthTrend' => 1.0];
        $usual = ['equityWealthRatio' => 1.0, 'equityWealthTrend' => 1.0];
        $poor = ['equityWealthRatio' => 0.80, 'equityWealthTrend' => 1.0];
        yield 'hypercar wealth effect' => [Sector\AutoManufacturerBusinessModel::class, 'apex_luxury', $rich, $usual, $poor, true];
    }

    /**
     * @param class-string<OperatingStrategyInterface> $model
     * @param array<string, float>                     $above
     * @param array<string, float>                     $neutral
     * @param array<string, float>                     $below
     */
    #[DataProvider('drivers')]
    public function testTheDriverPaysOneWayAndTakesBackTheOther(string $model, string $stream, array $above, array $neutral, array $below, bool $linear): void
    {
        $base = $this->revenue($model, $stream, $neutral);
        $up = $this->revenue($model, $stream, $above) - $base;
        $down = $this->revenue($model, $stream, $below) - $base;

        $this->assertNotEqualsWithDelta(0.0, $up, 1e-6, 'The driver moves revenue above neutral.');
        $this->assertNotEqualsWithDelta(0.0, $down, 1e-6, 'The driver moves revenue below neutral too.');
        $this->assertLessThan(0.0, $up * $down, 'The two sides move revenue opposite ways.');
        if ($linear) {
            $this->assertEqualsWithDelta(-$up, $down, abs($up) * 1e-6, 'Equal and opposite on a linear term.');
        }
    }

    /**
     * @param class-string<OperatingStrategyInterface> $model
     * @param array<string, float>                     $macro
     */
    private function revenue(string $model, string $stream, array $macro): float
    {
        $math = $this->createStub(MathUtility::class);
        $math->method('generatePersistentZ')->willReturn(0.0);
        $math->method('generateStandardNormal')->willReturn(0.0);
        $math->method('generateUniform')->willReturn(1.0);

        $stock = (new Stock())->setTicker('TWOSIDE')->setBeta('1.0')->setTotalEquity('500000000');
        $result = (new $model())->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.0, new MacroStateDTO(...$macro), $math);

        return $stream === '' ? $result->actualRevenue : $result->streamRevenue[$stream];
    }
}
