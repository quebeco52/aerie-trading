<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\InsuranceBusinessModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A book's catastrophe layer is struck on a lattice of the district's quarterly losses
 * (CommodityLogisticsSubsystem::catastropheQuarterDistribution()). Run on the engine's own loss process, the cover
 * attaches as often as its return period says and pays on average what the layer expects, so its price is loaded on
 * the loss it actually covers.
 */
final class CatastropheLayerEngineTest extends TestCase
{
    private const QUARTERS = 20_000;
    private const TICKS_PER_QUARTER = 63;
    private const BURN_IN_QUARTERS = 40;

    /** @return iterable<string, array{float, float, float}> Threshold, scale and district load of a book's layer. */
    public static function layers(): iterable
    {
        yield 'primary' => [InsuranceBusinessModel::CATASTROPHE_Z_THRESHOLD, InsuranceBusinessModel::CATASTROPHE_LOSS_SCALAR, InsuranceBusinessModel::DISTRICT_CATASTROPHE_LOAD];
        yield 'reinsurer' => [-1.55, 0.20, 0.08];
    }

    #[DataProvider('layers')]
    public function testLayerMatchesTheEngineLossProcess(float $threshold, float $scale, float $load): void
    {
        $layer = InsuranceBusinessModel::catastropheLayer($threshold, $scale, $load);
        $district = new CommodityLogisticsSubsystem(MathUtility::ownStream(4242));
        $claims = MathUtility::ownStream(9191);
        $state = new MacroState();
        $dt = FinancialConstants::QUARTER_YEARS / self::TICKS_PER_QUARTER;

        $recoveries = [];
        $attached = 0;
        for ($quarter = 0; $quarter < self::QUARTERS + self::BURN_IN_QUARTERS; ++$quarter) {
            $burdenThen = $state->catastropheLossIndex;
            for ($tick = 0; $tick < self::TICKS_PER_QUARTER; ++$tick) {
                $district->calculateCatastropheLosses($state, $dt);
                $state->totalTime += $dt;
            }
            $ratio = CommodityLogisticsSubsystem::catastropheIncurredSince($state->catastropheLossIndex, $burdenThen, FinancialConstants::QUARTER_YEARS)
                / CommodityLogisticsSubsystem::expectedCatastropheIncurred($state->totalTime, FinancialConstants::QUARTER_YEARS);
            $gross = ($scale * max(0.0, $threshold - $claims->generateStandardNormal())) + ($load * ($ratio - 1.0));
            if ($quarter < self::BURN_IN_QUARTERS) {
                continue;
            }
            $recoveries[] = min($layer['limit'], max(0.0, $gross - $layer['retention']));
            $attached += $gross > $layer['retention'] ? 1 : 0;
        }

        $attachRate = $attached / self::QUARTERS;
        $target = 1.0 / (FinancialConstants::QUARTERS_PER_YEAR * InsuranceBusinessModel::CATASTROPHE_RETENTION_RETURN_YEARS);
        $this->assertEqualsWithDelta($target, $attachRate, 3.0 * sqrt($target * (1.0 - $target) / self::QUARTERS), 'attaches at its return period');

        $mean = array_sum($recoveries) / self::QUARTERS;
        $sd = sqrt(array_sum(array_map(static fn(float $recovery): float => ($recovery - $mean) ** 2, $recoveries)) / self::QUARTERS);
        $this->assertEqualsWithDelta($layer['expected_recovery'], $mean, 3.0 * $sd / sqrt(self::QUARTERS), 'pays what the layer expects');
    }
}
