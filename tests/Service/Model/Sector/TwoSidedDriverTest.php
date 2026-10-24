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
 * A lending-standards gate, a wealth effect or a counter-cyclical stream reads its driver both ways: what it pays
 * the firm when the driver runs one side of neutral it gives back when the driver runs the other. Read one-sided, a
 * driver that averages neutral still paid on average, revenue no one forecast.
 */
#[Group('unit')]
final class TwoSidedDriverTest extends TestCase
{
    /**
     * @return iterable<string, array{0: class-string<OperatingStrategyInterface>, 1: string, 2: array<string, float>, 3: array<string, float>, 4: array<string, float>, 5: bool}>
     *         Model, stream, driver above neutral, at neutral, below neutral, whether the response is linear.
     */
    public static function drivers(): iterable
    {
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
        yield 'security retainers' => [Sector\SecurityProtectionBusinessModel::class, 'corporate_retainers', $boom, $flat, $slump, true];

        $panic = ['marketVolatilityEma' => 0.25];
        $anchor = ['marketVolatilityEma' => 0.15];
        $lull = ['marketVolatilityEma' => 0.05];
        yield 'security fear premium' => [Sector\SecurityProtectionBusinessModel::class, 'expeditionary_ops', $panic, $anchor, $lull, true];
        $busy = ['marketVolatilityEma' => 0.20];
        $quiet = ['marketVolatilityEma' => 0.10];
        yield 'bank trading volatility' => [Sector\InvestmentBankBusinessModel::class, '', $busy, $anchor, $quiet, true];

        $stormy = ['catastropheLossIndexEma' => 1.5];
        $average = ['catastropheLossIndexEma' => 1.0];
        $calm = ['catastropheLossIndexEma' => 0.5];
        yield 'construction storm rebuild' => [Sector\ConstructionBusinessModel::class, '', $stormy, $average, $calm, true];
        yield 'reit storm vacancy' => [Sector\ReitBusinessModel::class, '', $stormy, $average, $calm, true];

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
        $math = new ScriptedDraws(generatePersistentZ: 0.0, generateUniform: 1.0);

        $stock = (new Stock())->setTicker('TWOSIDE')->setBeta('1.0')->setTotalEquity('500000000');
        $result = (new $model())->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.0, new MacroStateDTO(...$macro), $math);

        return $stream === '' ? $result->actualRevenue : $result->streamRevenue[$stream];
    }
}
