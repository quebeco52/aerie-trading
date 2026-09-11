<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\CapitalAllocationContext;
use App\DTO\DebtHealthDTO;
use App\DTO\DebtMetricsDTO;
use App\DTO\MacroStateDTO;
use App\DTO\MaturityRollDTO;
use App\Entity\Stock;
use App\Service\Corporate\CapExEngine;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\TreasuryEngine;
use App\Service\Market\CreditRatingAgency;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Guards the path that liquidated solvent companies.
 *
 * A multi-year market run was killing issuers whose accounts never deteriorated. The chain ran: a cyclical
 * firm's spot volatility ratchets to its cap on repeated earnings surprises -> that spot figure is carried
 * undiminished through a five-year Merton horizon -> distance to default collapses -> the notch clamp is
 * bypassed and the rating falls to D in three steps -> the rating floor shuts the primary market -> the
 * whole maturing slug must be repaid in cash -> the treasury is swept to zero -> the residue is an event of
 * default and the company is permanently liquidated, with a Safe Altman score and 6x interest coverage.
 *
 * Each link is pinned here independently.
 */
#[AllowMockObjectsWithoutExpectations]
class SolventFirmSurvivalTest extends TestCase
{
    /**
     * The case that started it: an integrated producer with a Safe Altman score, ample coverage and
     * compounding equity, whose only distress signal is that its earnings keep surprising. Its spot
     * volatility sits at the 3x cap the earnings engine allows. It must remain financeable.
     */
    public function testAHealthyIssuerAtItsVolatilityCapKeepsMarketAccess(): void
    {
        $agency = new CreditRatingAgency();
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), $agency, null);

        $stock = $this->buildHealthyCyclicalProducer();
        $stock->setCurrentVolatility('1.05'); // 3x the 0.35 structural level

        // Re-rate repeatedly: one notch per evaluation must still not reach the refinancing floor.
        for ($quarter = 0; $quarter < 12; $quarter++) {
            $health = $engine->analyzeDebtHealth($stock, $this->neutralMacro(), 200_000_000_000.0, 0.30);
        }

        $ranks = CreditRatingAgency::RATING_RANKS;
        $this->assertGreaterThanOrEqual(
            $ranks['BB'],
            $ranks[$stock->getCreditRating()],
            'a firm with a Safe Altman score and 6x coverage must not be rated near default on volatility alone'
        );
        $this->assertTrue(
            $engine->rollMaturities($stock, $health, 0.0)->refinanced,
            'a solvent, investment-grade-ish issuer must keep primary market access'
        );
    }

    /**
     * The same balance sheet rated at its structural volatility and at its capped spot volatility must not
     * land in different worlds. Before the horizon average existed, those two ratings were BBB and D.
     */
    public function testVolatilityAloneCannotSpanTheWholeRatingScale(): void
    {
        $agency = new CreditRatingAgency();
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), $agency, null);

        $rate = function (string $spotVol) use ($engine): string {
            $stock = $this->buildHealthyCyclicalProducer();
            $stock->setCurrentVolatility($spotVol);
            for ($quarter = 0; $quarter < 12; $quarter++) {
                $engine->analyzeDebtHealth($stock, $this->neutralMacro(), 200_000_000_000.0, 0.30);
            }

            return $stock->getCreditRating();
        };

        $ranks = CreditRatingAgency::RATING_RANKS;
        $calm = $ranks[$rate('0.35')];
        $shocked = $ranks[$rate('1.05')];

        $this->assertLessThanOrEqual($calm, $shocked, 'a volatility shock may only ever hurt the rating');
        $this->assertLessThanOrEqual(
            2,
            $calm - $shocked,
            'tripling spot volatility on an unchanged balance sheet must not move the rating more than two notches'
        );
    }

    /**
     * Agencies move one notch at a time because a market-implied score is noisier than the credit is. That
     * discipline was bypassed whenever the implied score alone pointed at CCC or below, which is exactly
     * when it was needed.
     */
    public function testACollapsedMarketScoreDowngradesOneNotchAtATimeWhileTheAccountsAreSound(): void
    {
        $agency = new CreditRatingAgency();

        $stock = new Stock();
        $stock->setTicker('NOTCH');
        $stock->setCreditRating('BBB');
        $stock->setTotalEquity('200000000000.00');

        // Distance to default floored, Altman score firmly in the Safe zone.
        $this->assertSame('BB', $agency->evaluateRating($stock, 0.05, 3.90));
        $this->assertSame('B', $agency->evaluateRating($stock, 0.05, 3.90));
        $this->assertSame('CCC', $agency->evaluateRating($stock, 0.05, 3.90));
    }

    /**
     * The clamp is not a blanket stay of execution: a firm whose accounts say distress still gets the
     * emergency downgrade, which is what the bypass existed for in the first place.
     */
    public function testAnAccountingInsolvencyStillDowngradesImmediately(): void
    {
        $agency = new CreditRatingAgency();

        $stock = new Stock();
        $stock->setTicker('GONE');
        $stock->setCreditRating('BBB');
        $stock->setTotalEquity('-1000000.00');

        $this->assertSame('D', $agency->evaluateRating($stock, 0.05, -0.50));
    }

    /**
     * Only cash above the operating floor can be handed to a bondholder. Sweeping the treasury to zero to
     * repay principal destroyed the going concern and removed the means to cure the default.
     */
    public function testMaturityRepaymentLeavesTheOperatingCashFloorIntact(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), null, null);

        $stock = new Stock();
        $stock->setTicker('FLOOR');
        $stock->setIndustry('Auto Manufacturers');
        $stock->setWholesaleDebt('10000000000.00');
        $stock->setTotalEquity('20000000000.00');
        $stock->setCreditRating('CCC');
        $stock->setCreditSpread('0.02');

        // $500M comes due (5% of a $10B book). The firm holds $4.3B against a $4.0B operating floor, so
        // only $300M of that is spendable and the balance is a shortfall rather than a raided treasury.
        $health = $this->closedMarketHealth();
        $roll = $engine->rollMaturities($stock, $health, 4_300_000_000.0, 4_000_000_000.0);

        $this->assertFalse($roll->refinanced);
        $this->assertEqualsWithDelta(500_000_000.0, $roll->maturingPrincipal, 1.0);
        $this->assertEqualsWithDelta(
            300_000_000.0,
            $roll->principalRepaid,
            1.0,
            'principal may only be paid out of cash above the operating floor'
        );
        $this->assertEqualsWithDelta(200_000_000.0, $roll->unfundedShortfall, 1.0);
    }

    /**
     * Without a floor the same repayment takes every last dollar, which is the behaviour being replaced.
     */
    public function testMaturityRepaymentWithoutAFloorStillSweepsAvailableCash(): void
    {
        $engine = new DebtEngine(new MathUtility(), new CorporateMetrics(), null, null);

        $stock = new Stock();
        $stock->setTicker('SWEEP');
        $stock->setIndustry('Auto Manufacturers');
        $stock->setWholesaleDebt('10000000000.00');
        $stock->setTotalEquity('20000000000.00');
        $stock->setCreditRating('CCC');
        $stock->setCreditSpread('0.02');

        $roll = $engine->rollMaturities($stock, $this->closedMarketHealth(), 300_000_000.0);

        $this->assertEqualsWithDelta(300_000_000.0, $roll->principalRepaid, 1.0, 'with no floor the last dollar still goes');
        $this->assertEqualsWithDelta(200_000_000.0, $roll->unfundedShortfall, 1.0);
    }

    /**
     * Cash is an asset. A treasury driven below zero by an operating burn put a liability in the asset
     * column, where it flowed on into Altman's working capital term, net asset value and every screener
     * built on the balance. The overdraft is a revolver draw, which is what a revolver is for.
     */
    public function testAnOperatingBurnDrawsTheRevolverRatherThanGoingCashNegative(): void
    {
        $stock = $this->buildBurningCorporate();

        $debtEngineMock = $this->createMock(DebtEngine::class);
        $debtEngineMock->method('rollMaturities')->willReturn(new MaturityRollDTO());
        $debtEngineMock->method('issueDebt')->willReturnCallback(
            static function (Stock $target, float $amount, float $rate): void {
                $target->setWholesaleDebt((string) (((float) $target->getWholesaleDebt()) + $amount));
            }
        );

        $engine = new TreasuryEngine(
            $this->createStub(CorporateMetrics::class),
            $debtEngineMock,
            $this->createStub(CapExEngine::class),
            new MathUtility()
        );

        $ctx = $this->burningContext($stock);
        $ctx->newTreasury = -5_000_000_000.0; // the quarter burned more cash than it had, within the facility

        $engine->finalizeLiquidity($ctx);

        $this->assertGreaterThanOrEqual(
            0.0,
            (float) $stock->getCorporateTreasury(),
            'corporate cash must never be reported as a negative balance'
        );
        $this->assertGreaterThan(
            20_000_000_000.0,
            (float) $stock->getWholesaleDebt(),
            'the overdraft must appear on the liability side, not vanish'
        );
    }

    /**
     * A draw past the committed facility is still funded - the cash was already spent - but the firm is
     * out of liquidity, and must be flagged into the financing path the model already runs on that state
     * rather than silently carrying on.
     */
    public function testADrawBeyondTheCommitmentFlagsTheFirmAsOutOfLiquidity(): void
    {
        $stock = $this->buildBurningCorporate();

        $debtEngineMock = $this->createMock(DebtEngine::class);
        $debtEngineMock->method('rollMaturities')->willReturn(new MaturityRollDTO());
        $debtEngineMock->method('issueDebt')->willReturnCallback(
            static function (Stock $target, float $amount, float $rate): void {
                $target->setWholesaleDebt((string) (((float) $target->getWholesaleDebt()) + $amount));
            }
        );

        $engine = new TreasuryEngine(
            $this->createStub(CorporateMetrics::class),
            $debtEngineMock,
            $this->createStub(CapExEngine::class),
            new MathUtility()
        );

        // Price zero, so no equity raise can rescue the quarter and reset the flag.
        $ctx = $this->burningContext($stock, currentPrice: 0.0);
        // Far past the facility, which is five times the $1.2B minimum operating cash this firm carries.
        $ctx->newTreasury = -30_000_000_000.0;

        $engine->finalizeLiquidity($ctx);

        $this->assertGreaterThanOrEqual(0.0, (float) $stock->getCorporateTreasury());
        $this->assertEqualsWithDelta(
            50_000_000_000.0,
            (float) $stock->getWholesaleDebt(),
            1.0,
            'the whole overdraft is funded, within the commitment and beyond it'
        );
        $this->assertTrue($ctx->failedEmergencyBorrow, 'a draw past the commitment is a liquidity failure');
    }

    private function buildHealthyCyclicalProducer(): Stock
    {
        $stock = new Stock();
        $stock->setTicker('CYCL');
        $stock->setIndustry('Oil & Gas E&P');
        $stock->setCreditRating('BBB');
        $stock->setVolatility('0.35');
        $stock->setSharesOutstanding('1000000000');
        $stock->setPrice('210.00');
        $stock->setTotalEquity('200000000000.00');
        $stock->setRetainedEarnings('120000000000.00');
        $stock->setWholesaleDebt('138000000000.00');
        $stock->setCorporateTreasury('20000000000.00');
        $stock->setCreditSpread('0.028');
        $stock->setHistoricalFixedRate('0.06');
        $stock->setFloatingDebtRatio('0.20');
        $stock->setDepreciationRate('0.12');

        return $stock;
    }

    private function buildBurningCorporate(): Stock
    {
        $stock = new Stock();
        $stock->setTicker('BURN');
        $stock->setIndustry('Auto Manufacturers');
        $stock->setTotalEquity('50000000000.00');
        $stock->setRetainedEarnings('10000000000.00');
        $stock->setWholesaleDebt('20000000000.00');
        $stock->setCorporateTreasury('0.00');
        $stock->setTotalRevenue('40000000000.00');
        $stock->setCreditSpread('0.03');

        return $stock;
    }

    private function burningContext(Stock $stock, float $currentPrice = 10.0): CapitalAllocationContext
    {
        $ctx = new CapitalAllocationContext(
            stock: $stock,
            macroState: MacroStateDTO::fromArray(['policy_rate_ema' => 0.04, 'yield_5y_ema' => 0.045]),
            actualAnnualEps: -1.0,
            quarterlyFcfPerShare: -1.0,
            currentPrice: $currentPrice,
            sharesOutstanding: 1_000_000_000,
            actualTotalNetIncome: -2_000_000_000.0,
            stockCompensation: 0.0
        );

        $ctx->strategy = new StandardCorporateBusinessModel();
        $ctx->businessModel = 'auto';
        $ctx->quarterlyNetIncome = -2_000_000_000.0;
        $ctx->operatingBase = 40_000_000_000.0;
        $ctx->wholesaleDebt = 20_000_000_000.0;
        $ctx->customerDeposits = 0.0;
        $ctx->totalPaid = 0.0;
        $ctx->totalCashSpent = 0.0;
        $ctx->debtActionTaken = true;
        $ctx->health = $this->closedMarketHealth();

        return $ctx;
    }

    private function closedMarketHealth(): DebtHealthDTO
    {
        $metrics = new DebtMetricsDTO(
            interestExpense: 500_000_000.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.05,
            dynamicSpread: 0.02,
            currentMarketRate: 0.07,
            wholesaleRate: 0.05,
            ebit: -1_000_000_000.0,
            revenue: 40_000_000_000.0,
            depreciation: 500_000_000.0,
            ebitda: -500_000_000.0
        );

        return new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.05,
            cashYield: 0.03,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 0.4,
            wantsToPaydownDebt: false,
            canIssueDebt: false,
            debtTolerance: 2.0,
            wacc: 0.08,
            costOfEquity: 0.10,
            leveredBeta: 1.0,
            rawMetrics: $metrics,
            isLiquidityCrisis: true,
            isLiquidityWarning: true,
            isUnderLeveraged: false
        );
    }

    private function neutralMacro(): MacroStateDTO
    {
        return MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.04,
            'yield_5y_ema' => 0.045,
            'macro_credit_spread_ema' => 0.015,
            'corporate_tax_rate' => 0.21,
        ]);
    }
}
