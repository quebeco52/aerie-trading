<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use PHPUnit\Framework\TestCase;
use App\Service\Model\InvestmentBankBusinessModel;
use App\Service\Math\MathUtility;
use App\Entity\Stock;
use App\Data\InitialMarket;
use App\Data\StockInfo;
use App\Data\StockModelTuning;

class InvestmentBankBusinessModelTest extends TestCase
{
    private InvestmentBankBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new InvestmentBankBusinessModel();
    }

    public function testCorvIsRegisteredInInitialMarketAndStockInfo(): void
    {
        $corvConfig = null;
        foreach (InitialMarket::STOCKS as $stock) {
            if ($stock['ticker'] === 'CORV') {
                $corvConfig = $stock;
                break;
            }
        }

        $this->assertNotNull($corvConfig, 'CORV must be registered in InitialMarket::STOCKS');
        $this->assertSame('Corvid Capital', $corvConfig['name']);
        $this->assertSame('Financials', $corvConfig['sector']);
        $this->assertSame('Investment Banking', $corvConfig['industry']);
        $this->assertArrayHasKey('CORV', StockInfo::DESCRIPTIONS);
        $this->assertArrayHasKey('CORV', StockModelTuning::OVERRIDES);
    }

    public function testCorvUsesCustomTuningOverridesInIdiosyncraticShock(): void
    {
        $stock = new Stock();
        $stock->setTicker('CORV');

        $mathMock = $this->createMock(MathUtility::class);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);
        $mathMock->method('generateUniform')->willReturn(0.50); // No regulatory fine

        // High VIX environment to test CORV's vix_arbitrage_scalar = 2.00
        $macroState = [
            'output_gap_ema' => 0.0,
            'policy_rate_ema' => 0.04,
            'yield_5y_ema' => 0.04,
            'market_volatility_ema' => 0.28, // 10% above VIX_ARBITRAGE_FLOOR (0.18)
        ];

        $result = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            100.0,
            0.14,
            $macroState,
            $mathMock
        );

        // CORV overrides: advisory_weight = 0.25, trading_weight = 0.75, vix_arbitrage_scalar = 2.00
        // volatilityArbitrage = (0.28 - 0.18) * 2.00 = 0.20
        // advisoryRevenue = 1000.0 * 0.25 * 1.0 = 250.0
        // tradingRevenue  = 1000.0 * 0.75 * (1.0 + 0.20) = 900.0
        // actual_revenue  = 250.0 + 900.0 = 1150.0
        $this->assertEqualsWithDelta(1150.0, $result->actualRevenue, 0.001);
    }
}
