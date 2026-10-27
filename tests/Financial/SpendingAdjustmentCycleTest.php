<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\ConstructionBusinessModel;
use App\Service\Model\Sector\InternetRetailBusinessModel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The spending-adjustment term is a rate of change: over the cycle it adds nothing to a durable seller's demand on
 * average, whatever level the gap averages, so it shapes the cycle without moving trend revenue.
 */
#[Group('unit')]
final class SpendingAdjustmentCycleTest extends TestCase
{
    /** The level the gap wanders around, off zero so a term read on the level would show. */
    private const GAP_MEAN = -0.01;
    /** The gap's persistence a quarter and its spread, about the macro's own. */
    private const QUARTERLY_PERSISTENCE = 0.92;
    private const GAP_SD = 0.016;

    public function testTheTermAveragesZeroOverTheCycle(): void
    {
        $aggregate = MacroAggregateSubsystem::headless(new MathUtility());
        $draws = MathUtility::ownStream(20261027);
        $stock = (new Stock())->setTicker('XXXX');
        $models = ['spot' => new InternetRetailBusinessModel(), 'backlog' => new ConstructionBusinessModel()];

        $state = new MacroState();
        $state->outputGap = self::GAP_MEAN;
        $state->outputGapEma = self::GAP_MEAN;
        $state->outputGapLag3m = self::GAP_MEAN;
        $state->outputGapLag18m = self::GAP_MEAN;

        $ticksPerQuarter = 13;
        $dt = 0.25 / $ticksPerQuarter;
        $persistence = self::QUARTERLY_PERSISTENCE ** (1.0 / $ticksPerQuarter);
        $innovation = self::GAP_SD * sqrt(1.0 - ($persistence ** 2));
        $shifts = ['spot' => [], 'backlog' => []];
        for ($tick = 1; $tick <= 200 * 4 * $ticksPerQuarter; $tick++) {
            $state->outputGap = self::GAP_MEAN + ($persistence * ($state->outputGap - self::GAP_MEAN)) + ($innovation * $draws->generateStandardNormal());
            $aggregate->updateExponentialMovingAverages($state, $dt);
            if ($tick % $ticksPerQuarter === 0) {
                $macro = new MacroStateDTO(outputGapEma: $state->outputGapEma, outputGapLag3m: $state->outputGapLag3m, outputGapLag18m: $state->outputGapLag18m);
                foreach ($models as $name => $model) {
                    $shifts[$name][] = $model->resolveSpendingAdjustmentShift($stock, $macro);
                }
            }
        }

        foreach ($shifts as $name => $series) {
            $mean = array_sum($series) / count($series);
            $sd = sqrt(array_sum(array_map(static fn(float $shift): float => ($shift - $mean) ** 2, $series)) / count($series));
            $this->assertGreaterThan(0.0, $sd, $name);
            $this->assertLessThan(0.15 * $sd, abs($mean), "{$name}: the term moves trend revenue");
        }
    }
}
