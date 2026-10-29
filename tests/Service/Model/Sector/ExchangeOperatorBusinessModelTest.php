<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\Data\Company\AnchorHoldings;
use App\Data\Company\InitialMarket;
use App\Data\Company\Sectors;
use App\Data\Company\StrategicHoldings;
use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use App\Service\Model\Sector\ExchangeOperatorBusinessModel;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class ExchangeOperatorBusinessModelTest extends TestCase
{
    private const EXPECTED_REVENUE = 100_000_000.0;
    private const VARIABLE_COST_RATIO = 0.20;

    private ExchangeOperatorBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new ExchangeOperatorBusinessModel();
    }

    /**
     * One quarter with every draw at zero.
     *
     * @param array<string, float> $momentum
     */
    private function runQuarter(MacroStateDTO $macro, array $momentum = []): ActualFinancialsDTO
    {
        $stock = (new Stock())->setTicker('EXCHANGE_TEST')->setBeta('1.00');
        $stock->setEarningsMomentumZ($momentum);

        return $this->model->computeActualFinancials(
            $stock,
            self::EXPECTED_REVENUE,
            self::VARIABLE_COST_RATIO,
            30_000_000.0,
            0.0,
            $macro,
            new ScriptedDraws(),
        );
    }

    public function testRookeryIsTheListedExchangeAndTheDistrictHoldsAFifthOfTheCapital(): void
    {
        $rows = array_values(array_filter(InitialMarket::STOCKS, static fn(array $row): bool => $row['ticker'] === 'ROOK'));
        $this->assertCount(1, $rows);
        $this->assertSame('Rookery Holdings', $rows[0]['name']);
        $this->assertInstanceOf(ExchangeOperatorBusinessModel::class, Sectors::strategyFor($rows[0]['industry']));

        $this->assertSame(0.20, StrategicHoldings::stake('ROOK'));
        $this->assertEqualsWithDelta(0.80, AnchorHoldings::tradableFloat('ROOK', (float) $rows[0]['public_float']), 1e-12);
    }

    public function testACalmQuarterEarnsThePlanMix(): void
    {
        $result = $this->runQuarter(new MacroStateDTO());

        $this->assertEqualsWithDelta(40_000_000.0, $result->streamRevenue['equity_trading'], 1.0);
        $this->assertEqualsWithDelta(20_000_000.0, $result->streamRevenue['commodity_futures'], 1.0);
        $this->assertEqualsWithDelta(10_000_000.0, $result->streamRevenue['listings'], 1.0);
        $this->assertEqualsWithDelta(30_000_000.0, $result->streamRevenue['market_data'], 1.0);
    }

    /**
     * Value traded is turnover times the board's value: turnover doubling on a board worth a third less than its trend
     * leaves a third more to charge fees on, and nothing else moves.
     */
    public function testShareTradingFeesFollowTurnoverTimesTheBoardsValue(): void
    {
        $calm = $this->runQuarter(new MacroStateDTO());
        $selloff = $this->runQuarter(new MacroStateDTO(
            equityWealthRatio: 0.8,
            equityWealthTrend: 1.2,
            boardTurnoverEma: 2.4,
            boardTurnoverTrend: 1.2,
        ));

        $this->assertEqualsWithDelta(4.0 / 3.0, $selloff->streamRevenue['equity_trading'] / $calm->streamRevenue['equity_trading'], 1e-9);
        $this->assertEqualsWithDelta($calm->streamRevenue['commodity_futures'], $selloff->streamRevenue['commodity_futures'], 1e-6);
        $this->assertEqualsWithDelta($calm->streamRevenue['market_data'], $selloff->streamRevenue['market_data'], 1e-6);
    }

    /**
     * Futures volume rises with the basket's realised volatility over its trend at the futures volume elasticity; a
     * quarter whose basket has not moved leaves the readings as they were.
     */
    public function testFuturesVolumeFollowsCommodityVolatilityOverItsTrend(): void
    {
        $macro = new MacroStateDTO();
        $first = $this->runQuarter($macro);
        $this->assertEqualsWithDelta(20_000_000.0, $first->streamRevenue['commodity_futures'], 1.0, 'The first quarter only records the level.');

        $momentum = $first->streamZ;
        $momentum[ExchangeOperatorBusinessModel::STATE_COMMODITY_VARIANCE] = 0.04;
        $momentum[ExchangeOperatorBusinessModel::STATE_COMMODITY_VARIANCE_TREND] = 0.01;
        $busy = $this->runQuarter($macro, $momentum);

        $expected = 2.0 ** ExchangeOperatorBusinessModel::COMMODITY_VOLUME_VOLATILITY_ELASTICITY;
        $this->assertEqualsWithDelta($expected, $busy->streamRevenue['commodity_futures'] / $first->streamRevenue['commodity_futures'], 1e-9);
        $this->assertEqualsWithDelta(0.04, $busy->streamZ[ExchangeOperatorBusinessModel::STATE_COMMODITY_VARIANCE], 1e-12);
    }

    /**
     * Each basket move feeds its annualised squared return into the variance and the trend, which open as running
     * means of the moves seen so far: two quarters in, both are the mean of the two, so neither move alone is normal.
     */
    public function testTheVarianceAndItsTrendOpenAsRunningMeans(): void
    {
        $macro = new MacroStateDTO();
        $first = $this->runQuarter($macro);
        $basketLog = $first->streamZ[ExchangeOperatorBusinessModel::STATE_COMMODITY_BASKET_LOG];

        $momentum = $first->streamZ;
        $momentum[ExchangeOperatorBusinessModel::STATE_COMMODITY_BASKET_LOG] = $basketLog - 0.10;
        $once = $this->runQuarter($macro, $momentum);
        $big = 0.01 / FinancialConstants::QUARTER_YEARS;
        $this->assertEqualsWithDelta($big, $once->streamZ[ExchangeOperatorBusinessModel::STATE_COMMODITY_VARIANCE], 1e-12);
        $this->assertEqualsWithDelta($big, $once->streamZ[ExchangeOperatorBusinessModel::STATE_COMMODITY_VARIANCE_TREND], 1e-12);

        $momentum = $once->streamZ;
        $momentum[ExchangeOperatorBusinessModel::STATE_COMMODITY_BASKET_LOG] = $basketLog - 0.02;
        $twice = $this->runQuarter($macro, $momentum);
        $mean = ($big + (0.0004 / FinancialConstants::QUARTER_YEARS)) / 2.0;
        $this->assertSame(2.0, $twice->streamZ[ExchangeOperatorBusinessModel::STATE_COMMODITY_SAMPLES]);
        $this->assertEqualsWithDelta($mean, $twice->streamZ[ExchangeOperatorBusinessModel::STATE_COMMODITY_VARIANCE], 1e-12);
        $this->assertEqualsWithDelta($mean, $twice->streamZ[ExchangeOperatorBusinessModel::STATE_COMMODITY_VARIANCE_TREND], 1e-12);
        $this->assertEqualsWithDelta(20_000_000.0, $twice->streamRevenue['commodity_futures'], 1.0);
    }

    /** The stamp duty is priced against the share-trading fees net of their variable cost, as a share of revenue. */
    public function testTheStampDutyBaseIsShareTradingNetOfVariableCost(): void
    {
        $result = $this->runQuarter(new MacroStateDTO());

        $this->assertEqualsWithDelta(0.40 * (1.0 - self::VARIABLE_COST_RATIO), $result->streamZ[FinancialConstants::STATE_STAMP_DUTY_TURNOVER_SHARE], 1e-6);
    }
}
