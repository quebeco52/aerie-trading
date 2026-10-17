<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\EarningsEngine;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\HealthInsurerBusinessModel;
use PHPUnit\Framework\TestCase;

final class HealthInsurerBusinessModelTest extends TestCase
{
    /** UnitedHealth's 2019 medical care ratio, above the floor's reported-ratio equivalent. */
    private const PRICED_LOSS_RATIO = 0.825;
    private const EXPECTED_REVENUE = 100.0;

    private HealthInsurerBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new HealthInsurerBusinessModel();
    }

    /** Every membership draw at zero, so the cost trend is the only thing moving. */
    private function quietDraws(): MathUtility
    {
        return new class extends MathUtility {
            public function generatePersistentZ(float $previousZ, float $phi, ?float $commonInnovation = null, float $commonLoading = 0.0): float
            {
                return 0.0;
            }

            public function generateStandardNormal(): float
            {
                return 0.0;
            }
        };
    }

    private function plan(): Stock
    {
        $stock = new Stock();
        $stock->setTicker('GEN_PLAN');

        return $stock;
    }

    /** One report at a given priced loss ratio, carrying the stream state forward the way the engine does. */
    private function report(Stock $stock, MacroStateDTO $macro, float $pricedLossRatio = self::PRICED_LOSS_RATIO): ActualFinancialsDTO
    {
        $actuals = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: self::EXPECTED_REVENUE,
            realizedVariableMargin: $pricedLossRatio,
            fixedCosts: 0.0,
            baselineVol: 0.25,
            macroState: $macro,
            mathUtility: $this->quietDraws(),
        );
        $stock->setEarningsMomentumZ($actuals->streamZ);

        return $actuals;
    }

    public function testAtTheTrendItPricedTheBookEarnsItsPricedRatio(): void
    {
        $actuals = $this->report($this->plan(), new MacroStateDTO());

        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE, $actuals->actualRevenue, 1e-9);
        $this->assertEqualsWithDelta(self::PRICED_LOSS_RATIO, $actuals->clampedMargin, 1e-12);
        $this->assertEqualsWithDelta(self::PRICED_LOSS_RATIO, $actuals->kpis['medical_cost_ratio'], 1e-12);
        $this->assertSame(0.0, $actuals->kpis['premium_rebate']);
    }

    public function testACostTrendAbovePricingRaisesTheRatioUntilTheBookRenews(): void
    {
        $stock = $this->plan();
        $priced = (new MacroStateDTO())->reimbursementRateGrowth;
        $surge = new MacroStateDTO(reimbursementRateGrowth: $priced + 0.03);

        // The first quarter after the surge: dg/dt = 3% - g / 1y, from g = 0.
        $first = $this->report($stock, $surge);
        $gap = 0.03 * HealthInsurerBusinessModel::POLICY_TERM_YEARS * (1.0 - exp(-EarningsEngine::QUARTERLY_TIME_STEP / HealthInsurerBusinessModel::POLICY_TERM_YEARS));
        $this->assertEqualsWithDelta(self::PRICED_LOSS_RATIO * exp($gap), $first->kpis['medical_cost_ratio'], 1e-12);

        // The ratio peaks while premiums still carry the old trend, then returns as filings catch up with it.
        $peak = 0.0;
        for ($quarter = 0; $quarter < 60; $quarter++) {
            $last = $this->report($stock, $surge);
            $peak = max($peak, $last->kpis['medical_cost_ratio']);
        }
        $this->assertGreaterThan(self::PRICED_LOSS_RATIO + 0.01, $peak, 'a 3-point trend surprise costs the plan more than a point of margin at its worst');
        $this->assertEqualsWithDelta(self::PRICED_LOSS_RATIO, $last->kpis['medical_cost_ratio'], 2e-3);
    }

    public function testTheFloorRebatesPremiumWhenTheAveragedRatioFallsBelowIt(): void
    {
        // A book running at 80%, its average with it, rebates the shortfall under the floor's reported equivalent.
        $actuals = $this->report($this->plan(), new MacroStateDTO(), 0.80);
        $rebate = HealthInsurerBusinessModel::reportedLossRatioFloor() - 0.80;

        $this->assertEqualsWithDelta(0.85 - 0.027, HealthInsurerBusinessModel::reportedLossRatioFloor(), 1e-12);
        $this->assertEqualsWithDelta($rebate, $actuals->kpis['premium_rebate'], 1e-12);
        $this->assertEqualsWithDelta(self::EXPECTED_REVENUE * (1.0 - $rebate), $actuals->actualRevenue, 1e-9);
        // Claims are paid on the premium charged, so the ratio on revenue net of the rebate is the floor's.
        $this->assertEqualsWithDelta(0.80 / (1.0 - $rebate), $actuals->clampedMargin, 1e-12);
    }

    public function testTheFloorIsTestedOnTheAverageNotTheQuarter(): void
    {
        $stock = $this->plan();
        $stock->setEarningsMomentumZ([HealthInsurerBusinessModel::STATE_AVERAGE_LOSS_RATIO => 0.83]);

        // One cheap quarter pulls a 0.83 average only part of the way down: still above the floor, nothing rebated.
        $actuals = $this->report($stock, new MacroStateDTO(), 0.80);
        $average = 0.83 + ((1.0 - exp(-EarningsEngine::QUARTERLY_TIME_STEP / HealthInsurerBusinessModel::MLR_AVERAGING_YEARS)) * (0.80 - 0.83));

        $this->assertGreaterThan(HealthInsurerBusinessModel::reportedLossRatioFloor(), $average);
        $this->assertEqualsWithDelta($average, $stock->getEarningsMomentumZ()[HealthInsurerBusinessModel::STATE_AVERAGE_LOSS_RATIO], 1e-12);
        $this->assertSame(0.0, $actuals->kpis['premium_rebate']);
    }

    public function testUnemploymentAboveItsNaturalRateCostsMembers(): void
    {
        $slump = new MacroStateDTO(unemploymentRateEma: 0.06, nairuEma: 0.04);

        $this->assertEqualsWithDelta(-1.97 * 0.02, $this->model->resolveEnrollmentShift($slump), 1e-12);
        $this->assertEqualsWithDelta(-1.97 * 0.02, $this->model->getMacroPhysics($this->plan(), $slump)['macro_demand_shift'], 1e-12);
        $this->assertSame(0.0, $this->model->resolveEnrollmentShift(new MacroStateDTO()));
    }

    public function testTheClaimsFloatIsNegativeWorkingCapital(): void
    {
        $stock = $this->plan();
        $stock->setOperatingMargin('0.055');
        $stock->setFixedCostRatio(0.127);
        $lossRatio = (1.0 - 0.055) * (1.0 - 0.127);

        $this->assertEqualsWithDelta(-50.6 / 365.0 * $lossRatio, $this->model->getWorkingCapitalIntensity($stock), 1e-12);
        $days = $this->model->getWorkingCapitalDays($stock);
        $this->assertSame(0.0, $days['dso']);
        $this->assertSame(0.0, $days['dio']);
        // Payables days are struck on total cost: at the seeded margin they hold 50.6 days of claims.
        $this->assertEqualsWithDelta(50.6 * $lossRatio, $days['dpo'] * (1.0 - 0.055), 1e-9);
    }

    public function testTheFloatEarnsTheCashYieldInsideTheOperatingBuffer(): void
    {
        $stock = $this->plan();
        $stock->setTotalRevenue('80000000000');
        $stock->setTotalEquity('20000000000');
        $stock->setWholesaleDebt('8000000000');
        $stock->setCorporateTreasury('9000000000');
        $stock->setPayables('8000000000');
        $macro = new MacroStateDTO(policyRateEma: 0.04);

        $buffer = $this->model->calculateTargetOperatingCash(80_000_000_000.0, 0.0, 8_000_000_000.0);
        $invested = 8_000_000_000.0 + max(0.0, 9_000_000_000.0 - 8_000_000_000.0 - $buffer);

        $this->assertEqualsWithDelta($invested * $this->model->calculateCashYield($macro), $this->model->calculateInterestIncome($stock, $macro, $this->quietDraws()), 1.0);
    }
}
