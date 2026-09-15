<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Data\ManagementProfile;
use App\Data\ManagementStyle;
use App\DTO\DebtHealthDTO;
use App\DTO\DebtMetricsDTO;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\MergerAndAcquisitionEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;
use App\Tests\Support\MacroStateBuilder;
use App\Tests\Support\StockBuilder;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The rejection gate in front of the per-tick M&A evaluation.
 *
 * The evaluation was the single largest CPU item on the tick — a debt analysis, a fair-value multiple and
 * a hoarding test per firm per tick — to decide an event with a per-tick hazard of a few in ten thousand.
 * The gate makes the cheap draw first and does that work only when it passes. Two things have to hold for
 * that to be a pure optimisation rather than a model change: the ceiling must dominate every hazard the
 * logic can assign, and the two-stage draw must reproduce each hazard exactly.
 */
class MergerAndAcquisitionGateTest extends TestCase
{
    private MathUtility&Stub $math;
    private DebtEngine&Stub $debt;
    private MergerAndAcquisitionEngine $engine;

    protected function setUp(): void
    {
        $this->math = $this->createStub(MathUtility::class);
        $this->debt = $this->createStub(DebtEngine::class);
        $this->engine = $this->engine($this->debt, $this->math);
    }

    private function engine(DebtEngine $debt, MathUtility $math): MergerAndAcquisitionEngine
    {
        return new MergerAndAcquisitionEngine(
            $this->createStub(MarketEventPublisher::class),
            $debt,
            $math,
            new CorporateMetrics()
        );
    }

    public function testTheAcquisitionCeilingDominatesEveryBranchUnderTheMostAcquisitiveManager(): void
    {
        $ceiling = MergerAndAcquisitionEngine::acquisitionHazardCeiling();

        foreach (ManagementStyle::cases() as $style) {
            $bias = ManagementProfile::forStyle($style, ManagementProfile::MAX_INTENSITY)->acquisitionBias();

            $branches = [
                MergerAndAcquisitionEngine::MA_EMPIRE_BUILDER_PROB,
                MergerAndAcquisitionEngine::MA_OVERVALUED_PROB * $bias,
                MergerAndAcquisitionEngine::MA_MEGA_HOARDER_PROB * $bias,
                MergerAndAcquisitionEngine::MA_HOARDER_PROB * $bias,
                MergerAndAcquisitionEngine::MA_LOW_LEVERAGE_PROB * $bias,
                MergerAndAcquisitionEngine::MA_MOD_LEVERAGE_PROB * $bias,
            ];

            // A primary branch and the cash fallback can both be live, so the union of the two must fit.
            foreach ($branches as $primary) {
                $fallback = MergerAndAcquisitionEngine::MA_CASH_FALLBACK_PROB * $bias;
                $this->assertGreaterThanOrEqual($primary + $fallback, $ceiling, $style->name);
            }
        }
    }

    public function testTheDivestitureCeilingDominatesEveryState(): void
    {
        $ceiling = MergerAndAcquisitionEngine::divestitureHazardCeiling();

        $this->assertGreaterThanOrEqual(MergerAndAcquisitionEngine::DIV_DYING_ANNUAL_PROB, $ceiling);
        $this->assertGreaterThanOrEqual(MergerAndAcquisitionEngine::DIV_DISTRESSED_ANNUAL_PROB, $ceiling);
        $this->assertGreaterThanOrEqual(MergerAndAcquisitionEngine::DIV_PREMIUM_ANNUAL_PROB, $ceiling);
    }

    public function testARejectedGateDoesNoneOfTheExpensiveWork(): void
    {
        $dt = 1.0 / 14400.0;
        $math = $this->createMock(MathUtility::class);
        $debt = $this->createMock(DebtEngine::class);
        $engine = $this->engine($debt, $math);

        $math->expects($this->exactly(2))
            ->method('checkProbability')
            ->willReturnCallback(function (float $hazard) use ($dt): bool {
                // Struck at the ceiling, not at some smaller hazard the gate could not justify.
                $this->assertContains(
                    round($hazard, 12),
                    [
                        round(MergerAndAcquisitionEngine::acquisitionHazardCeiling() * $dt, 12),
                        round(MergerAndAcquisitionEngine::divestitureHazardCeiling() * $dt, 12),
                    ]
                );

                return false;
            });
        $debt->expects($this->never())->method('analyzeDebtHealth');
        $math->expects($this->never())->method('generateUniform');

        $stock = StockBuilder::create('GATE')->build();
        $macro = \App\DTO\MacroStateDTO::fromMacroState(MacroStateBuilder::create()->build());

        $this->assertNull($engine->evaluatePrivateAcquisition($stock, $macro, $dt));
        $this->assertNull($engine->evaluateCorporateDivestiture($stock, $macro, $dt));
    }

