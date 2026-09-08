<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\DTO\CapitalAllocationContext;
use App\DTO\DebtHealthDTO;
use App\DTO\DebtMetricsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CapExEngine;
use App\DTO\MaturityRollDTO;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\TreasuryEngine;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\LogisticsBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class RunawayFeedbackLoopTest extends TestCase
{
    private CorporateMetrics $corporateMetrics;
    private DebtEngine $debtEngine;
    private CapExEngine $capExEngine;
    private MathUtility $mathUtility;
    private TreasuryEngine $treasuryEngine;

    protected function setUp(): void
    {
        mt_srand(42);
        $this->corporateMetrics = CorporateMetrics::getInstance();
        $this->debtEngine = $this->createMock(DebtEngine::class);
        // These tests are not about the maturity wall, so no principal comes due in them.
        $this->debtEngine->method('rollMaturities')->willReturn(new MaturityRollDTO());
        $this->capExEngine = $this->createStub(CapExEngine::class);
        $this->mathUtility = new MathUtility();

        $this->treasuryEngine = new TreasuryEngine(
            $this->corporateMetrics,
            $this->debtEngine,
            $this->capExEngine,
            $this->mathUtility
        );
    }

    public function testEmergencyEquityIssuanceIsBoundedByMarketCap(): void
    {
        $stock = new Stock();
        $stock->setTicker('CANV');
        $stock->setIndustry('Integrated Freight & Logistics');
        $stock->setPrice('100.00000000');
        $stock->setSharesOutstanding('1000000000'); // 1B shares -> $100B Market Cap
        $stock->setTotalEquity('80000000000.00'); // $80B
        $stock->setWholesaleDebt('65000000000.00'); // $65B
        $stock->setCorporateTreasury('0.00'); // 0 cash -> severe shortfall
        $stock->setTotalRevenue('50000000000.00');
        $stock->setOperatingMargin('0.11');

        $macro = MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.04,
            'yield_5y_ema' => 0.045,
            'macro_credit_spread_ema' => 0.02,
            'nominal_gdp_index' => 1.0,
        ]);

        $ctx = new CapitalAllocationContext(
            stock: $stock,
            macroState: $macro,
            actualAnnualEps: 1.0,
            quarterlyFcfPerShare: 0.0,
            currentPrice: 100.0,
            sharesOutstanding: 1_000_000_000,
            actualTotalNetIncome: 0.0
        );

        $strategy = new LogisticsBusinessModel();
        $ctx->strategy = $strategy;
        $ctx->businessModel = 'logistics';
        $ctx->isFinancial = false;
        $ctx->newTreasury = 0.0;
        $ctx->operatingBase = $this->corporateMetrics->calculateOperatingBase(50_000_000_000.0, 80_000_000_000.0);
        $ctx->wholesaleDebt = 65_000_000_000.0;
        $ctx->customerDeposits = 0.0;

        $debtMetrics = new DebtMetricsDTO(
            interestExpense: 4_000_000_000.0,
            blendedRate: 0.06,
            historicalFixedRate: 0.05,
            dynamicSpread: 0.02,
            currentMarketRate: 0.06,
            wholesaleRate: 0.06,
            ebit: 1_000_000_000.0,
            revenue: 50_000_000_000.0,
            depreciation: 2_000_000_000.0,
            ebitda: 3_000_000_000.0
        );

        $ctx->health = new DebtHealthDTO(
            grossCost: 0.06,
            effectiveCost: 0.05,
            cashYield: 0.02,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 0.25, // Distressed ICR
            wantsToPaydownDebt: false,
            canIssueDebt: false, // Cannot borrow debt!
            debtTolerance: 1.5,
            wacc: 0.12,
            costOfEquity: 0.14,
            leveredBeta: 1.2,
            rawMetrics: $debtMetrics,
            isLiquidityCrisis: true,
            isLiquidityWarning: true,
            isUnderLeveraged: false
        );

        $this->treasuryEngine->finalizeLiquidity($ctx);

        // Max allowable emergency raise is 25% of Market Cap ($100B * 0.25 = $25B)
        $expectedMaxRaise = 100_000_000_000.0 * FinancialConstants::MAX_EMERGENCY_EQUITY_RAISE_RATIO;
        $actualEquity = (float) $stock->getTotalEquity();
        $equityIncrease = $actualEquity - 80_000_000_000.0;

        $this->assertLessThanOrEqual($expectedMaxRaise + 1.0, $equityIncrease);

        // Shares issued should be at most $25B / ($100 * 0.90) ≈ 277.7M shares, NOT billions or trillions
        $newShares = (float) $stock->getSharesOutstanding();
        $this->assertLessThan(1_500_000_000, $newShares);
    }

    // testOperatingBaseCapsPaperEquityGrowth removed because operatingBase is no longer capped by revenue * 5.0

    public function testEarningsEngineBoundsRevenueToSectorTam(): void
    {
        $capitalAllocationEngine = $this->createStub(\App\Service\Corporate\CapitalAllocationEngine::class);
        $capitalAllocationEngine->method('allocateCapital')->willReturn([
            'new_shares' => 1_000_000_000,
            'dividend_paid' => 0.0,
            'total_paid' => 0.0,
            'total_cash_spent' => 0.0,
            'organic_capex' => 0.0,
            'events' => []
        ]);

        $debtEngine = $this->createStub(DebtEngine::class);
        $debtMetrics = new DebtMetricsDTO(
            interestExpense: 0.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.05,
            dynamicSpread: 0.01,
            currentMarketRate: 0.05,
            wholesaleRate: 0.05,
            ebit: 10_000_000_000.0,
            revenue: 50_000_000_000.0,
            depreciation: 1_000_000_000.0,
            ebitda: 11_000_000_000.0
        );
        $debtEngine->method('calculateInterestExpense')->willReturn($debtMetrics);
        $debtEngine->method('analyzeDebtHealth')->willReturn(new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.04,
            cashYield: 0.02,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 5.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 2.0,
            wacc: 0.08,
            costOfEquity: 0.10,
            leveredBeta: 1.0,
            rawMetrics: $debtMetrics,
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: false
        ));

        $marketEventPublisher = $this->createStub(\App\Service\Event\MarketEventPublisher::class);
        $narrativeEngine = $this->createStub(\App\Service\Event\NarrativeEngine::class);
        $eventDispatcher = $this->createMock(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
        $consensusEngine = new \App\Service\Market\MarketConsensusEngine();

        $engine = new \App\Service\Corporate\EarningsEngine(
            $eventDispatcher,
            $marketEventPublisher,
            $capitalAllocationEngine,
            $debtEngine,
            $this->capExEngine,
            $this->mathUtility,
            $this->corporateMetrics,
            $narrativeEngine,
            $consensusEngine
        );

        $stock = new Stock();
        $stock->setTicker('CANV');
        $stock->setIndustry('Integrated Freight & Logistics');
        $stock->setPrice('100.00000000');
        $stock->setSharesOutstanding('1000000000');
        // Set an extreme bloated $100 Trillion in total equity
        $stock->setTotalEquity('100000000000000.00');
        $stock->setWholesaleDebt('0.00');
        $stock->setCorporateTreasury('0.00');
        $stock->setOperatingMargin('0.11');
        $stock->setBaselineRoic('0.12');
        $stock->setSamRatio('0.50');

        $macro = MacroStateDTO::fromArray([
            'corporate_tax_rate' => 0.20,
            'output_gap_ema' => 0.0,
            'tips_breakeven_ema' => 0.02,
            'market_volatility_ema' => 0.15,
            'nominal_gdp_index' => 1.0,
            'policy_rate' => 0.04,
            'equity_risk_premium' => 0.05,
        ]);

        $ctx = new \App\DTO\EarningsSimulationContext(
            stock: $stock,
            macroState: $macro,
            strategy: new LogisticsBusinessModel(),
            businessModel: 'logistics',
            dt: 0.25
        );

        $tick = EarningsEngine::resolveReportingTick($stock->getTicker(), 252);
        $report = $engine->calculate($stock, $macro, $tick, 252);

        // Dynamic SAM for 0.5 SAM ratio = $1T * 1.0 * 0.50 = $500B
        // Structural revenue is capped at $500B * 1.50 = $750B
        // Annualized totalRevenue = actualRevenue * 4 bounded by EXPECTED_REVENUE_TAM_HEADROOM (1.50)
        $annualRevenue = (float) $stock->getTotalRevenue();
        $this->assertLessThanOrEqual(750_000_000_000.0 * 4.0 * EarningsEngine::EXPECTED_REVENUE_TAM_HEADROOM, $annualRevenue);
    }

    public function testEarningsEngineDoesNotBoundFinancialsToSectorTam(): void
    {
        $capitalAllocationEngine = $this->createStub(\App\Service\Corporate\CapitalAllocationEngine::class);
        $capitalAllocationEngine->method('allocateCapital')->willReturn([
            'new_shares' => 1_000_000_000,
            'dividend_paid' => 0.0,
            'total_paid' => 0.0,
            'total_cash_spent' => 0.0,
            'organic_capex' => 0.0,
            'events' => []
        ]);

        $debtEngine = $this->createStub(DebtEngine::class);
        $debtMetrics = new DebtMetricsDTO(
            interestExpense: 0.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.05,
            dynamicSpread: 0.01,
            currentMarketRate: 0.05,
            wholesaleRate: 0.05,
            ebit: 10_000_000_000.0,
            revenue: 50_000_000_000.0,
            depreciation: 0.0,
            ebitda: 10_000_000_000.0
        );
        $debtEngine->method('calculateInterestExpense')->willReturn($debtMetrics);
        $debtEngine->method('analyzeDebtHealth')->willReturn(new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.04,
            cashYield: 0.02,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 5.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 2.0,
            wacc: 0.08,
            costOfEquity: 0.10,
            leveredBeta: 1.0,
            rawMetrics: $debtMetrics,
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: false
        ));

        $marketEventPublisher = $this->createStub(\App\Service\Event\MarketEventPublisher::class);
        $narrativeEngine = $this->createStub(\App\Service\Event\NarrativeEngine::class);
        $eventDispatcher = $this->createMock(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
        $consensusEngine = new \App\Service\Market\MarketConsensusEngine();

        $engine = new \App\Service\Corporate\EarningsEngine(
            $eventDispatcher,
            $marketEventPublisher,
            $capitalAllocationEngine,
            $debtEngine,
            $this->capExEngine,
            $this->mathUtility,
            $this->corporateMetrics,
            $narrativeEngine,
            $consensusEngine
        );

        $stock = new Stock();
        $stock->setTicker('LAKE');
        $stock->setIndustry('Banks - Diversified');
        $stock->setPrice('100.00000000');
        $stock->setSharesOutstanding('1000000000');
        // $10 Trillion Equity
        $stock->setTotalEquity('10000000000000.00');
        $stock->setWholesaleDebt('0.00');
        $stock->setCorporateTreasury('0.00');
        $stock->setOperatingMargin('0.40');
        $stock->setBaselineRoe('0.15');
        $stock->setSamRatio('1.00');

        $macro = MacroStateDTO::fromArray([
            'corporate_tax_rate' => 0.20,
            'output_gap_ema' => 0.0,
            'tips_breakeven_ema' => 0.02,
            'market_volatility_ema' => 0.15,
            'nominal_gdp_index' => 1.0,
            'policy_rate' => 0.04,
            'equity_risk_premium' => 0.05,
        ]);

        $ctx = new \App\DTO\EarningsSimulationContext(
            stock: $stock,
            macroState: $macro,
            strategy: new \App\Service\Model\Sector\CommercialBankBusinessModel(),
            businessModel: 'commercial_bank',
            dt: 0.25
        );

        $tick = EarningsEngine::resolveReportingTick($stock->getTicker(), 252);
        $report = $engine->calculate($stock, $macro, $tick, 252);

        // Bank should not be bounded by the TAM ratio of $1.5T
        $annualRevenue = (float) $stock->getTotalRevenue();
        $this->assertGreaterThan(1_500_000_000_000.0, $annualRevenue);
    }
}
