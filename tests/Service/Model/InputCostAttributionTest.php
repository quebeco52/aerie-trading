<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\AutoManufacturerBusinessModel;
use App\Service\Model\StreamContext;
use PHPUnit\Framework\TestCase;

/**
 * The input-cost drag split by input market (StandardOperatingPhysicsTrait::attributeInputCostDrag). The lags are
 * linear and shared, so each input's own lag state is an exact part of the basket's: the parts sum to the drag the
 * quarter booked, one moving input owns all of it, and state from before the split fades as "earlier".
 */
final class InputCostAttributionTest extends TestCase
{
    /** Auto assembly buys metals, imported parts and labour (InputOutputExposures::AUTO_MANUFACTURER). */
    private AutoManufacturerBusinessModel $model;

    protected function setUp(): void
    {
        $model = Sectors::strategyFor('Auto Manufacturers');
        $this->assertInstanceOf(AutoManufacturerBusinessModel::class, $model);
        $this->model = $model;
    }

    public function testThePartsSumToTheBookedDragEveryQuarter(): void
    {
        $state = [];
        foreach ($this->path() as [$metals, $wageGap]) {
            [$drag, $state] = $this->quarter($state, new MacroStateDTO(industrialMetalsIndexEma: $metals, realWageGap: $wageGap));
            $parts = $this->model->attributeInputCostDrag($state);

            $this->assertEqualsWithDelta($drag, array_sum($parts), 1e-12);
            $this->assertEqualsWithDelta(0.0, $parts['earlier'], 1e-12, 'a firm tracked from its first quarter has no earlier state');
            $this->assertSame(['metals', 'imports', 'labor', 'earlier'], array_keys($parts));
        }
    }

    public function testOneMovingInputOwnsTheWholeDrag(): void
    {
        $state = [];
        foreach ([130.0, 140.0, 120.0, 100.0] as $metals) {
            [$drag, $state] = $this->quarter($state, new MacroStateDTO(industrialMetalsIndexEma: $metals));
            $parts = $this->model->attributeInputCostDrag($state);

            $this->assertEqualsWithDelta($drag, $parts['metals'], 1e-12);
            $this->assertEqualsWithDelta(0.0, $parts['labor'], 1e-12);
        }
        $this->assertGreaterThan(0.0, $parts['metals'], 'metals back at normal still cost the firm while recovery lags');
    }

    /**
     * A firm whose basket state predates the split carries it as "earlier", which fades over the lag horizon. Not
     * monotonically: recovery can still be catching up with the old cost level, so the gap can hump before it decays.
     */
    public function testStateFromBeforeTheSplitFadesAsEarlier(): void
    {
        $state = [
            FinancialConstants::STATE_INPUT_COST_LEVEL => 0.05,
            FinancialConstants::STATE_INPUT_COST_RECOVERY => 0.01,
            FinancialConstants::STATE_INPUT_COST_DRAG_BASE => 0.4,
        ];
        $largest = 0.0;
        for ($quarter = 0; $quarter < 16; $quarter++) {
            [$drag, $state] = $this->quarter($state, new MacroStateDTO());
            $parts = $this->model->attributeInputCostDrag($state);

            $this->assertEqualsWithDelta($drag, array_sum($parts), 1e-12);
            $this->assertEqualsWithDelta($drag, $parts['earlier'], 1e-12, 'with every input at normal, all of it is earlier state');
            $largest = max($largest, abs($parts['earlier']));
        }
        $this->assertGreaterThan(0.0, $largest);
        $this->assertLessThan(0.05 * $largest, abs($parts['earlier']), 'four years on, the earlier state has all but gone');
    }

    /** Past the drag bound the parts are scaled alike, so they still sum to what was booked. */
    public function testThePartsRespectTheDragBound(): void
    {
        $parts = $this->model->attributeInputCostDrag([
            FinancialConstants::STATE_INPUT_COST_LEVEL => 2.0,
            FinancialConstants::STATE_INPUT_COST_RECOVERY => 0.0,
            FinancialConstants::STATE_INPUT_COST_DRAG_BASE => 0.5,
            FinancialConstants::STATE_INPUT_COST_LEVEL_PREFIX . 'metals' => 1.5,
            FinancialConstants::STATE_INPUT_COST_LEVEL_PREFIX . 'labor' => 0.5,
        ]);

        $this->assertEqualsWithDelta(0.5, array_sum($parts), 1e-12);
        $this->assertEqualsWithDelta(3.0, $parts['metals'] / $parts['labor'], 1e-12);
    }

    public function testAModelThatBooksNoDragHasNoParts(): void
    {
        $this->assertSame([], $this->model->attributeInputCostDrag([]));
    }

    // --- Fixtures ---

    /** @return list<array{0: float, 1: float}> Metals index and real wage gap, quarter by quarter. */
    private function path(): array
    {
        return [[100.0, 0.0], [135.0, -0.02], [150.0, 0.01], [110.0, 0.03], [90.0, 0.0], [125.0, -0.04], [100.0, 0.02], [140.0, 0.0]];
    }

    /**
     * One quarter of the model's input-cost drag on a fresh stream context over the last quarter's state.
     *
     * @param array<string, float> $state
     * @return array{0: float, 1: array<string, float>}
     */
    private function quarter(array $state, MacroStateDTO $macro): array
    {
        $streams = new StreamContext($state, new MathUtility());
        $resolve = \Closure::bind(
            fn (Stock $stock, MacroStateDTO $macro, StreamContext $streams): float => $this->resolveInputCostDrag($stock, $macro, $streams, 0.6, 0.4),
            $this->model,
            $this->model::class
        );
        $drag = $resolve(new Stock(), $macro, $streams);

        return [$drag, $streams->getStreamZ()];
    }
}
