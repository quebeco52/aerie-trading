<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\CapitalAllocationContext;
use App\DTO\DebtHealthDTO;
use App\DTO\DebtMetricsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CapExEngine;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\TreasuryEngine;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\Model\Sector\InvestmentBankBusinessModel;
use App\Service\Model\Sector\ShadowBankBusinessModel;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use PHPUnit\Framework\TestCase;

class TreasuryEngineTest extends TestCase
{
    private CorporateMetrics $corporateMetrics;
    private DebtEngine $debtEngine;
    private CapExEngine $capExEngine;
    private MathUtility $mathUtility;
    private TreasuryEngine $treasuryEngine;

    protected function setUp(): void
    {
        mt_srand(42);
        $this->corporateMetrics = $this->createMock(CorporateMetrics::class);
        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->capExEngine = $this->createMock(CapExEngine::class);
        $this->mathUtility = new MathUtility();

        $this->treasuryEngine = new TreasuryEngine(
            $this->corporateMetrics,
            $this->debtEngine,
            $this->capExEngine,
            $this->mathUtility
        );
    }

    public function testUnderleveragedInvestmentBankIssuesDebtEvenWhenMarginalReturnIsBelowHurdle(): void
    {
        $stock = new Stock();
        $stock->setTicker('PERE');
        $stock->setIndustry('Investment Banking');
        $stock->setTotalEquity('1000000000.00'); // $1B Equity
        $stock->setWholesaleDebt('0.00'); // $0 debt -> severely under-leveraged
        $stock->setCorporateTreasury('50000000.00');
        $stock->setCreditSpread('0.02');

        $macro = MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.04,
            'yield_5y_ema' => 0.045,
            'macro_credit_spread_ema' => 0.02,
        ]);

        $ctx = new CapitalAllocationContext(
            stock: $stock,
            macroState: $macro,
            actualAnnualEps: 1.0,
            quarterlyFcfPerShare: 0.25,
            currentPrice: 50.0,
            sharesOutstanding: 100_000_000,
            actualTotalNetIncome: 25_000_000
        );

        $strategy = new InvestmentBankBusinessModel();
        $ctx->strategy = $strategy;
        $ctx->businessModel = 'investment_bank';
        $ctx->isFinancial = true;
        $ctx->newTreasury = 50_000_000.0;
        $ctx->operatingBase = 1_000_000_000.0;
        $ctx->wholesaleDebt = 0.0;
        $ctx->customerDeposits = 0.0;

        $debtMetrics = new DebtMetricsDTO(
            interestExpense: 0.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.05,
            dynamicSpread: 0.02,
            currentMarketRate: 0.05,
            wholesaleRate: 0.05,
            ebit: 30_000_000.0,
            revenue: 100_000_000.0,
            depreciation: 2_000_000.0,
            ebitda: 32_000_000.0
        );

        // Under-leveraged is TRUE, but severe saturation penalty makes marginal return < hurdle
        $ctx->health = new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.05,
            cashYield: 0.04,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 10.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 8.0,
            wacc: 0.08,
            costOfEquity: 0.10,
            leveredBeta: 1.0,
            rawMetrics: $debtMetrics,
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: true
        );

        // Saturation penalty drops marginal return below hurdle rate
        $this->corporateMetrics->method('calculateLiveInvestedCapital')->willReturn(1_000_000_000.0);
        $this->corporateMetrics->method('calculateMarketSaturationPenalty')->willReturn(0.15);
        $this->corporateMetrics->method('calculateMarginalReturn')->willReturn(0.02); // 2% < 10% Hurdle

        $this->debtEngine->expects($this->once())
            ->method('issueDebt')
            ->with($stock, $this->greaterThan(0.0), 0.05);

        $this->treasuryEngine->executeCorporateStrategy($ctx);

        $this->assertTrue($ctx->debtActionTaken);
        $this->assertTrue($ctx->recapActionTaken);
        $this->assertGreaterThan(0.0, $ctx->debtIssued);
    }

    public function testCommercialBankDoesNotUseWholesaleDebtForUnderleveraged(): void
    {
        $stock = new Stock();
        $stock->setTicker('JPM');
        $stock->setIndustry('Commercial Banking');
        $stock->setTotalEquity('1000000000.00');
        $stock->setWholesaleDebt('0.00');
        $stock->setCorporateTreasury('50000000.00');

        $macro = MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.04,
            'yield_5y_ema' => 0.045,
        ]);

        $ctx = new CapitalAllocationContext(
            stock: $stock,
            macroState: $macro,
            actualAnnualEps: 1.0,
            quarterlyFcfPerShare: 0.25,
            currentPrice: 50.0,
            sharesOutstanding: 100_000_000,
            actualTotalNetIncome: 25_000_000
        );

        $ctx->strategy = new CommercialBankBusinessModel();
        $ctx->businessModel = 'commercial_bank';
        $ctx->isFinancial = true;
        $ctx->newTreasury = 50_000_000.0;
        $ctx->operatingBase = 1_000_000_000.0;
        $ctx->wholesaleDebt = 0.0;
        $ctx->customerDeposits = 5_000_000_000.0;

        $debtMetrics = new DebtMetricsDTO(
            interestExpense: 10_000_000.0,
            blendedRate: 0.04,
            historicalFixedRate: 0.04,
            dynamicSpread: 0.01,
            currentMarketRate: 0.04,
            wholesaleRate: 0.04,
            ebit: 30_000_000.0,
            revenue: 100_000_000.0,
            depreciation: 2_000_000.0,
            ebitda: 32_000_000.0
        );

        $ctx->health = new DebtHealthDTO(
            grossCost: 0.04,
            effectiveCost: 0.04,
            cashYield: 0.04,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 3.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 2.0,
            wacc: 0.06,
            costOfEquity: 0.10,
            leveredBeta: 1.0,
            rawMetrics: $debtMetrics,
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: true
        );

        $this->corporateMetrics->method('calculateLiveInvestedCapital')->willReturn(6_000_000_000.0);
        $this->corporateMetrics->method('calculateMarketSaturationPenalty')->willReturn(0.15);
        $this->corporateMetrics->method('calculateMarginalReturn')->willReturn(0.02);

        // Commercial bank should NOT issue wholesale debt via underleveraged path
        $this->debtEngine->expects($this->never())->method('issueDebt');

        $this->treasuryEngine->executeCorporateStrategy($ctx);

        $this->assertFalse($ctx->debtActionTaken);
    }

    public function testFinancialInstitutionsProtectedFromCashHoarderDeleveragingSweep(): void
    {
        $stock = new Stock();
        $stock->setTicker('PERE');
        $stock->setIndustry('Investment Banking');
        $stock->setTotalEquity('1000000000.00');
        $stock->setWholesaleDebt('4000000000.00'); // $4B wholesale debt (4x leverage, healthy for IB)
        $stock->setCorporateTreasury('1000000000.00'); // $1B treasury (trading float)
        $stock->setRetainedEarnings('500000000.00');
        $stock->setCreditSpread('0.02');

        $macro = MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.04,
            'yield_5y_ema' => 0.045,
        ]);

        $ctx = new CapitalAllocationContext(
            stock: $stock,
            macroState: $macro,
            actualAnnualEps: 1.0,
            quarterlyFcfPerShare: 0.25,
            currentPrice: 50.0,
            sharesOutstanding: 100_000_000,
            actualTotalNetIncome: 25_000_000
        );

        $ctx->strategy = new InvestmentBankBusinessModel();
        $ctx->businessModel = 'investment_bank';
        $ctx->isFinancial = true;
        $ctx->newTreasury = 1_000_000_000.0;
        $ctx->operatingBase = 1_000_000_000.0;
        $ctx->wholesaleDebt = 4_000_000_000.0;
        $ctx->customerDeposits = 0.0;
        $ctx->debtActionTaken = false;

        $debtMetrics = new DebtMetricsDTO(
            interestExpense: 50_000_000.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.05,
            dynamicSpread: 0.02,
            currentMarketRate: 0.05,
            wholesaleRate: 0.05,
            ebit: 100_000_000.0,
            revenue: 300_000_000.0,
            depreciation: 5_000_000.0,
            ebitda: 105_000_000.0
        );

        $ctx->health = new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.05,
            cashYield: 0.04,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 2.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 0.8, // industrial tolerance
            wacc: 0.06,
            costOfEquity: 0.10,
            leveredBeta: 1.0,
            rawMetrics: $debtMetrics,
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: false
        );

        $this->treasuryEngine->finalizeLiquidity($ctx);

        // Wholesale debt should NOT be swept down
        $this->assertEquals(4_000_000_000.0, (float) $stock->getWholesaleDebt());
    }

    public function testUnderleveragedShadowBankIssuesDebt(): void
    {
        $stock = new Stock();
        $stock->setTicker('SHAD');
        $stock->setIndustry('Mortgage Finance');
        $stock->setTotalEquity('1000000000.00');
        $stock->setWholesaleDebt('0.00');
        $stock->setCorporateTreasury('50000000.00');
        $stock->setCreditSpread('0.02');

        $macro = MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.04,
            'yield_5y_ema' => 0.045,
            'macro_credit_spread_ema' => 0.02,
        ]);

        $ctx = new CapitalAllocationContext(
            stock: $stock,
            macroState: $macro,
            actualAnnualEps: 1.0,
            quarterlyFcfPerShare: 0.25,
            currentPrice: 50.0,
            sharesOutstanding: 100_000_000,
            actualTotalNetIncome: 25_000_000
        );

        $ctx->strategy = new ShadowBankBusinessModel();
        $ctx->businessModel = 'shadow_bank';
        $ctx->isFinancial = true;
        $ctx->newTreasury = 50_000_000.0;
        $ctx->operatingBase = 1_000_000_000.0;
        $ctx->wholesaleDebt = 0.0;
        $ctx->customerDeposits = 0.0;

        $debtMetrics = new DebtMetricsDTO(
            interestExpense: 0.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.05,
            dynamicSpread: 0.02,
            currentMarketRate: 0.05,
            wholesaleRate: 0.05,
            ebit: 30_000_000.0,
            revenue: 100_000_000.0,
            depreciation: 2_000_000.0,
            ebitda: 32_000_000.0
        );

        $ctx->health = new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.05,
            cashYield: 0.04,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 10.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 8.0,
            wacc: 0.08,
            costOfEquity: 0.10,
            leveredBeta: 1.0,
            rawMetrics: $debtMetrics,
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: true
        );

        $this->corporateMetrics->method('calculateLiveInvestedCapital')->willReturn(1_000_000_000.0);
        $this->corporateMetrics->method('calculateMarketSaturationPenalty')->willReturn(0.15);
        $this->corporateMetrics->method('calculateMarginalReturn')->willReturn(0.02);

        $this->debtEngine->expects($this->once())
            ->method('issueDebt')
            ->with($stock, $this->greaterThan(0.0), 0.05);

        $this->treasuryEngine->executeCorporateStrategy($ctx);

        $this->assertTrue($ctx->debtActionTaken);
        $this->assertTrue($ctx->recapActionTaken);
        $this->assertGreaterThan(0.0, $ctx->debtIssued);
    }

    public function testUnderleveragedStandardCorporateIssuesDebt(): void
    {
        $stock = new Stock();
        $stock->setTicker('CORP');
        $stock->setIndustry('Technology');
        $stock->setTotalEquity('1000000000.00');
        $stock->setWholesaleDebt('0.00');
        $stock->setCorporateTreasury('50000000.00');
        $stock->setCreditSpread('0.02');

        $macro = MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.04,
            'yield_5y_ema' => 0.045,
            'macro_credit_spread_ema' => 0.02,
        ]);

        $ctx = new CapitalAllocationContext(
            stock: $stock,
            macroState: $macro,
            actualAnnualEps: 1.0,
            quarterlyFcfPerShare: 0.25,
            currentPrice: 50.0,
            sharesOutstanding: 100_000_000,
            actualTotalNetIncome: 25_000_000
        );

        $ctx->strategy = new StandardCorporateBusinessModel();
        $ctx->businessModel = 'standard_corporate';
        $ctx->isFinancial = false;
        $ctx->newTreasury = 50_000_000.0;
        $ctx->operatingBase = 1_000_000_000.0;
        $ctx->wholesaleDebt = 0.0;
        $ctx->customerDeposits = 0.0;

        $debtMetrics = new DebtMetricsDTO(
            interestExpense: 0.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.05,
            dynamicSpread: 0.02,
            currentMarketRate: 0.05,
            wholesaleRate: 0.05,
            ebit: 50_000_000.0,
            revenue: 200_000_000.0,
            depreciation: 5_000_000.0,
            ebitda: 55_000_000.0
        );

        $ctx->health = new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.05,
            cashYield: 0.04,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 10.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 1.5,
            wacc: 0.08,
            costOfEquity: 0.10,
            leveredBeta: 1.0,
            rawMetrics: $debtMetrics,
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: true
        );

        $this->corporateMetrics->method('calculateLiveInvestedCapital')->willReturn(1_000_000_000.0);
        $this->corporateMetrics->method('calculateMarketSaturationPenalty')->willReturn(0.15);
        $this->corporateMetrics->method('calculateMarginalReturn')->willReturn(0.02);

        $this->debtEngine->expects($this->once())
            ->method('issueDebt')
            ->with($stock, $this->greaterThan(0.0), 0.05);

        $this->treasuryEngine->executeCorporateStrategy($ctx);

        $this->assertTrue($ctx->debtActionTaken);
        $this->assertTrue($ctx->recapActionTaken);
        $this->assertGreaterThan(0.0, $ctx->debtIssued);
    }
}
