<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Model\Sector\ConsultingBusinessModel;
use App\Service\Model\StreamContext;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class ConsultingBusinessModelTest extends TestCase
{
    private const EXPECTED_REVENUE = 100_000_000.0;
    private const VARIABLE_COST_RATIO = 0.55;

    private ConsultingBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new ConsultingBusinessModel();
    }

    /** A macro state with every booking driver at its trend: no gap, spreads and failures at their usual level. */
    private static function calmMacro(float $outputGap = 0.0, ?float $lagged12m = null, float $spreadShift = 0.0, float $defaultMultiple = 1.0): MacroStateDTO
    {
        return new MacroStateDTO(
            outputGapEma: $outputGap,
            outputGapLag12m: $lagged12m ?? $outputGap,
            macroCreditSpreadEma: MacroConstants::CREDIT_SPREAD_LONG_RUN_MEAN + $spreadShift,
            macroCreditSpreadTrend: MacroConstants::CREDIT_SPREAD_LONG_RUN_MEAN,
            corporateDefaultRateEma: MacroConstants::CORPORATE_DEFAULT_LONG_RUN_MEAN * $defaultMultiple,
        );
    }

    /**
     * Runs one quarter with every normal draw at zero, the books opening at their steady-state depth unless the
     * momentum map says otherwise.
     *
     * @param array<string, float> $momentum
     */
    private function runQuarter(MacroStateDTO $macro, array $momentum = []): ActualFinancialsDTO
    {
        $stock = (new Stock())->setTicker('CONSULT_TEST')->setBeta('1.00');
        $stock->setEarningsMomentumZ($momentum);

        return $this->model->computeActualFinancials(
            $stock,
            self::EXPECTED_REVENUE,
            self::VARIABLE_COST_RATIO,
            20_000_000.0,
            0.0,
            $macro,
            new ScriptedDraws(generatePersistentZ: fn(float $prev): float => 0.0),
        );
    }

    private static function steadyStateQuarters(float $burnRate): float
    {
        return (1.0 - $burnRate) / $burnRate;
    }

    public function testSteadyStateBillsTheTargetMixAtTheTargetMargin(): void
    {
        $result = $this->runQuarter(self::calmMacro());

        $this->assertEqualsWithDelta(35_000_000.0, $result->streamRevenue['strategy_advisory'], 1.0);
        $this->assertEqualsWithDelta(45_000_000.0, $result->streamRevenue['technology_programmes'], 1.0);
        $this->assertEqualsWithDelta(20_000_000.0, $result->streamRevenue['risk_and_restructuring'], 1.0);
        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE, $result->actualRevenue, 1.0);
        $this->assertEqualsWithDelta(self::VARIABLE_COST_RATIO, $result->clampedMargin, 1e-9);

        // Orders replace what is billed, and the firm-wide book is each line's steady depth at its weight.
        $this->assertEqualsWithDelta(1.0, $result->kpis['book_to_bill'], 1e-9);
        $expectedDepth = (0.35 * self::steadyStateQuarters(ConsultingBusinessModel::STRATEGY_BURN_RATE))
            + (0.45 * self::steadyStateQuarters(ConsultingBusinessModel::TECHNOLOGY_BURN_RATE))
            + (0.20 * self::steadyStateQuarters(ConsultingBusinessModel::RISK_BURN_RATE));
        $this->assertEqualsWithDelta($expectedDepth, $result->kpis['backlog_quarters'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $result->kpis['technology_programmes_book_to_bill'], 1e-9);
        $this->assertNull($result->eventType);
    }

    public function testAFallingGapCutsStrategyBookingsAndReachesRevenueAtTheBurnRate(): void
    {
        $gap = -0.03;
        $result = $this->runQuarter(self::calmMacro(outputGap: $gap, lagged12m: 0.0));

        // Deal flow at trend: the gap alone moves strategy orders, and the quarter bills its burn rate of them.
        $orderShift = $gap * ConsultingBusinessModel::STRATEGY_GAP_SENSITIVITY;
        $billedShift = ConsultingBusinessModel::STRATEGY_BURN_RATE * $orderShift;
        $this->assertEqualsWithDelta(35_000_000.0 * (1.0 + $billedShift), $result->streamRevenue['strategy_advisory'], 1.0);
        $this->assertEqualsWithDelta((1.0 + $orderShift) / (1.0 + $billedShift), $result->kpis['strategy_advisory_book_to_bill'], 1e-9);

        // Technology budgets were set a year ago, when the gap was closed, and restructuring reads distress, not the
        // gap.
        $this->assertEqualsWithDelta(45_000_000.0, $result->streamRevenue['technology_programmes'], 1.0);
        $this->assertEqualsWithDelta(20_000_000.0, $result->streamRevenue['risk_and_restructuring'], 1.0);
    }

    public function testTechnologyBookingsFollowTheGapTheBudgetRoundSaw(): void
    {
        $lagged = -0.03;
        $result = $this->runQuarter(self::calmMacro(outputGap: 0.0, lagged12m: $lagged));

        $orderShift = $lagged * ConsultingBusinessModel::TECHNOLOGY_GAP_SENSITIVITY;
        $this->assertEqualsWithDelta(45_000_000.0 * (1.0 + (ConsultingBusinessModel::TECHNOLOGY_BURN_RATE * $orderShift)), $result->streamRevenue['technology_programmes'], 1.0);
        $this->assertEqualsWithDelta(35_000_000.0, $result->streamRevenue['strategy_advisory'], 1.0);
    }

    public function testCreditStressAndFailuresFillTheRestructuringBook(): void
    {
        $result = $this->runQuarter(self::calmMacro(spreadShift: 0.01, defaultMultiple: 2.0));

        $orderShift = (0.01 * ConsultingBusinessModel::RISK_SPREAD_SENSITIVITY) + ConsultingBusinessModel::RISK_DEFAULT_SENSITIVITY;
        $this->assertEqualsWithDelta(20_000_000.0 * (1.0 + (ConsultingBusinessModel::RISK_BURN_RATE * $orderShift)), $result->streamRevenue['risk_and_restructuring'], 1.0);
        $this->assertGreaterThan(1.0, $result->kpis['risk_and_restructuring_book_to_bill']);

        // Work not billed this quarter stays in the book for the next.
        $momentum = $result->streamZ;
        $this->assertGreaterThan(self::steadyStateQuarters(ConsultingBusinessModel::RISK_BURN_RATE), $momentum[StreamContext::BACKLOG_STATE_PREFIX . 'risk_and_restructuring']);
        $next = $this->runQuarter(self::calmMacro(), $momentum);
        $this->assertGreaterThan(20_000_000.0, $next->streamRevenue['risk_and_restructuring']);
        $this->assertLessThan(1.0, $next->kpis['risk_and_restructuring_book_to_bill']);
    }

    public function testTheFirmIsStaffedToTheWorkInHand(): void
    {
        $stock = (new Stock())->setTicker('CONSULT_TEST');
        $stock->setEarningsMomentumZ([]);
        $this->assertEqualsWithDelta(0.0, $this->model->resolveSectorActivityShift($stock, self::calmMacro()), 1e-12);

        // A strategy book worked down to half its steady depth leaves fewer hours to staff; the other books are full.
        $burn = ConsultingBusinessModel::STRATEGY_BURN_RATE;
        $halfDepth = 0.5 * self::steadyStateQuarters($burn);
        $stock->setEarningsMomentumZ([StreamContext::BACKLOG_STATE_PREFIX . 'strategy_advisory' => $halfDepth]);
        $strategyWorkload = $burn * ($halfDepth + 1.0);

        $this->assertEqualsWithDelta(0.35 * ($strategyWorkload - 1.0), $this->model->resolveSectorActivityShift($stock, self::calmMacro()), 1e-12);
    }

    public function testSecularGrowthAddsTheMeasuredShareDrift(): void
    {
        $expected = MacroConstants::TREND_REAL_GROWTH
            + MacroTransmission::gdpShareDrift(ConsultingBusinessModel::SECULAR_SHARE_1997, ConsultingBusinessModel::SECULAR_SHARE_2019, FinancialConstants::SECULAR_SHARE_WINDOW_YEARS);

        $this->assertEqualsWithDelta($expected, $this->model->getSecularGrowthRate(new Stock()), 1e-12);
        $this->assertGreaterThan(MacroConstants::TREND_REAL_GROWTH, $expected);
    }
}
