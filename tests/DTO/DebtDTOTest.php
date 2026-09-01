<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\DTO\DebtHealthDTO;
use App\DTO\DebtMetricsDTO;
use PHPUnit\Framework\TestCase;

class DebtDTOTest extends TestCase
{
    public function testDebtMetricsDTOInstantiation(): void
    {
        $metrics = new DebtMetricsDTO(
            interestExpense: 1000.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.04,
            dynamicSpread: 0.01,
            currentMarketRate: 0.05,
            wholesaleRate: 0.05,
            ebit: 5000.0,
            revenue: 20000.0,
            depreciation: 1000.0,
            ebitda: 6000.0
        );

        $this->assertSame(1000.0, $metrics->interestExpense);
        $this->assertSame(0.05, $metrics->blendedRate);
        $this->assertSame(0.04, $metrics->historicalFixedRate);
        $this->assertSame(0.01, $metrics->dynamicSpread);
        $this->assertSame(0.05, $metrics->currentMarketRate);
        $this->assertSame(0.05, $metrics->wholesaleRate);
        $this->assertSame(5000.0, $metrics->ebit);
        $this->assertSame(20000.0, $metrics->revenue);
        $this->assertSame(1000.0, $metrics->depreciation);
        $this->assertSame(6000.0, $metrics->ebitda);
    }

    public function testDebtHealthDTOInstantiation(): void
    {
        $metrics = new DebtMetricsDTO(
            interestExpense: 1000.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.04,
            dynamicSpread: 0.01,
            currentMarketRate: 0.05,
            wholesaleRate: 0.05,
            ebit: 5000.0,
            revenue: 20000.0,
            depreciation: 1000.0,
            ebitda: 6000.0
        );

        $health = new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.04,
            cashYield: 0.02,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 5.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 1.5,
            wacc: 0.08,
            costOfEquity: 0.10,
            leveredBeta: 1.2,
            rawMetrics: $metrics,
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: false
        );

        $this->assertSame(0.05, $health->grossCost);
        $this->assertSame(0.04, $health->effectiveCost);
        $this->assertSame(0.02, $health->cashYield);
        $this->assertFalse($health->isNegativeCarry);
        $this->assertFalse($health->isSevereNegativeCarry);
        $this->assertSame(5.0, $health->interestCoverage);
        $this->assertFalse($health->wantsToPaydownDebt);
        $this->assertTrue($health->canIssueDebt);
        $this->assertSame(1.5, $health->debtTolerance);
        $this->assertSame(0.08, $health->wacc);
        $this->assertSame(0.10, $health->costOfEquity);
        $this->assertSame(1.2, $health->leveredBeta);
        $this->assertSame($metrics, $health->rawMetrics);
        $this->assertFalse($health->isLiquidityCrisis);
        $this->assertFalse($health->isLiquidityWarning);
        $this->assertFalse($health->isUnderLeveraged);
    }
}
