<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\ConsumerStaplesBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ConsumerStaplesBusinessModelTest extends TestCase
{
    private ConsumerStaplesBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new ConsumerStaplesBusinessModel();
    }

    private static function stock(string $ticker): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setBeta('0.8');

        return $stock;
    }

    /** Every stream and event draw at zero, so a comparison isolates the macro term under test. */
    private function calmDraws(): MathUtility
    {
        $mathUtility = $this->createStub(MathUtility::class);
        $mathUtility->method('generatePersistentZ')->willReturn(0.0);
        $mathUtility->method('generateStandardNormal')->willReturn(0.0);

        return $mathUtility;
    }

    private function report(Stock $stock, MacroStateDTO $macro, ?MathUtility $mathUtility = null): ActualFinancialsDTO
    {
        return $this->model->computeActualFinancials($stock, 1.0e9, 0.60, 1.0e8, 0.15, $macro, $mathUtility ?? $this->calmDraws());
    }

    /** The input-cost drag the stream state books: what management projects and the cost ratio carries. */
    private static function bookedDrag(ActualFinancialsDTO $actuals): float
    {
        $state = $actuals->streamZ;

        return (float) $state[FinancialConstants::STATE_INPUT_COST_DRAG_BASE]
            * ((float) $state[FinancialConstants::STATE_INPUT_COST_LEVEL] - (float) $state[FinancialConstants::STATE_INPUT_COST_RECOVERY]);
    }

    public function testDynamicWorkingCapitalIntensity(): void
    {
        // Default mix (65% branded, 35% volume)
        $this->assertEqualsWithDelta(0.65 * -0.05 + 0.35 * 0.05, $this->model->getWorkingCapitalIntensity(self::stock('DEFAULT_STAPLE')), 0.0001);
        // LARK: 80% branded, 20% volume
        $this->assertEqualsWithDelta(0.80 * -0.05 + 0.20 * 0.05, $this->model->getWorkingCapitalIntensity(self::stock('LARK')), 0.0001);
        // CROP: 20% branded, 65% volume, the desk outside the two
        $this->assertEqualsWithDelta((0.20 * -0.05 + 0.65 * 0.05) / 0.85, $this->model->getWorkingCapitalIntensity(self::stock('CROP')), 0.0001);

        $this->assertEquals(0.15, $this->model->getReversionSpeed());
        $this->assertEquals(0.015, $this->model->getMoatSpread());
    }

    /** Engel's law: volume follows income at the sector's elasticity; a brand's pricing power does not change how much people eat. */
    public function testDemandFollowsIncomeAtTheSectorElasticity(): void
    {
        $slump = new MacroStateDTO(outputGapEma: -0.04);

        foreach (['PHIL', 'LARK', 'CROP'] as $ticker) {
            $this->assertEqualsWithDelta(
                (-0.04 * ConsumerStaplesBusinessModel::OPERATING_CYCLICALITY) + $this->model->resolveFxDemandShift($slump),
                $this->model->getMacroPhysics(self::stock($ticker), $slump)['macro_demand_shift'],
                1e-12,
                $ticker
            );
        }
    }

    /** A strong brand recovers expected inflation and more on the shelf; a bulk grower recovers less of it. */
    public function testPricingPowerSetsHowMuchExpectedInflationReachesTheShelf(): void
    {
        $macro = new MacroStateDTO(tipsBreakevenEma: 0.02);

        $tobacco = $this->model->getMacroPhysics(self::stock('PHIL'), $macro);
        $this->assertEqualsWithDelta(1.0 + (0.02 * (0.50 + 0.90)), $tobacco['pricing_power_multiplier'], 1e-12, 'PHIL lists above inflation');
        $this->assertEqualsWithDelta(1.02, $tobacco['input_cost_multiplier'], 1e-12);

        $grower = $this->model->getMacroPhysics(self::stock('CROP'), $macro);
        $this->assertLessThan($grower['input_cost_multiplier'], $grower['pricing_power_multiplier'], 'CROP clears below inflation');
    }

    /**
     * A desk that buys the commodity bill forward meets a spot move as its layered contracts roll: the first quarter's
     * bill is held down, but under a lasting shift it reaches the spot buyer's in full. The cost ratio carries exactly
     * the drag the stream state books, so management's projection is the squeeze the report books.
     */
    public function testTheDeskHedgeDelaysTheBillAndDoesNotLowerIt(): void
    {
        $spike = new MacroStateDTO(agriculturalCommodityIndexEma: 140.0);

        $levels = [];
        foreach (['LARK', 'SGRB'] as $ticker) {
            $stock = self::stock($ticker);
            for ($quarter = 0; $quarter < 16; $quarter++) {
                if ($quarter === 1) {
                    $projected = $this->model->projectInputCostGap($stock, $spike);
                }
                $actuals = $this->report($stock, $spike);
                $stock->setEarningsMomentumZ($actuals->streamZ);
                $levels[$ticker][$quarter] = (float) $actuals->streamZ[FinancialConstants::STATE_INPUT_COST_LEVEL];

                $this->assertEqualsWithDelta(0.60 + self::bookedDrag($actuals), $actuals->clampedMargin, 1e-12, "{$ticker} Q{$quarter}: the cost ratio carries the booked drag, no more and no less");
                if ($quarter === 1) {
                    $state = $actuals->streamZ;
                    $this->assertEqualsWithDelta($state[FinancialConstants::STATE_INPUT_COST_LEVEL] - $state[FinancialConstants::STATE_INPUT_COST_RECOVERY], $projected ?? null, 1e-12, "{$ticker}: the projected gap is the booked one");
                }
            }
        }

        $this->assertLessThan(0.5 * $levels['LARK'][0], $levels['SGRB'][0], 'the forward cover holds the first quarter down');
        $this->assertEqualsWithDelta($levels['LARK'][15], $levels['SGRB'][15], 1e-3 * $levels['LARK'][15], 'a lasting shift reaches the hedged bill in full');
    }

    /** The desk is a trading line with its own noise; an inflation or commodity spike hands it no windfall. */
    public function testTheDeskEarnsNoCommodityWindfall(): void
    {
        $calm = $this->report(self::stock('PINT'), new MacroStateDTO());
        $spike = $this->report(self::stock('PINT'), new MacroStateDTO(inflationEma: 0.06, energyCostPushLag: 0.02, agriculturalCommodityIndexEma: 140.0));

        $this->assertGreaterThan(0.0, $calm->streamRevenue['commodity_trading']);
        $this->assertEqualsWithDelta($calm->streamRevenue['commodity_trading'], $spike->streamRevenue['commodity_trading'], 1e-6);
    }

    /**
     * A grower sells part of its bulk output at the exchange price: a dear harvest raises its revenue with no unit of
     * cost added, so it gains on balance, where a processor that only buys the crop is squeezed. The price is public,
     * so consensus hears all of it.
     */
    public function testADearHarvestIsAGrowersWindfallAndAProcessorsSqueeze(): void
    {
        $calmMacro = new MacroStateDTO();
        $harvestMacro = new MacroStateDTO(agriculturalCommodityIndexEma: 130.0);

        $calm = $this->report(self::stock('CROP'), $calmMacro);
        $dear = $this->report(self::stock('CROP'), $harvestMacro);

        $priceGain = $calm->streamRevenue['volume'] * 0.40 * 0.30;
        $this->assertEqualsWithDelta($priceGain, $dear->streamRevenue['volume'] - $calm->streamRevenue['volume'], 1e-3);
        $this->assertEqualsWithDelta(($dear->actualRevenue - $priceGain) * (0.60 + self::bookedDrag($dear)), $dear->actualVariableCosts, 1e-3, 'price is not produced: costs run on units');
        $this->assertGreaterThan($calm->ebit, $dear->ebit, 'CROP is net long the harvest');
        $this->assertEqualsWithDelta(0.0, $calm->publishedRevenueShift, 1e-12);
        $this->assertEqualsWithDelta(0.0, $dear->observableShockZ, 1e-12);
        $this->assertEqualsWithDelta($dear->actualRevenue / ($dear->actualRevenue - $priceGain) - 1.0, $dear->publishedRevenueShift, 1e-12);

        $processorCalm = $this->report(self::stock('LARK'), $calmMacro);
        $processorDear = $this->report(self::stock('LARK'), $harvestMacro);
        $this->assertEqualsWithDelta($processorCalm->actualRevenue, $processorDear->actualRevenue, 1e-6);
        $this->assertLessThan($processorCalm->ebit, $processorDear->ebit, 'LARK only buys the crop');
    }

    /** A recall or a fine is a one-off charge on the quarter's branded sales, outside the cost ratio the run rate is built from. */
    public function testARecallIsAOneOffChargeOutsideTheCostRatio(): void
    {
        $macro = new MacroStateDTO();
        $draws = function (float $eventDraw): MathUtility {
            $mathUtility = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generateStandardNormal'])->getMock();
            // firm factor, branded, volume, event
            $mathUtility->method('generateStandardNormal')->willReturnOnConsecutiveCalls(0.0, 0.0, 0.0, $eventDraw, 0.0, 0.0);

            return $mathUtility;
        };

        $quiet = $this->report(self::stock('STAPLE'), $macro, $draws(0.0));
        $recall = $this->report(self::stock('STAPLE'), $macro, $draws(-3.0));
        $fine = $this->report(self::stock('STAPLE'), $macro, $draws(-2.2));

        $this->assertNull($quiet->eventType);
        $this->assertSame(0.0, $quiet->specialCharge);

        $this->assertSame(ShockEvent::PRODUCT_RECALL, $recall->eventType);
        $this->assertTrue($recall->isPublicEvent);
        $this->assertEqualsWithDelta(ConsumerStaplesBusinessModel::RECALL_SEVERE_PENALTY * $recall->streamRevenue['branded'], $recall->specialCharge, 1e-6);
        $this->assertEqualsWithDelta($quiet->clampedMargin, $recall->clampedMargin, 1e-12, 'the charge is not a cost ratio');

        $this->assertSame(ShockEvent::REGULATORY_FINE, $fine->eventType);
        $this->assertEqualsWithDelta(ConsumerStaplesBusinessModel::RECALL_MODERATE_PENALTY * $fine->streamRevenue['branded'], $fine->specialCharge, 1e-6);
        $this->assertEqualsWithDelta($quiet->clampedMargin, $fine->clampedMargin, 1e-12);
    }

    public function testExchangeRateDragOnMacroPhysics(): void
    {
        $stock = self::stock('STD_STAPLE');

        $basePhysics = $this->model->getMacroPhysics($stock, new MacroStateDTO(exchangeRateIndexEma: 100.0));
        $strongCurrencyPhysics = $this->model->getMacroPhysics($stock, new MacroStateDTO(exchangeRateIndexEma: 120.0));

        $this->assertLessThan($basePhysics['macro_demand_shift'], $strongCurrencyPhysics['macro_demand_shift']);
    }
}
