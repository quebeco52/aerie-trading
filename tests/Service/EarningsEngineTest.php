<?php

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use App\Service\Corporate\EarningsEngine;
use App\Service\Math\MathUtility;
use App\Service\Math\CorporateMetrics;
use App\Service\Event\NarrativeEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Corporate\CapitalAllocationEngine;
use App\Service\Corporate\DebtEngine;
use App\Service\Market\MarketConsensusEngine;
use App\Data\EconomicCycle;
use App\Entity\Stock;
use PHPUnit\Framework\MockObject\MockObject;
use Doctrine\ORM\EntityManagerInterface;

class EarningsEngineTest extends TestCase
{
    private MathUtility|MockObject $mathUtilityMock;
    private MarketEventPublisher|MockObject $marketEventMock;
    private EntityManagerInterface|MockObject $entityManagerMock;
    private CapitalAllocationEngine|MockObject $capitalAllocationEngineMock;
    private DebtEngine|MockObject $debtEngineMock;
    private CorporateMetrics|MockObject $corporateMetricsMock;
    private NarrativeEngine|MockObject $narrativeEngineMock;
    private EarningsEngine $engine;

    protected function setUp(): void
    {
        $this->entityManagerMock = $this->createMock(EntityManagerInterface::class);
        
        $this->capitalAllocationEngineMock = $this->createMock(CapitalAllocationEngine::class);
        $this->capitalAllocationEngineMock->method('allocateCapital')->willReturn([
            'new_shares' => 1000000,
            'dividend_paid' => 0.0,
            'total_paid' => 0.0,
            'total_cash_spent' => 0.0,
            'organic_capex' => 0.0,
            'events' => []
        ]);

        $this->debtEngineMock = $this->createMock(DebtEngine::class);
        $this->debtEngineMock->method('calculateInterestExpense')->willReturn([
            'interest_expense' => 0.0,
            'blended_rate' => 0.05,
            'historical_fixed_rate' => 0.05,
            'dynamic_spread' => 0.01,
            'current_market_rate' => 0.05,
            'wholesale_rate' => 0.05,
            'ebit' => 1000.0,
            'revenue' => 5000.0
        ]);
        $this->debtEngineMock->method('analyzeDebtHealth')->willReturn([
            'wacc' => 0.08,
            'cost_of_equity' => 0.10,
            'gross_cost' => 0.05,
            'effective_cost' => 0.04,
            'cash_yield' => 0.02,
            'is_severe_negative_carry' => false,
            'interest_coverage' => 5.0,
            'wants_to_paydown_debt' => false,
            'can_issue_debt' => true,
            'debt_tolerance' => 2.0,
            'levered_beta' => 1.0,
            'raw_metrics' => []
        ]);

        $this->marketEventMock = $this->createMock(MarketEventPublisher::class);
        $this->marketEventMock->method('publish')->willReturnCallback(function($stock, $type, $desc, $pct) {
            return [
                'type' => $type,
                'ticker' => $stock->getTicker(),
                'description' => $desc,
                'change_percent' => $pct
            ];
        });

        // 3. Mock MathUtility to control the stochastic Z-scores
        $this->mathUtilityMock = $this->createMock(MathUtility::class);

        $this->corporateMetricsMock = $this->createMock(CorporateMetrics::class);
        $this->corporateMetricsMock->method('calculateOperatingBase')->willReturn(10000000.0);

        $this->narrativeEngineMock = $this->createMock(NarrativeEngine::class);

        // Instantiate the core engine
        $this->engine = new EarningsEngine(
            $this->entityManagerMock, 
            $this->marketEventMock, 
            $this->capitalAllocationEngineMock, 
            $this->debtEngineMock, 
            $this->mathUtilityMock,
            $this->corporateMetricsMock,
            $this->narrativeEngineMock,
            new MarketConsensusEngine()
        );
    }

    private function getReportingTick(string $ticker, int $ticksPerYear = 252): int
    {
        $ticksPerQuarter = (int) ($ticksPerYear / 4);
        $ticksPerSeason = (int) ($ticksPerQuarter * 0.15); 
        return abs(crc32($ticker)) % max(1, $ticksPerSeason);
    }

