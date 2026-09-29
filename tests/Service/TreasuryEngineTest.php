<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\CapitalAllocationContext;
use App\DTO\DebtHealthDTO;
use App\DTO\DebtMetricsDTO;
use App\DTO\MacroStateDTO;
use App\DTO\MaturityRollDTO;
use App\Data\ManagementStyle;
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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TreasuryEngineTest extends TestCase
{
    private CorporateMetrics&Stub $corporateMetrics;
    private DebtEngine&MockObject $debtEngine;
    private CapExEngine&Stub $capExEngine;
    private MathUtility $mathUtility;
    private TreasuryEngine $treasuryEngine;

    protected function setUp(): void
    {
        mt_srand(42);
        $this->corporateMetrics = $this->createStub(CorporateMetrics::class);
        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->capExEngine = $this->createStub(CapExEngine::class);
        $this->mathUtility = new MathUtility();

        // These tests are not about the maturity wall, so no principal comes due in them.
        $this->debtEngine->method('rollMaturities')->willReturn(new MaturityRollDTO());

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

    /**
     * The perverse case the covenant exists to close. Book equity moves far too slowly to register an
     * earnings collapse, so isUnderLeveraged still reads TRUE and this branch would issue debt to
     * recapitalise at precisely the moment cash flow can no longer support any. Identical to
     * testUnderleveragedStandardCorporateIssuesDebt above in every respect but the covenant flag, so a
     * pass here is attributable to the covenant and nothing else.
     */
    public function testCovenantBreachStopsTheRecapitalisationTheEquityRatioWouldWaveThrough(): void
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
            isUnderLeveraged: true,
            hasLeverageHeadroom: false,
            netDebtToEbitda: 4.5,
            ebitdaCovenantLimit: 2.0
        );

        $this->corporateMetrics->method('calculateLiveInvestedCapital')->willReturn(1_000_000_000.0);
        $this->corporateMetrics->method('calculateMarketSaturationPenalty')->willReturn(0.15);
        $this->corporateMetrics->method('calculateMarginalReturn')->willReturn(0.02);

        $this->debtEngine->expects($this->never())->method('issueDebt');

        $this->treasuryEngine->executeCorporateStrategy($ctx);

        $this->assertFalse($ctx->recapActionTaken, 'A firm past its leverage covenant must not lever up to recapitalise.');
        $this->assertSame(0.0, $ctx->debtIssued);
    }

    /**
     * ASC 718: stock-based compensation is an expense inside net income whose credit side is additional
     * paid-in capital. Equity must roll forward net of it, otherwise book value falls every quarter by a
     * charge that never left the company.
     */
    public function testStockCompensationIsCreditedBackToEquityAsPaidInCapital(): void
    {
        $stock = $this->createSolventCorporate();
        $ctx = $this->createAllocationContext($stock, stockCompensation: 40_000_000.0);

        $this->treasuryEngine->finalizeLiquidity($ctx);

        // Opening 1B + net income 100M + SBC 40M - dividends 0 - buybacks 0
        $this->assertEqualsWithDelta(1_140_000_000.0, (float) $stock->getTotalEquity(), 1.0);
    }

    /**
     * The credit is paid-in capital, not retained earnings: only income less distributions reaches RE.
     */
    public function testStockCompensationDoesNotInflateRetainedEarnings(): void
    {
        $stock = $this->createSolventCorporate();
        $ctx = $this->createAllocationContext($stock, stockCompensation: 40_000_000.0);

        $this->treasuryEngine->finalizeLiquidity($ctx);

        // Opening 500M + net income 100M, with no dividends paid
        $this->assertEqualsWithDelta(600_000_000.0, (float) $stock->getRetainedEarnings(), 1.0);
    }

    /**
     * A firm that grants no equity compensation must roll forward exactly as before this rule existed.
     */
    public function testEquityRollForwardUnchangedWhenThereIsNoStockCompensation(): void
    {
        $stock = $this->createSolventCorporate();
        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0);

        $this->treasuryEngine->finalizeLiquidity($ctx);

        $this->assertEqualsWithDelta(1_100_000_000.0, (float) $stock->getTotalEquity(), 1.0);
    }

    /**
     * The earnings engine writes the allocation's share count back to the stock after the treasury has run.
     * An offering that diluted the stock but left that count untouched was undone on the way out: the cash
     * and the equity stayed, the shares reverted, and every secondary was free money.
     */
    public function testEquityIssuanceReachesTheShareCountTheEngineWritesBack(): void
    {
        // A firm at seventy-five times earnings and thirty times book with returns well over its hurdle: the
        // bubble condition (2.5x a fair-value multiple that now carries the cycle's growth, ~20x here), and
        // the draw is pinned so the offering is certain to execute.
        $mathUtility = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generateUniform'])->getMock();
        $mathUtility->method('generateUniform')->willReturn(0.0);
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $mathUtility);

        $stock = $this->createSolventCorporate();
        $stock->setSharesOutstanding('100000000');
        $stock->setRoicTtm('0.30');
        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 300.0);
        $ctx->newShares = 100_000_000.0; // what the buyback step leaves when nothing is repurchased

        $engine->finalizeLiquidity($ctx);

        $this->assertGreaterThan(0.0, $ctx->equityRaised, 'the bubble offering must execute for this to test anything');
        $sharesIssued = $ctx->equityRaised / (300.0 * 0.90); // shares go out at the offering discount
        $this->assertEqualsWithDelta(-$sharesIssued, $stock->getCorporateFlowBacklog(), 1.0, 'the placed stock is queued to flow back onto the tape');
        $this->assertEqualsWithDelta(100_000_000.0 + $sharesIssued, (float) $stock->getSharesOutstanding(), 1.0);
        $this->assertEqualsWithDelta(
            (float) $stock->getSharesOutstanding(),
            $ctx->newShares,
            1e-6,
            'the share count the engine writes back has to carry the dilution, or the raise is booked with no shares behind it'
        );
    }

    /**
     * A firm shut out of the bond market repays maturing principal out of cash. The principal genuinely
     * leaves the balance sheet, which is the whole difference between a maturity ladder and a coupon reset.
     */
    public function testRefusedRefinancingRepaysPrincipalFromCash(): void
    {
        $stock = $this->createSolventCorporate();
        $stock->setWholesaleDebt('2000000000.00');

        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0);
        $ctx->wholesaleDebt = 2_000_000_000.0;
        $ctx->newTreasury = 1_000_000_000.0;

        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->debtEngine->method('rollMaturities')->willReturn(
            new MaturityRollDTO(maturingPrincipal: 300_000_000.0, refinanced: false, principalRepaid: 300_000_000.0, unfundedShortfall: 0.0)
        );
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $engine->finalizeLiquidity($ctx);

        $this->assertTrue($ctx->refinancingRefused);
        $this->assertEqualsWithDelta(300_000_000.0, $ctx->principalRepaid, 1.0);
        $this->assertFalse($stock->isPaymentDefault(), 'a firm that pays in full has not defaulted');
    }

    /**
     * A maturity past the committed line is not a default while anyone will still lend.
     *
     * The fixture's revolver is 5x a $30M cash floor, so it takes $150M of the $290M shortfall. The rest is
     * raised as uncommitted emergency paper, because a firm that can borrow at a penalty rate for working
     * capital is not a firm that defaults on its bonds the same afternoon. Booking the shortfall straight to
     * an event of default while the overdraft leg beside it drew on an UNCAPPED emergency facility left the
     * contractual claim as the only one in the capital structure that could go short.
     */
    public function testMaturityBeyondTheCommitmentIsCoveredByEmergencyPaperWhileTheFirmCanBorrow(): void
    {
        $stock = $this->createSolventCorporate();
        $stock->setWholesaleDebt('2000000000.00');

        // Price zero: there is no equity market to rescue it either.
        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $ctx->wholesaleDebt = 2_000_000_000.0;
        $ctx->newTreasury = 10_000_000.0;

        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->debtEngine->method('rollMaturities')->willReturn(
            new MaturityRollDTO(maturingPrincipal: 300_000_000.0, refinanced: false, principalRepaid: 10_000_000.0, unfundedShortfall: 290_000_000.0)
        );
        $this->debtEngine->method('issueDebt')->willReturnCallback(
            static function (Stock $target, float $amount): void {
                $target->setWholesaleDebt((string) ((float) $target->getWholesaleDebt() + $amount));
            }
        );
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $engine->finalizeLiquidity($ctx);

        $this->assertFalse($stock->isPaymentDefault(), 'a shortfall the emergency market funded is not a default');
        $this->assertSame(0, $stock->getQuartersInDefault());
        $this->assertEqualsWithDelta(0.0, $ctx->unfundedMaturity, 1.0, 'the whole maturity was funded');
        $this->assertEqualsWithDelta(300_000_000.0, $ctx->principalRepaid, 1.0, 'cash, the commitment and emergency paper all reached the bondholders');
        $this->assertEqualsWithDelta(150_000_000.0, (float) $stock->getRevolverDrawn(), 1.0, 'the committed line went first');
        $this->assertTrue($ctx->failedEmergencyBorrow, 'borrowing past the commitment is still a liquidity failure');
    }

    /**
     * When no market will lend at any price, the maturity really is an event of default -- and it is a
     * curable one. The firm is flagged and its grace clock starts; MarketOperator no longer liquidates on it.
     */
    public function testMaturityBeyondTheCommitmentDefaultsWhenNoMarketWillLend(): void
    {
        $stock = $this->createSolventCorporate();
        $stock->setWholesaleDebt('2000000000.00');

        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $ctx->wholesaleDebt = 2_000_000_000.0;
        $ctx->newTreasury = 10_000_000.0;
        $ctx->health = $this->healthThatCannotIssue($ctx->health);

        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->debtEngine->method('rollMaturities')->willReturn(
            new MaturityRollDTO(maturingPrincipal: 300_000_000.0, refinanced: false, principalRepaid: 10_000_000.0, unfundedShortfall: 290_000_000.0)
        );
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $engine->finalizeLiquidity($ctx);

        $this->assertTrue($stock->isPaymentDefault(), 'a maturity nobody will fund is an event of default');
        $this->assertSame(1, $stock->getQuartersInDefault(), 'the grace clock starts at one');
        $this->assertEqualsWithDelta(140_000_000.0, $ctx->unfundedMaturity, 1.0, 'only the part the revolver could not cover is unfunded');
        $this->assertEqualsWithDelta(160_000_000.0, $ctx->principalRepaid, 1.0, 'cash plus the full commitment went to the bondholders');
        $this->assertGreaterThan(0.0, (float) $stock->getTotalEquity(), 'the firm is still notionally solvent');
    }

    /**
     * The missed payment is an event, not a state of nature. A firm that finds the money the following
     * quarter is current again, and the flag and its grace clock both clear. Nothing in the engine used to
     * clear this flag at all, which made one dollar of shortfall permanent.
     */
    public function testAMissedMaturityCuredTheFollowingQuarterClearsTheDefault(): void
    {
        $stock = $this->createSolventCorporate();
        $stock->setWholesaleDebt('2000000000.00');

        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $ctx->wholesaleDebt = 2_000_000_000.0;
        $ctx->newTreasury = 10_000_000.0;
        $ctx->health = $this->healthThatCannotIssue($ctx->health);

        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->debtEngine->method('rollMaturities')->willReturn(
            new MaturityRollDTO(maturingPrincipal: 300_000_000.0, refinanced: false, principalRepaid: 10_000_000.0, unfundedShortfall: 290_000_000.0)
        );
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);
        $engine->finalizeLiquidity($ctx);

        $this->assertTrue($stock->isPaymentDefault());

        // Next quarter the market reopens and rolls the notes.
        $cured = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $cured->wholesaleDebt = (float) $stock->getWholesaleDebt();
        $cured->newTreasury = 500_000_000.0;

        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->debtEngine->method('rollMaturities')->willReturn(new MaturityRollDTO(maturingPrincipal: 0.0, refinanced: true));
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);
        $engine->finalizeLiquidity($cured);

        $this->assertFalse($stock->isPaymentDefault(), 'a funded quarter cures the default');
        $this->assertSame(0, $stock->getQuartersInDefault(), 'and resets the grace clock');
    }

    /**
     * Debt overhang (Myers 1977): rescue equity is sold only while the firm's assets still cover the debt
     * ahead of the new shares. Below that, a new dollar goes to the creditors and nobody subscribes, so the
     * missed maturity stands and the firm restructures, rather than selling stock into the hole every quarter
     * until its shares have been diluted hundreds of times over.
     */
    public function testRescueEquityIsSoldOnlyWhileTheGoingConcernCoversItsDebt(): void
    {
        foreach (['solvent' => 3_010_000_000.0, 'insolvent' => 510_000_000.0] as $case => $assetValue) {
            $stock = $this->createSolventCorporate();
            $stock->setWholesaleDebt('2000000000.00');
            $stock->setSharesOutstanding('100000000');

            $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 50.0);
            $ctx->wholesaleDebt = 2_000_000_000.0;
            $ctx->newTreasury = 10_000_000.0;
            $ctx->health = $this->healthThatCannotIssue($ctx->health);

            $debtEngine = $this->createMock(DebtEngine::class);
            $debtEngine->method('rollMaturities')->willReturn(
                new MaturityRollDTO(maturingPrincipal: 300_000_000.0, refinanced: false, principalRepaid: 10_000_000.0, unfundedShortfall: 290_000_000.0)
            );
            $debtEngine->method('assessGoingConcern')->willReturn(new \App\DTO\GoingConcernDTO(
                trailingEbit: 150_000_000.0,
                trailingEbitda: 170_000_000.0,
                assetValue: $assetValue,
                cash: 10_000_000.0,
                claims: 2_000_000_000.0
            ));
            // The subscription draw always clears, so the solvency test is the only thing that can refuse it.
            $mathUtility = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generateUniform'])->getMock();
            $mathUtility->method('generateUniform')->willReturn(0.0);

            (new TreasuryEngine($this->corporateMetrics, $debtEngine, $this->capExEngine, $mathUtility))->finalizeLiquidity($ctx);

            if ($case === 'solvent') {
                $this->assertGreaterThan(0.0, $ctx->equityRaised, 'a solvent firm can still sell rescue equity');
                $this->assertFalse($stock->isPaymentDefault(), 'and the raise funds the maturity');
            } else {
                $this->assertSame(0.0, $ctx->equityRaised, 'nobody subscribes to shares that are already out of the money');
                $this->assertEqualsWithDelta(100_000_000.0, (float) $stock->getSharesOutstanding(), 1e-6);
                $this->assertTrue($stock->isPaymentDefault(), 'the missed maturity stands, and the firm restructures');
            }
        }
    }

    /**
     * A committed facility is a term contract. It is renegotiated upward as the business grows and it does
     * not shrink because one bad quarter shrank revenue -- which is exactly what a commitment recomputed
     * from current revenue on every call did, halving the backstop at the moment it was the only thing
     * between a solvent firm and an event of default.
     */
    public function testTheRevolverCommitmentRatchetsUpAndNeverDown(): void
    {
        $stock = $this->createSolventCorporate();
        $stock->setWholesaleDebt('2000000000.00');

        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->debtEngine->method('rollMaturities')->willReturn(new MaturityRollDTO(maturingPrincipal: 0.0, refinanced: true));
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $rich = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $rich->operatingBase = 10_000_000_000.0;
        $rich->newTreasury = 100_000_000.0;
        $engine->finalizeLiquidity($rich);

        $peak = (float) $stock->getRevolverCommitment();
        $this->assertGreaterThan(0.0, $peak, 'a facility is sized even in a quarter with nothing to draw for');

        // The recession arrives and the operating base halves.
        $poor = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $poor->operatingBase = 5_000_000_000.0;
        $poor->newTreasury = 100_000_000.0;
        $engine->finalizeLiquidity($poor);

        $this->assertEqualsWithDelta($peak, (float) $stock->getRevolverCommitment(), 1.0, 'the committed line does not shrink with revenue');
    }

    /** A drawn revolver is not term debt, so it must not enlarge the principal coming due next quarter. */
    public function testARevolverDrawDoesNotEnlargeTheMaturityWall(): void
    {
        $stock = $this->createSolventCorporate();
        $stock->setWholesaleDebt('2000000000.00');

        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $ctx->wholesaleDebt = 2_000_000_000.0;
        $ctx->newTreasury = 35_000_000.0;

        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->debtEngine->method('rollMaturities')->willReturn(
            new MaturityRollDTO(maturingPrincipal: 100_000_000.0, refinanced: false, principalRepaid: 5_000_000.0, unfundedShortfall: 95_000_000.0)
        );
        $this->debtEngine->expects($this->never())->method('issueDebt');
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $engine->finalizeLiquidity($ctx);

        $this->assertEqualsWithDelta(95_000_000.0, (float) $stock->getRevolverDrawn(), 1.0);
        $this->assertEqualsWithDelta(
            1_905_000_000.0,
            (float) $stock->getWholesaleDebt(),
            1.0,
            'the notes left the ladder and the facility took their place: next quarter rolls a SMALLER wall'
        );
    }

    /**
     * A maturity the bond market refuses to roll is drawn on the committed revolver before it can become a
     * default: that is what the facility is for. The maturity wall repays down to the cash floor, so the
     * balance is never negative on that account; a revolver that only closed overdrafts left a solvent firm
     * with an untouched credit line defaulting on principal the line would have funded.
     */
    public function testCommittedRevolverFundsAMaturityTheBondMarketRefused(): void
    {
        $stock = $this->createSolventCorporate();
        $stock->setWholesaleDebt('2000000000.00');

        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $ctx->wholesaleDebt = 2_000_000_000.0;
        $ctx->newTreasury = 35_000_000.0; // the $30M operating cash floor plus the $5M cash leg of the repayment

        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->debtEngine->method('rollMaturities')->willReturn(
            new MaturityRollDTO(maturingPrincipal: 100_000_000.0, refinanced: false, principalRepaid: 5_000_000.0, unfundedShortfall: 95_000_000.0)
        );
        // The draw is NOT an issuance: it lands on the facility's own balance, priced at the revolver spread.
        $this->debtEngine->expects($this->never())->method('issueDebt');
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $engine->finalizeLiquidity($ctx);

        $this->assertFalse($stock->isPaymentDefault(), 'a maturity the revolver funded is not a default');
        $this->assertEqualsWithDelta(0.0, $ctx->unfundedMaturity, 1e-6);
        $this->assertEqualsWithDelta(100_000_000.0, $ctx->principalRepaid, 1.0, 'the whole maturity reached the bondholders');
        $this->assertEqualsWithDelta(95_000_000.0, (float) $stock->getRevolverDrawn(), 1.0, 'the line carries what the bondholders were paid');
        $this->assertEqualsWithDelta(
            2_000_000_000.0,
            (float) $stock->getWholesaleDebt() + (float) $stock->getRevolverDrawn(),
            1.0,
            'the notes were refinanced onto the line, not added to it: total borrowings are unchanged'
        );
        $this->assertEqualsWithDelta(30_000_000.0, $ctx->newTreasury, 1.0, 'the draw passed straight through to the bondholders');
    }

    /**
     * A breached maintenance covenant is an Event of Default under the credit agreement, so once the going
     * concern no longer covers the debt the lenders refuse new draws exactly as for a missed payment. The
     * maturity the bond market refused is then a payment default, and the commitment is neither terminated
     * (the breach can cure) nor upsized (no bank enlarges a line to a borrower in breach).
     */
    public function testACovenantBreachBarsNewRevolverDrawsSoARefusedMaturityDefaults(): void
    {
        $stock = $this->createSolventCorporate();
        $stock->setWholesaleDebt('20000000000.00');
        $stock->setRevolverCommitment('1000000000.00');

        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $ctx->wholesaleDebt = 20_000_000_000.0;
        $ctx->newTreasury = 10_000_000.0;
        $ctx->operatingBase = 100_000_000_000.0; // would size a far larger line, were the ratchet free
        $ctx->health = $this->healthInCovenantBreach($ctx->health);

        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->debtEngine->method('rollMaturities')->willReturn(
            new MaturityRollDTO(maturingPrincipal: 3_000_000_000.0, refinanced: false, principalRepaid: 10_000_000.0, unfundedShortfall: 2_990_000_000.0)
        );
        $this->debtEngine->method('assessGoingConcern')->willReturn($this->goingConcern(assetValue: 15_000_000_000.0, claims: 20_000_000_000.0));
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $engine->finalizeLiquidity($ctx);

        $this->assertEqualsWithDelta(0.0, (float) $stock->getRevolverDrawn(), 1e-6, 'no new draw while the covenant is breached');
        $this->assertTrue($stock->isPaymentDefault(), 'the refused maturity nobody funded is an event of default');
        $this->assertEqualsWithDelta(2_990_000_000.0, $ctx->unfundedMaturity, 1.0);
        $this->assertEqualsWithDelta(1_000_000_000.0, (float) $stock->getRevolverCommitment(), 1.0, 'the commitment survives the breach and is not upsized');
        $this->assertContains(
            'Lenders refused a $1.00B draw on its revolving credit facility while it is in breach of its leverage covenant.',
            array_column($ctx->events, 'description')
        );
    }

    /**
     * Most breaches are waived (Roberts & Sufi 2009): a borrower whose assets still cover every claim keeps its
     * line, because forcing a default on it gains the lenders nothing.
     */
    public function testLendersWaiveTheBreachOfABorrowerWhoseAssetsStillCoverItsDebt(): void
    {
        $stock = $this->createSolventCorporate();
        $stock->setWholesaleDebt('2000000000.00');
        $stock->setRevolverCommitment('150000000.00');

        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $ctx->wholesaleDebt = 2_000_000_000.0;
        $ctx->newTreasury = 35_000_000.0;
        $ctx->health = $this->healthInCovenantBreach($ctx->health);

        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->debtEngine->method('rollMaturities')->willReturn(
            new MaturityRollDTO(maturingPrincipal: 100_000_000.0, refinanced: false, principalRepaid: 5_000_000.0, unfundedShortfall: 95_000_000.0)
        );
        $this->debtEngine->method('assessGoingConcern')->willReturn($this->goingConcern(assetValue: 3_000_000_000.0, claims: 2_000_000_000.0));
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $engine->finalizeLiquidity($ctx);

        $this->assertEqualsWithDelta(95_000_000.0, (float) $stock->getRevolverDrawn(), 1.0, 'the waived line funds the maturity');
        $this->assertFalse($stock->isPaymentDefault());
        $this->assertNotContains(
            'Lenders refused a $0.10B draw on its revolving credit facility while it is in breach of its leverage covenant.',
            array_column($ctx->events, 'description')
        );
    }

    /** The commitment survived the breach, so the quarter leverage is back inside the covenant the line funds again. */
    public function testTheRevolverReopensOnceLeverageIsBackInsideTheCovenant(): void
    {
        $stock = $this->createSolventCorporate();
        $stock->setWholesaleDebt('2000000000.00');
        $stock->setRevolverCommitment('150000000.00');
        $roll = new MaturityRollDTO(maturingPrincipal: 100_000_000.0, refinanced: false, principalRepaid: 5_000_000.0, unfundedShortfall: 95_000_000.0);

        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->debtEngine->method('rollMaturities')->willReturn($roll);
        $this->debtEngine->method('assessGoingConcern')->willReturn($this->goingConcern(assetValue: 1_500_000_000.0, claims: 2_000_000_000.0));
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $breached = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $breached->wholesaleDebt = 2_000_000_000.0;
        $breached->newTreasury = 35_000_000.0;
        $breached->health = $this->healthInCovenantBreach($breached->health);
        $engine->finalizeLiquidity($breached);

        $this->assertTrue($stock->isPaymentDefault());

        $cured = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $cured->wholesaleDebt = (float) $stock->getWholesaleDebt();
        $cured->newTreasury = 35_000_000.0;
        $engine->finalizeLiquidity($cured);

        $this->assertEqualsWithDelta(95_000_000.0, (float) $stock->getRevolverDrawn(), 1.0, 'the line funds the maturity again');
        $this->assertFalse($stock->isPaymentDefault(), 'and the funded quarter cures the default');
    }

    /**
     * A firm the primary market just refused cannot turn around and issue emergency paper into the same
     * closed market. Without this the maturity wall would be toothless: every refusal would be papered over
     * by penalty-rate borrowing from lenders who had just said no. The committed revolver is the one line
     * that still funds, and it prices at its own spread, never the emergency one.
     */
    public function testAFirmRefusedRefinancingCannotIssueEmergencyDebt(): void
    {
        $stock = $this->createSolventCorporate();
        $stock->setWholesaleDebt('2000000000.00');

        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0, currentPrice: 0.0);
        $ctx->wholesaleDebt = 2_000_000_000.0;
        $ctx->newTreasury = 5_000_000.0;

        $this->debtEngine = $this->createMock(DebtEngine::class);
        $this->debtEngine->method('rollMaturities')->willReturn(
            new MaturityRollDTO(maturingPrincipal: 100_000_000.0, refinanced: false, principalRepaid: 5_000_000.0, unfundedShortfall: 95_000_000.0)
        );
        $ratesIssuedAt = [];
        $this->debtEngine->method('issueDebt')->willReturnCallback(
            static function (Stock $s, float $amount, float $rate) use (&$ratesIssuedAt): void {
                $ratesIssuedAt[] = $rate;
            }
        );
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $engine->finalizeLiquidity($ctx);

        $this->assertTrue($ctx->failedEmergencyBorrow);
        $this->assertCount(0, $ratesIssuedAt, 'the committed line funded it, and a draw is not an issuance');
        $this->assertEqualsWithDelta(95_000_000.0, (float) $stock->getRevolverDrawn(), 1.0, 'the facility carried the whole maturity');
    }

    /** The same health reading, with every lending door shut: no market will underwrite this credit. */
    private function healthThatCannotIssue(\App\DTO\DebtHealthDTO $health): \App\DTO\DebtHealthDTO
    {
        return new \App\DTO\DebtHealthDTO(
            grossCost: $health->grossCost,
            effectiveCost: $health->effectiveCost,
            cashYield: $health->cashYield,
            isNegativeCarry: $health->isNegativeCarry,
            isSevereNegativeCarry: $health->isSevereNegativeCarry,
            interestCoverage: $health->interestCoverage,
            wantsToPaydownDebt: $health->wantsToPaydownDebt,
            canIssueDebt: false,
            debtTolerance: $health->debtTolerance,
            wacc: $health->wacc,
            costOfEquity: $health->costOfEquity,
            leveredBeta: $health->leveredBeta,
            rawMetrics: $health->rawMetrics,
            isLiquidityCrisis: $health->isLiquidityCrisis,
            isLiquidityWarning: $health->isLiquidityWarning,
            isUnderLeveraged: $health->isUnderLeveraged
        );
    }

    private function goingConcern(float $assetValue, float $claims): \App\DTO\GoingConcernDTO
    {
        return new \App\DTO\GoingConcernDTO(trailingEbit: 150_000_000.0, trailingEbitda: 170_000_000.0, assetValue: $assetValue, cash: 10_000_000.0, claims: $claims);
    }

    /** No market will lend, and net debt / EBITDA stands past the 3.0x covenant. */
    private function healthInCovenantBreach(\App\DTO\DebtHealthDTO $health): \App\DTO\DebtHealthDTO
    {
        $shut = $this->healthThatCannotIssue($health);

        return new \App\DTO\DebtHealthDTO(
            grossCost: $shut->grossCost,
            effectiveCost: $shut->effectiveCost,
            cashYield: $shut->cashYield,
            isNegativeCarry: $shut->isNegativeCarry,
            isSevereNegativeCarry: $shut->isSevereNegativeCarry,
            interestCoverage: $shut->interestCoverage,
            wantsToPaydownDebt: $shut->wantsToPaydownDebt,
            canIssueDebt: false,
            debtTolerance: $shut->debtTolerance,
            wacc: $shut->wacc,
            costOfEquity: $shut->costOfEquity,
            leveredBeta: $shut->leveredBeta,
            rawMetrics: $shut->rawMetrics,
            isLiquidityCrisis: $shut->isLiquidityCrisis,
            isLiquidityWarning: $shut->isLiquidityWarning,
            isUnderLeveraged: $shut->isUnderLeveraged,
            hasLeverageHeadroom: false,
            netDebtToEbitda: 5.0,
            ebitdaCovenantLimit: 3.0
        );
    }

    private function createSolventCorporate(): Stock
    {
        $stock = new Stock();
        $stock->setTicker('SBCC');
        $stock->setIndustry('Information Technology Services');
        $stock->setTotalEquity('1000000000.00');
        $stock->setRetainedEarnings('500000000.00');
        $stock->setWholesaleDebt('200000000.00');
        $stock->setCorporateTreasury('300000000.00');
        $stock->setCreditSpread('0.02');

        return $stock;
    }

    private function createAllocationContext(Stock $stock, float $stockCompensation, float $currentPrice = 50.0): CapitalAllocationContext
    {
        $macro = MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.04,
            'yield_5y_ema' => 0.045,
        ]);

        $ctx = new CapitalAllocationContext(
            stock: $stock,
            macroState: $macro,
            actualAnnualEps: 4.0,
            quarterlyFcfPerShare: 1.0,
            currentPrice: $currentPrice,
            sharesOutstanding: 100_000_000,
            actualTotalNetIncome: 100_000_000,
            stockCompensation: $stockCompensation
        );

        $ctx->strategy = new StandardCorporateBusinessModel();
        $ctx->businessModel = 'tech';
        $ctx->quarterlyNetIncome = 100_000_000.0;
        $ctx->newTreasury = 300_000_000.0;
        $ctx->operatingBase = 1_000_000_000.0;
        $ctx->wholesaleDebt = 200_000_000.0;
        $ctx->customerDeposits = 0.0;
        // No distributions this quarter, so equity moves only through income and the SBC credit.
        $ctx->totalPaid = 0.0;
        $ctx->totalCashSpent = 0.0;
        $ctx->debtActionTaken = true; // suppress the deleveraging sweep; this test isolates the equity roll-forward

        $debtMetrics = new DebtMetricsDTO(
            interestExpense: 10_000_000.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.05,
            dynamicSpread: 0.02,
            currentMarketRate: 0.05,
            wholesaleRate: 0.05,
            ebit: 150_000_000.0,
            revenue: 800_000_000.0,
            depreciation: 20_000_000.0,
            ebitda: 170_000_000.0
        );

        $ctx->health = new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.05,
            cashYield: 0.04,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 15.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 1.0,
            wacc: 0.08,
            costOfEquity: 0.10,
            leveredBeta: 1.0,
            rawMetrics: $debtMetrics,
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: false
        );

        return $ctx;
    }

    /**
     * A lender short of operating cash sells from its book at a haircut before it borrows at penalty rates.
     * The slice sold takes its share of the allowance with it, the proceeds come in as cash, and the discount
     * is a loss charged to equity: the book shrinks by exactly what the claims on it lose.
     */
    public function testLenderShortOfCashSellsEarningAssetsAtAHaircutBeforeBorrowing(): void
    {
        $stock = new Stock();
        $stock->setTicker('RUNB');
        $stock->setIndustry('Banks - Diversified');
        $stock->setEarningAssets('5000000000.00');
        $stock->setCreditLossAllowance('100000000.00');
        $stock->setCustomerDeposits('4000000000.00');
        $stock->setWholesaleDebt('0.00');
        $stock->setCorporateTreasury('50000000.00');
        $stock->setRetainedEarnings('500000000.00');
        // Equity is the residual of the sheet the fixture opens with, so it starts balanced.
        $stock->setTotalEquity((string) ($stock->getTotalAssets() - $stock->getTotalLiabilities()));
        $equityBefore = (float) $stock->getTotalEquity();

        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0);
        $ctx->strategy = new CommercialBankBusinessModel();
        $ctx->businessModel = 'commercial_bank';
        $ctx->isFinancial = true;
        $ctx->quarterlyNetIncome = 0.0;
        $ctx->wholesaleDebt = 0.0;
        $ctx->customerDeposits = 4_000_000_000.0;
        $ctx->newTreasury = 50_000_000.0; // well below what a bank of this size has to hold

        $this->debtEngine->expects($this->never())->method('issueDebt');
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $engine->finalizeLiquidity($ctx);

        $minOperatingCash = $ctx->strategy->calculateMinOperatingCash($ctx->operatingBase, 4_000_000_000.0, 0.0);
        $this->assertGreaterThan(50_000_000.0, $minOperatingCash, 'the fixture must actually be short');
        $this->assertGreaterThan(0.0, $ctx->assetSaleProceeds, 'the book was sold down');
        $this->assertEqualsWithDelta($minOperatingCash, (float) $stock->getCorporateTreasury(), 1.0, 'cash is restored to the operating floor');

        $haircut = \App\Service\Math\FinancialConstants::EARNING_ASSET_FIRE_SALE_HAIRCUT;
        $this->assertEqualsWithDelta($ctx->assetSaleProceeds * $haircut / (1.0 - $haircut), $ctx->assetSaleLoss, 1.0, 'the loss is the haircut on what was sold');
        $this->assertLessThan(5_000_000_000.0, (float) $stock->getEarningAssets());
        $this->assertLessThan(100_000_000.0, (float) $stock->getCreditLossAllowance(), 'the allowance on the slice sold leaves with it');
        $this->assertEqualsWithDelta($equityBefore - $ctx->assetSaleLoss, (float) $stock->getTotalEquity(), 1.0, 'the loss is charged to equity');

        $assets = $stock->getTotalAssets();
        $claims = $stock->getTotalLiabilities() + (float) $stock->getTotalEquity();
        $this->assertEqualsWithDelta($assets, $claims, 1.0, 'the sheet still balances after the fire sale');
    }

    /**
     * Organic expansion is an NPV decision on the MARGINAL return — what the next dollar of plant earns at
     * the firm's current scale — not on the average return its existing plant still earns. A saturated firm
     * keeps a high average for as long as its old capital is in the ground; testing that let it deploy cash
     * below its hurdle indefinitely. Both cases here pass the pacing draw at the same probability, so the
     * only thing separating them is which side of the hurdle the marginal return falls on.
     */
    public function testOrganicCapexIsGatedOnTheMarginalReturnNotTheAverage(): void
    {
        $deploy = function (float $hurdle): CapitalAllocationContext {
            mt_srand(7);
            $stock = new Stock();
            $stock->setTicker('SAT');
            $stock->setTotalEquity('1000000000.00');
            $stock->setCorporateTreasury('200000000.00');
            $stock->setRoicTtm('0.60'); // the AVERAGE return: comfortably above either hurdle

            $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0);
            $ctx->newTreasury = 200_000_000.0; // above the cash target, well short of hoarder territory
            $ctx->debtActionTaken = false;     // no debt this quarter, so nothing forces a deployment
            $ctx->health = new DebtHealthDTO(
                grossCost: 0.05, effectiveCost: 0.05, cashYield: 0.04, isNegativeCarry: false, isSevereNegativeCarry: false,
                interestCoverage: 15.0, wantsToPaydownDebt: false, canIssueDebt: false, debtTolerance: 1.0, wacc: $hurdle,
                costOfEquity: $hurdle + 0.02, leveredBeta: 1.0, rawMetrics: $ctx->health->rawMetrics, isLiquidityCrisis: false,
                isLiquidityWarning: false, isUnderLeveraged: false
            );

            // Saturation has taken the marginal return to 40%: the pacing draw sits at its 95% ceiling either way.
            $this->corporateMetrics->method('calculateLiveInvestedCapital')->willReturn(1_000_000_000.0);
            $this->corporateMetrics->method('calculateMarketSaturationPenalty')->willReturn(0.20);
            $this->corporateMetrics->method('calculateMarginalReturn')->willReturn(0.40);

            $capExEngine = $this->createMock(CapExEngine::class);
            $capExEngine->expects($hurdle > 0.40 ? $this->never() : $this->once())->method('allocateGrowthCapEx');
            $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $capExEngine, $this->mathUtility);

            $engine->executeCorporateStrategy($ctx);

            return $ctx;
        };

        $cleared = $deploy(0.30); // marginal 40% > hurdle 30%
        $this->assertGreaterThan(0.0, $cleared->organicCapex, 'With the marginal return above the hurdle the firm expands.');

        $refused = $deploy(0.50); // average 60% > hurdle 50% > marginal 40%
        $this->assertEqualsWithDelta(0.0, $refused->organicCapex, 1e-9, 'An average return above the hurdle is not a reason to deploy when the next dollar earns less than it costs.');
    }

    /**
     * The applied hurdle has to reach the CASH-funded deployment gate, not only the earnings engine's.
     *
     * Jensen's (1986) agency cost is a manager funding projects a disciplined board would refuse, and it is
     * one manager with one hurdle: reading the raw cost of capital here left an empire builder disciplined
     * about debt-funded plant and reckless about cash-funded plant, which is an accident of funding source
     * rather than a persistent style. The marginal return below sits between the two hurdles, so the
     * neutral firm refuses the project and the empire builder takes it — from the same balance sheet.
     */
    public function testOrganicCapexGateAppliesTheHurdleManagementHoldsNotTheRawCostOfCapital(): void
    {
        $deploy = function (?ManagementStyle $style): CapitalAllocationContext {
            mt_srand(7);
            $stock = new Stock();
            $stock->setTicker('AGCY');
            $stock->setTotalEquity('1000000000.00');
            $stock->setCorporateTreasury('200000000.00');
            $stock->setRoicTtm('0.60');
            $stock->setManagementStyle($style);

            $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0);
            $ctx->newTreasury = 200_000_000.0;
            $ctx->debtActionTaken = false;
            $ctx->health = new DebtHealthDTO(
                grossCost: 0.05, effectiveCost: 0.05, cashYield: 0.04, isNegativeCarry: false, isSevereNegativeCarry: false,
                interestCoverage: 15.0, wantsToPaydownDebt: false, canIssueDebt: false, debtTolerance: 1.0, wacc: 0.50,
                costOfEquity: 0.52, leveredBeta: 1.0, rawMetrics: $ctx->health->rawMetrics, isLiquidityCrisis: false,
                isLiquidityWarning: false, isUnderLeveraged: false
            );

            // The next dollar of plant earns 40%, against a true cost of capital of 50%.
            $this->corporateMetrics->method('calculateLiveInvestedCapital')->willReturn(1_000_000_000.0);
            $this->corporateMetrics->method('calculateMarketSaturationPenalty')->willReturn(0.20);
            $this->corporateMetrics->method('calculateMarginalReturn')->willReturn(0.40);

            $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->createStub(CapExEngine::class), $this->mathUtility);
            $engine->executeCorporateStrategy($ctx);

            return $ctx;
        };

        // 0.50 x 0.75 = 0.375, which the 40% marginal return clears. That gap is the agency cost.
        $this->assertLessThan(
            0.40,
            ManagementStyle::EmpireBuilder->appliedHurdle(0.50),
            'The fixture only means anything while the empire builder\'s hurdle sits below the marginal return.'
        );

        $neutral = $deploy(null);
        $this->assertEqualsWithDelta(0.0, $neutral->organicCapex, 1e-9, 'A disciplined firm does not buy a 40% return with 50% money.');

        $empireBuilder = $deploy(ManagementStyle::EmpireBuilder);
        $this->assertGreaterThan(0.0, $empireBuilder->organicCapex, 'The empire builder funds it, and the value destruction is the point.');
    }

    /**
     * A lender lends out whatever funding sits above its liquidity target, every quarter and in full, keeping
     * only this quarter's retained earnings back for capital return. The cash goes onto the earning-asset
     * ledger directly; nothing is queued as construction. A bank its regulator has frozen lends nothing.
     */
    public function testLenderDeploysExcessFundingIntoTheBookEveryQuarter(): void
    {
        $stock = new Stock();
        $stock->setTicker('LEND');
        $stock->setIndustry('Banks - Diversified');
        $stock->setEarningAssets('2000000000.00');
        $stock->setCreditLossAllowance('0.00');
        $stock->setCustomerDeposits('0.00'); // no passive deposit growth in this quarter, so the arithmetic is exact
        $stock->setWholesaleDebt('0.00');
        $stock->setCorporateTreasury('500000000.00');
        $stock->setTotalEquity('2500000000.00');

        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0);
        $ctx->strategy = new CommercialBankBusinessModel();
        $ctx->businessModel = 'commercial_bank';
        $ctx->isFinancial = true;
        $ctx->wholesaleDebt = 0.0;
        $ctx->customerDeposits = 0.0;
        $ctx->newTreasury = 500_000_000.0;
        $ctx->operatingBase = 1_000_000_000.0;
        $ctx->retainedEarningsThisQuarter = 100_000_000.0;
        $ctx->health = new DebtHealthDTO(
            grossCost: 0.04, effectiveCost: 0.04, cashYield: 0.03, isNegativeCarry: false, isSevereNegativeCarry: false,
            interestCoverage: 10.0, wantsToPaydownDebt: false, canIssueDebt: false, debtTolerance: 15.0, wacc: 0.08,
            costOfEquity: 0.09, leveredBeta: 1.0, rawMetrics: $ctx->health->rawMetrics, isLiquidityCrisis: false,
            isLiquidityWarning: false, isUnderLeveraged: true
        );

        $capExEngine = $this->createMock(CapExEngine::class);
        $capExEngine->expects($this->never())->method('allocateGrowthCapEx');
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $capExEngine, $this->mathUtility);

        $engine->executeCorporateStrategy($ctx);

        // Target cash is 5% of the operating base with no deposits or wholesale debt, held with a 20% buffer:
        // $60M. Of the $440M above it, $100M of retained earnings stays back, so $340M is lent.
        $this->assertEqualsWithDelta(340_000_000.0, $ctx->loanOriginations, 1.0);
        $this->assertEqualsWithDelta(340_000_000.0, $ctx->organicCapex, 1.0, 'the deployment is the quarter\'s investing flow');
        $this->assertEqualsWithDelta(2_340_000_000.0, (float) $stock->getEarningAssets(), 1.0);
        $this->assertEqualsWithDelta(160_000_000.0, $ctx->newTreasury, 1.0);
        $this->assertEqualsWithDelta(0.0, (float) $stock->getCipBalance(), 1.0, 'loans are never construction in progress');
    }

    public function testCapitalConstrainedLenderKeepsItsFundingInCash(): void
    {
        $stock = new Stock();
        $stock->setTicker('THIN');
        $stock->setIndustry('Banks - Diversified');
        $stock->setEarningAssets('2000000000.00');
        $stock->setCustomerDeposits('0.00');
        $stock->setWholesaleDebt('0.00');
        $stock->setCorporateTreasury('500000000.00');
        $stock->setTotalEquity('50000000.00'); // CET1 of 2.5%, far below the conservation buffer

        $ctx = $this->createAllocationContext($stock, stockCompensation: 0.0);
        $ctx->strategy = new CommercialBankBusinessModel();
        $ctx->businessModel = 'commercial_bank';
        $ctx->isFinancial = true;
        $ctx->wholesaleDebt = 0.0;
        $ctx->customerDeposits = 0.0;
        $ctx->newTreasury = 500_000_000.0;
        $ctx->operatingBase = 1_000_000_000.0;
        $ctx->retainedEarningsThisQuarter = 0.0;
        $ctx->health = new DebtHealthDTO(
            grossCost: 0.04, effectiveCost: 0.04, cashYield: 0.03, isNegativeCarry: false, isSevereNegativeCarry: false,
            interestCoverage: 10.0, wantsToPaydownDebt: false, canIssueDebt: false, debtTolerance: 15.0, wacc: 0.08,
            costOfEquity: 0.09, leveredBeta: 1.0, rawMetrics: $ctx->health->rawMetrics, isLiquidityCrisis: false,
            isLiquidityWarning: false, isUnderLeveraged: false
        );
        $engine = new TreasuryEngine($this->corporateMetrics, $this->debtEngine, $this->capExEngine, $this->mathUtility);

        $engine->executeCorporateStrategy($ctx);

        $this->assertEqualsWithDelta(0.0, $ctx->loanOriginations, 1.0, 'a bank below its capital buffer cannot add risk-weighted assets');
        $this->assertEqualsWithDelta(2_000_000_000.0, (float) $stock->getEarningAssets(), 1.0);
        $this->assertEqualsWithDelta(500_000_000.0, $ctx->newTreasury, 1.0);
    }
}
