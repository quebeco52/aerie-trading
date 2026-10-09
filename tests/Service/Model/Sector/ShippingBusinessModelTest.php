<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Model\Sector\ShippingBusinessModel;
use App\Service\Model\StreamContext;
use App\Service\Event\ShockEvent;
use App\DTO\MacroStateDTO;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ShippingBusinessModelTest extends TestCase
{
    public function testContinuousSpotRateElasticity(): void
    {
        $model = new ShippingBusinessModel();
        $stock = new Stock();
        $stock->setTicker('SHIP');
        $stock->setBeta('1.0');

        $mathUtilityMock = $this->createStub(MathUtility::class);
        $mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        // Positive output gap -> continuous positive spot rate multiplier
        $macroStatePositive = \App\DTO\MacroStateDTO::fromArray(['output_gap_ema' => 0.010, 'inflation_ema' => 0.02]);
        $resultPositive = $model->computeActualFinancials(
            $stock,
            1000.0,
            0.40,
            50.0,
            0.15,
            $macroStatePositive,
            $mathUtilityMock
        );

        // Negative output gap -> continuous negative spot rate multiplier
        $macroStateNegative = \App\DTO\MacroStateDTO::fromArray(['output_gap_ema' => -0.010, 'inflation_ema' => 0.02]);
        $resultNegative = $model->computeActualFinancials(
            $stock,
            1000.0,
            0.40,
            50.0,
            0.15,
            $macroStateNegative,
            $mathUtilityMock
        );

        $this->assertGreaterThan($resultNegative->actualRevenue, $resultPositive->actualRevenue);
    }

    public function testVesselAgingAndEcoFleetModernization(): void
    {
        $model = new ShippingBusinessModel();

        // R = 0.5 (Underinvestment -> Vessel aging drag)
        $stock = new Stock();
        $stock->setOperatingMargin('0.25');
        $model->applyAssetDepreciationDecay($stock, 0.5, 0.25);
        $decayed = (float) $stock->getOperatingMargin();
        $this->assertLessThan(0.25, $decayed);
        $this->assertGreaterThanOrEqual(ShippingBusinessModel::MIN_OPERATING_MARGIN_FLOOR, $decayed);

        // R = 1.5 (Modernization -> Eco fleet fuel savings)
        $stock->setOperatingMargin('0.25');
        $model->applyAssetDepreciationDecay($stock, 1.5, 0.25);
        $expanded = (float) $stock->getOperatingMargin();
        $this->assertGreaterThan(0.25, $expanded);
        $this->assertLessThanOrEqual(ShippingBusinessModel::MAX_OPERATING_MARGIN_CEILING, $expanded);
    }

    public function testFreightRateIndexSurgeBoostsShippingRevenue(): void
    {
        $model = new ShippingBusinessModel();
        $stock = new Stock();
        $stock->setTicker('SHIP');
        $stock->setBeta('1.0');

        $mathUtilityMock = $this->createStub(MathUtility::class);
        $mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $baseMacro = new \App\DTO\MacroStateDTO(freightRateIndexEma: 100.0);
        $surgeMacro = new \App\DTO\MacroStateDTO(freightRateIndexEma: 140.0);

        $baseResult = $model->computeActualFinancials($stock, 1000.0, 0.40, 50.0, 0.15, $baseMacro, $mathUtilityMock);
        $surgeResult = $model->computeActualFinancials($stock, 1000.0, 0.40, 50.0, 0.15, $surgeMacro, $mathUtilityMock);

        $this->assertGreaterThan($baseResult->streamRevenue['spot'], $surgeResult->streamRevenue['spot']);
    }

    public function testFxShiftOnMacroPhysics(): void
    {
        $model = new ShippingBusinessModel();
        $stock = new Stock();
        $stock->setTicker('SHIP');
        $stock->setBeta('1.0');

        $baseMacro = new \App\DTO\MacroStateDTO(exchangeRateIndexEma: 100.0);
        $strongDollarMacro = new \App\DTO\MacroStateDTO(exchangeRateIndexEma: 120.0);

        $basePhysics = $model->getMacroPhysics($stock, $baseMacro);
        $strongDollarPhysics = $model->getMacroPhysics($stock, $strongDollarMacro);

        $this->assertLessThan($basePhysics['macro_demand_shift'], $strongDollarPhysics['macro_demand_shift']);
    }

    public function testCyclicalTroughTangibleBookValueAnchoring(): void
    {
        $model = new ShippingBusinessModel();

        $earningsValue = 10.0;
        $pbFairValue = 50.0; // High tangible book value of owned vessel fleet

        // 1. Cyclical trough (negative normalized EPS) -> heavy book weight (65%)
        $troughFairValue = $model->calculateFairValue($earningsValue, $pbFairValue, -0.50);
        // (10 * 0.35) + (50 * 0.65) = 3.5 + 32.5 = 36.0
        $this->assertEqualsWithDelta(36.0, $troughFairValue, 0.01);

        // 2. Expansion boom (positive normalized EPS) -> standard earnings weight (70%)
        $boomFairValue = $model->calculateFairValue($earningsValue, $pbFairValue, 2.00);
        // (10 * 0.70) + (50 * 0.30) = 7.0 + 15.0 = 22.0
        $this->assertEqualsWithDelta(22.0, $boomFairValue, 0.01);
    }
    public function testTailEventPersistsAsAMarkovRegimeWithRandomExit(): void
    {
        $model = new ShippingBusinessModel();
        $regimeKey = StreamContext::REGIME_STATE_PREFIX . ShippingBusinessModel::REGIME_PORT_CONGESTION;
        $macro = new MacroStateDTO(outputGapEma: 0.03);

        $run = function (array $momentum, bool $exits, float $onsetZ = 0.0) use ($model, $macro) {
            $stock = new Stock();
            $stock->setTicker('SHIP');
            $stock->setBeta('1.0');
            $stock->setEarningsMomentumZ($momentum);
            // Partial mock: stream draws and regime dice are scripted, the mix-drift weight math stays real.
            $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generatePersistentZ', 'checkProbability'])->getMock();
            // The onset stream is recognised by its sentinel previous value; every other draw is flat.
            $math->method('generatePersistentZ')->willReturnCallback(fn (float $prev): float => $prev === -9.9 ? $onsetZ : 0.0);
            $math->method('checkProbability')->willReturn($exits);
            return $model->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.10, $macro, $math);
        };

        $clean = $run([], false);
        $this->assertNull($clean->eventType);

        // Quarter 1: the trigger fires and the regime starts.
        $onset = $run(['spot' => -9.9], false, 3.0);
        $this->assertSame(ShockEvent::SHIPPING_PORT_CONGESTION, $onset->eventType);
        $this->assertSame(1.0, $onset->streamZ[$regimeKey]);

        // Quarter 2: no new trigger, no exit -> the regime is still active and its cost persists silently.
        $ongoing = $run($onset->streamZ, false);
        $this->assertNull($ongoing->eventType, 'a continuing regime is not re-announced');
        $this->assertSame(2.0, $ongoing->streamZ[$regimeKey]);
        $this->assertGreaterThan($clean->actualRevenue, $ongoing->actualRevenue, 'congested spot rates must persist after the onset quarter');

        // Quarter 3: the exit hazard fires -> back to baseline.
        $after = $run($ongoing->streamZ, true);
        $this->assertSame(0.0, $after->streamZ[$regimeKey]);
        $this->assertLessThan($ongoing->actualRevenue, $after->actualRevenue, 'spot rates normalize once berths clear');
        // The charter book and the mix have moved on meanwhile, so the baseline is the same firm with no regime on its books.
        $sameBookNoRegime = $ongoing->streamZ;
        unset($sameBookNoRegime[$regimeKey]);
        $baseline = $run($sameBookNoRegime, false);
        $this->assertEqualsWithDelta($baseline->actualRevenue, $after->actualRevenue, $baseline->actualRevenue * 1e-9, 'back to baseline once the regime exits');
    }


    /**
     * Spot revenue is rate times cargo, each entering once. The gap used to reach the spot book three times (a root
     * shift at cyclicality x MACRO_DEMAND_SCALAR, a direct 3.5x spot-rate term, and the freight index that already
     * prices world demand) and the contract book took the exchange rate twice.
     */
    public function testTheCycleFillsTheShipsOnceAndTheFreightRateIsPrice(): void
    {
        $model = new ShippingBusinessModel();
        $stock = new Stock();
        $stock->setTicker('XSHP');
        $stock->setBeta('1.0');
        $quiet = $this->createStub(MathUtility::class);
        $run = fn (MacroStateDTO $state) => $model->computeActualFinancials($stock, 1000.0, 0.40, 50.0, 0.15, $state, $quiet);
        $cargoElasticity = ShippingBusinessModel::OPERATING_CYCLICALITY * ShippingBusinessModel::MACRO_DEMAND_SCALAR;

        $gap = -0.01;
        $neutral = new MacroStateDTO(outputGapEma: 0.0, exchangeRateIndexEma: 100.0, freightRateIndexEma: MacroEngine::FREIGHT_BASELINE);
        $slump = new MacroStateDTO(outputGapEma: $gap, exchangeRateIndexEma: 100.0, freightRateIndexEma: MacroEngine::FREIGHT_BASELINE);

        // The root carries no cycle; the spot book takes the cargo elasticity, and a fresh charter book its burn rate of it.
        $this->assertEqualsWithDelta(0.0, $model->getMacroPhysics($stock, $slump)['macro_demand_shift'], 1e-12);
        $base = $run($neutral);
        $low = $run($slump);
        $this->assertEqualsWithDelta($gap * $cargoElasticity, ($low->streamRevenue['spot'] / $base->streamRevenue['spot']) - 1.0, 1e-9);
        $this->assertEqualsWithDelta($gap * $cargoElasticity * ShippingBusinessModel::CONTRACT_BACKLOG_BURN_RATE, ($low->streamRevenue['contract'] / $base->streamRevenue['contract']) - 1.0, 1e-9);
        $this->assertEqualsWithDelta(
            ShippingBusinessModel::SPOT_CHARTER_WEIGHT * $gap * $cargoElasticity,
            $model->resolveSectorActivityShift($stock, $slump),
            1e-9
        );

        // A freight-rate move reprices the same cargo one for one, books as price, and leaves the charter book alone.
        $dear = $run(new MacroStateDTO(outputGapEma: 0.0, exchangeRateIndexEma: 100.0, freightRateIndexEma: 1.2 * MacroEngine::FREIGHT_BASELINE));
        $this->assertEqualsWithDelta(1.2, $dear->streamRevenue['spot'] / $base->streamRevenue['spot'], 1e-9);
        $this->assertEqualsWithDelta($dear->streamRevenue['spot'] - $base->streamRevenue['spot'], $dear->priceRevenue, 1e-9);
        $this->assertEqualsWithDelta($base->streamRevenue['contract'], $dear->streamRevenue['contract'], 1e-9);

        // The exchange rate reaches the charter book once, through the root shift, not again in its orders.
        $strong = $run(new MacroStateDTO(outputGapEma: 0.0, exchangeRateIndexEma: 120.0, freightRateIndexEma: MacroEngine::FREIGHT_BASELINE));
        $this->assertEqualsWithDelta($base->streamRevenue['contract'], $strong->streamRevenue['contract'], 1e-9);
    }

    /** The world's cycle, not only the district's, fills the ships. */
    public function testAForeignBoomLiftsShippingDemandWithTheDistrictFlat(): void
    {
        $model = new ShippingBusinessModel();
        $stock = new Stock();
        $stock->setTicker('SHIP');
        $stock->setBeta('1.2');

        $home = new MacroStateDTO(outputGapEma: 0.0, tradeBalanceToGdpEma: MacroEngine::TRADE_BALANCE_BASELINE);
        $abroad = new MacroStateDTO(outputGapEma: 0.0, tradeBalanceToGdpEma: MacroEngine::TRADE_BALANCE_BASELINE, foreignOutputGapEma: 0.03);

        $quiet = $this->createStub(MathUtility::class);
        $spotHome = $model->computeActualFinancials($stock, 1000.0, 0.40, 50.0, 0.15, $home, $quiet)->streamRevenue['spot'];
        $spotAbroad = $model->computeActualFinancials($stock, 1000.0, 0.40, 50.0, 0.15, $abroad, $quiet)->streamRevenue['spot'];

        // Cargo volume, once: the spot book carries the foreign gap at FOREIGN_DEMAND_SENSITIVITY, the root shift none of it.
        $this->assertEqualsWithDelta(0.03 * ShippingBusinessModel::FOREIGN_DEMAND_SENSITIVITY, ($spotAbroad / $spotHome) - 1.0, 1e-9);
        $this->assertEqualsWithDelta(
            $model->getMacroPhysics($stock, $home)['macro_demand_shift'],
            $model->getMacroPhysics($stock, $abroad)['macro_demand_shift'],
            1e-12
        );
    }
}
