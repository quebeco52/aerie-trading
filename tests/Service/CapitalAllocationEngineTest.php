<?php

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use App\Service\Corporate\CapitalAllocationEngine;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\TreasuryEngine;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;
use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;

class CapitalAllocationEngineTest extends TestCase
{
    private EntityManagerInterface|MockObject $entityManagerMock;
    private CorporateMetrics|MockObject $corporateMetricsMock;
    private DebtEngine|MockObject $debtEngineMock;
    private MathUtility|MockObject $mathUtilityMock;
    private TreasuryEngine|MockObject $treasuryEngineMock;
    private CapitalAllocationEngine $engine;

    protected function setUp(): void
    {
        $this->entityManagerMock = $this->createMock(EntityManagerInterface::class);
        $connectionMock = $this->createMock(Connection::class);
        $connectionMock->method('executeStatement')->willReturn(1);
        $this->entityManagerMock->method('getConnection')->willReturn($connectionMock);

        $this->corporateMetricsMock = $this->createMock(CorporateMetrics::class);
        $this->corporateMetricsMock->method('getIndustryDepreciationRate')->willReturn(0.05);
        $this->corporateMetricsMock->method('calculateMarketSaturationPenalty')->willReturn(0.0);

        $this->debtEngineMock = $this->createMock(DebtEngine::class);
        $this->debtEngineMock->method('calculateInterestExpenseAndWholesaleRate')->willReturn([
            'interest_expense' => 500000.0,
            'blended_rate' => 0.05,
            'dynamic_spread' => 0.02
        ]);
        $this->debtEngineMock->method('getInterestCoverage')->willReturn(5.0);

        $this->mathUtilityMock = $this->createMock(MathUtility::class);
        $this->treasuryEngineMock = $this->createMock(TreasuryEngine::class);

        $this->engine = new CapitalAllocationEngine(
            $this->entityManagerMock,
            $this->corporateMetricsMock,
            $this->debtEngineMock,
            $this->mathUtilityMock,
            $this->treasuryEngineMock
        );
    }

    public function testReitFfoDividendCapacity(): void
    {
        $stock = new Stock();
        $stock->setSymbol('TEST_REIT');
        $stock->setIndustry('REITs - Diversified');
        $stock->setSharesOutstanding('1000000');
        $stock->setPrice('100.00');
        $stock->setTotalEquity('100000000');
        $stock->setInvestedCapital('100000000');
        $stock->setCorporateTreasury('50000000');
        $stock->setTargetPayoutRatio('0.80');
        $stock->setDividendSpeed('0.50');
        $stock->setLastDividend('0.00');
        $stock->setCeoArchetype('balanced');
        $stock->setDepreciationRate('0.05');

        $health = [
            'wacc' => 0.06,
            'cost_of_equity' => 0.08,
            'interest_coverage' => 5.0
        ];

        // GAAP EPS is 1.00 ($1M / 1M shares). With Invested Capital 100M and Dep 5%, quarterly dep is $1.25M ($1.25/share).
        // FFO per share = 1.00 + 1.25 = 2.25. Payout target 80% = 1.80 per share.
        $result = $this->engine->allocateCapital($stock, 4.00, 100.00, 50000000.0, 0.0, 100000000.0, 2000000.0, 0.0, $health, 1000000.0);

        $this->assertGreaterThan(1.00, $result['dividend_paid'], 'REIT dividend should reflect FFO rather than raw GAAP EPS');
    }

    public function testDividendDistributionCreditsEscrowedSellOrders(): void
    {
        $executedQueries = [];
        $connectionMock = $this->createMock(Connection::class);
        $connectionMock->method('executeStatement')->willReturnCallback(function ($sql, $params = []) use (&$executedQueries) {
            $executedQueries[] = $sql;
            return 1;
        });

        $entityManagerMock = $this->createMock(EntityManagerInterface::class);
        $entityManagerMock->method('getConnection')->willReturn($connectionMock);

        $engine = new CapitalAllocationEngine(
            $entityManagerMock,
            $this->corporateMetricsMock,
            $this->debtEngineMock,
            $this->mathUtilityMock,
            $this->treasuryEngineMock
        );

        $stock = new Stock();
        $stock->setSymbol('TEST_DIV');
        $stock->setIndustry('Tech');
        $stock->setSharesOutstanding('1000000');
        $stock->setPrice('100.00');
        $stock->setTotalEquity('100000000');
        $stock->setInvestedCapital('100000000');
        $stock->setCorporateTreasury('50000000');
        $stock->setTargetPayoutRatio('0.50');
        $stock->setDividendSpeed('1.00');
        $stock->setLastDividend('1.00');
        $stock->setCeoArchetype('balanced');

        $health = [
            'wacc' => 0.06,
            'cost_of_equity' => 0.08,
            'interest_coverage' => 5.0
        ];

        $engine->allocateCapital($stock, 4.00, 100.00, 50000000.0, 0.0, 100000000.0, 2000000.0, 0.0, $health, 1000000.0);

        $foundEscrowDividendSql = false;
        foreach ($executedQueries as $sql) {
            if (str_contains($sql, "FROM trade_orders WHERE ticker = :ticker AND status = 'OPEN' AND action = 'SELL'")) {
                $foundEscrowDividendSql = true;
                break;
            }
        }

        $this->assertTrue($foundEscrowDividendSql, 'Dividend distribution SQL did not query trade_orders for escrowed SELL shares!');
    }
}