    public function testTheSecondStageAcceptsAtTheRatioOfTheFirmsHazardToTheCeiling(): void
    {
        // An empire builder with cash and headroom: the primary branch is MA_EMPIRE_BUILDER_PROB at bias
        // 1.0, so the second stage must accept exactly when the conditional draw lands below that hazard.
        $dt = 0.25;
        $stock = StockBuilder::create('EMPR')
            ->withCorporateTreasury(50_000_000_000.0)
            ->withTotalEquity(200_000_000_000.0)
            ->withWholesaleDebt(10_000_000_000.0)
            ->build();
        $stock->setManagementStyle(ManagementStyle::EmpireBuilder);
        $stock->setManagementIntensity(1.0);
        $stock->setIndustry('General');
        $stock->setEarningsPerShare('5.00');
        $stock->setTotalRevenue('100000000000.00');

        $this->debt->method('analyzeDebtHealth')->willReturn($this->healthyBalanceSheet());
        $this->math->method('checkProbability')->willReturn(true);

        $macro = \App\DTO\MacroStateDTO::fromMacroState(MacroStateBuilder::create()->build());
        $ceiling = MergerAndAcquisitionEngine::acquisitionHazardCeiling();
        $acceptance = MergerAndAcquisitionEngine::MA_EMPIRE_BUILDER_PROB / $ceiling;

        // Just inside the acceptance band: a deal.
        $this->math->method('generateUniform')->willReturn($acceptance * 0.999);
        $this->math->method('generateUniformBetween')->willReturn(0.8);
        $this->math->method('generateStandardNormal')->willReturn(0.0);

        $deal = $this->engine->evaluatePrivateAcquisition($stock, $macro, $dt);
        $this->assertNotNull($deal, 'A conditional draw below the branch hazard must fire the deal.');
    }

    public function testADrawAboveTheFirmsOwnHazardIsRejectedEvenThoughTheGatePassed(): void
    {
        $dt = 0.25;
        $stock = StockBuilder::create('EMPR')
            ->withCorporateTreasury(50_000_000_000.0)
            ->withTotalEquity(200_000_000_000.0)
            ->withWholesaleDebt(10_000_000_000.0)
            ->build();
        $stock->setManagementStyle(ManagementStyle::EmpireBuilder);
        $stock->setManagementIntensity(1.0);
        $stock->setIndustry('General');
        $stock->setEarningsPerShare('5.00');
        $stock->setTotalRevenue('100000000000.00');

        $this->debt->method('analyzeDebtHealth')->willReturn($this->healthyBalanceSheet());
        $this->math->method('checkProbability')->willReturn(true);
        // The top of the conditional interval: above every hazard the ceiling admits.
        $this->math->method('generateUniform')->willReturn(0.999999);

        $macro = \App\DTO\MacroStateDTO::fromMacroState(MacroStateBuilder::create()->build());

        $this->assertNull($this->engine->evaluatePrivateAcquisition($stock, $macro, $dt));
    }

    private function healthyBalanceSheet(): DebtHealthDTO
    {
        return new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.04,
            cashYield: 0.02,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 8.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 2.0,
            wacc: 0.08,
            costOfEquity: 0.10,
            leveredBeta: 1.0,
            rawMetrics: new DebtMetricsDTO(
                interestExpense: 0.0,
                blendedRate: 0.05,
                historicalFixedRate: 0.05,
                dynamicSpread: 0.01,
                currentMarketRate: 0.05,
                wholesaleRate: 0.05,
                ebit: 1000.0,
                revenue: 5000.0,
                depreciation: 100.0,
                ebitda: 1100.0
            ),
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: false,
            hasLeverageHeadroom: true
        );
    }
}
