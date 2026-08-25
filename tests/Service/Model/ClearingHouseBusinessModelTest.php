<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use PHPUnit\Framework\TestCase;
use App\Service\Model\Sector\ClearingHouseBusinessModel;
use App\Service\Model\BusinessModelInterface;
use App\Service\Math\MathUtility;

class ClearingHouseBusinessModelTest extends TestCase
{
    private ClearingHouseBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new ClearingHouseBusinessModel();
    }

    public function testImplementsBusinessModelInterface(): void
    {
        $this->assertInstanceOf(BusinessModelInterface::class, $this->model);
    }

    public function testCalculateEarningsValue(): void
    {
        $mathMock = $this->createStub(MathUtility::class);

        $val1 = $this->model->calculateEarningsValue(100.0, 150.0, 5.0, 0.08, $mathMock);
        $this->assertSame(150.0, $val1);

        $val2 = $this->model->calculateEarningsValue(200.0, 150.0, 5.0, 0.08, $mathMock);
        $this->assertSame(200.0, $val2);
    }

    public function testCalculateInterestIncomeScalesWithPolicyRateAndZirpTrap(): void
    {
        $stockMock = $this->createStub(\App\Entity\Stock::class);
        $stockMock->method('getCorporateTreasury')->willReturn('100000000000.0');
        $stockMock->method('getCustomerDeposits')->willReturn('100000000000.0'); // 100% margin pool

        $mathMock = $this->createStub(MathUtility::class);

        // Low interest rate ZIRP regime (< 1%)
        $lowRateState = new \App\DTO\MacroStateDTO(
            policyRateEma: 0.005,
            yield2yEma: 0.005
        );
        $incomeLow = $this->model->calculateInterestIncome($stockMock, $lowRateState, $mathMock);

        // High interest rate regime
        $highRateState = new \App\DTO\MacroStateDTO(
            policyRateEma: 0.05,
            yield2yEma: 0.06
        );
        $incomeHigh = $this->model->calculateInterestIncome($stockMock, $highRateState, $mathMock);

        $this->assertGreaterThan($incomeLow, $incomeHigh, 'Clearinghouse margin pool yield must scale upward during high-rate regimes.');
    }

    public function testCalculateCashYieldUsesPolicyRate(): void
    {
        $macroState = new \App\DTO\MacroStateDTO(
            policyRateEma: 0.0525,
            yield2yEma: 0.0400
        );
        $yield = $this->model->calculateCashYield($macroState);

        $this->assertSame(0.0525, $yield);
    }
}