    public function testCalculateReturnsNullWhenNoEventOccurs()
    {
        $stock = new Stock();
        $stock->setTicker('TEST');
        
        // Tick 50 is outside the earnings season for a standard 252-tick year
        // (Season is the first ~9 ticks of the 63-tick quarter)
        $macroState = [];
        $result = $this->engine->calculate($stock, $macroState, 50, 252);
        
        $this->assertNull($result, 'Engine should return null when the earnings probability check fails.');
    }

    public function testStandardPositiveEarningsReport()
    {
        $stock = new Stock();
        $stock->setTicker('TEST');
        $stock->setEarningsPerShare('10.00');
        $stock->setSharesOutstanding('1000000');
        $stock->setVolatility('0.20');
        $stock->setCurrentVolatility('0.20');
        $stock->setBeta('1.0');
        $stock->setTotalEquity('150000000');
        $stock->setWholesaleDebt('0');
        if (method_exists($stock, 'setCustomerDeposits')) $stock->setCustomerDeposits('0');
        $stock->setCorporateTreasury('10000000');
        $stock->setBaselineRoic('0.10');
        $stock->setOperatingMargin('0.20');
        if (method_exists($stock, 'setInvestedCapital')) {
            $stock->setInvestedCapital('140000000');
        }

        // Force a mildly positive business quarter
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.5);

        $reportingTick = $this->getReportingTick('TEST');
        $macroState = [];
        $result = $this->engine->calculate($stock, $macroState, $reportingTick, 252);

        $this->assertNotNull($result);
        $this->assertArrayHasKey(0, $result);
        $this->assertEquals('EARNINGS', $result[0]['type']);
        $this->assertEquals('TEST', $result[0]['ticker']);
        
        // EPS should have increased
        $this->assertGreaterThan(10.00, (float) $stock->getEarningsPerShare());
    }

    public function testExtremeEarningsTriggersVolatilityShock()
    {
        $stock = new Stock();
        $stock->setTicker('SHOCK');
        $stock->setEarningsPerShare('10.00');
        $stock->setSharesOutstanding('1000000');
        $stock->setVolatility('0.20');
        $stock->setCurrentVolatility('0.20');
        $stock->setBeta('1.0');
        $stock->setTotalEquity('150000000');
        $stock->setWholesaleDebt('0');
        if (method_exists($stock, 'setCustomerDeposits')) $stock->setCustomerDeposits('0');
        $stock->setCorporateTreasury('10000000');
        $stock->setBaselineRoic('0.10');
        $stock->setOperatingMargin('0.20');
        if (method_exists($stock, 'setInvestedCapital')) {
            $stock->setInvestedCapital('140000000');
        }

        // Force an extreme blowout quarter (Z > 1.5 triggers the shock)
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(2.0);

        $reportingTick = $this->getReportingTick('SHOCK');
        $macroState = [];
        $this->engine->calculate($stock, $macroState, $reportingTick, 252);

        $this->assertGreaterThan(0.20, (float) $stock->getCurrentVolatility(), 'Volatility should have spiked due to the extreme surprise.');
    }

    public function testNegativeEpsBenefitsFromRecoveryBoost()
    {
        $stock = new Stock();
        $stock->setTicker('RECOV');
        $stock->setEarningsPerShare('-10.00'); // Company is bleeding cash
        $stock->setSharesOutstanding('1000000');
        $stock->setVolatility('0.20');
        $stock->setCurrentVolatility('0.20');
        $stock->setBeta('1.0');
        $stock->setTotalEquity('150000000');
        $stock->setWholesaleDebt('0');
        if (method_exists($stock, 'setCustomerDeposits')) $stock->setCustomerDeposits('0');
        $stock->setCorporateTreasury('10000000');
        $stock->setBaselineRoic('0.10');
        $stock->setOperatingMargin('0.20');
        if (method_exists($stock, 'setInvestedCapital')) {
            $stock->setInvestedCapital('140000000');
        }

        // Neutral quarter (0.0) isolates the recovery boost math
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $reportingTick = $this->getReportingTick('RECOV');
        $macroState = [];
        $this->engine->calculate($stock, $macroState, $reportingTick, 252);

        $this->assertNotEquals(-10.00, (float) $stock->getEarningsPerShare(), 'A company with negative EPS should still see EPS changes.');
    }
}