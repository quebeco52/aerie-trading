<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Data\Company\ManagementProfile;
use App\Data\Company\ManagementStyle;
use App\DTO\DebtHealthDTO;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\Deal\AcquisitionEngine;
use App\Service\Corporate\Deal\DivestitureEngine;
use App\Service\Corporate\DebtEngine;
use App\Service\Math\MathUtility;
use App\Service\News\MarketEventPublisher;
use App\Tests\Support\DebtHealthBuilder;
use App\Tests\Support\MacroStateBuilder;
use App\Tests\Support\ScriptedDraws;
use App\Tests\Support\StockBuilder;
use PHPUnit\Framework\Attributes\Group;
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
#[Group('unit')]
class MergerAndAcquisitionGateTest extends TestCase
{
    private ScriptedDraws $math;
    private DebtEngine&Stub $debt;
    private AcquisitionEngine $engine;

    protected function setUp(): void
    {
        $this->math = new ScriptedDraws(calculateLogNormalSynergy: 0.0);
        $this->debt = $this->createStub(DebtEngine::class);
        $this->engine = $this->engine($this->debt, $this->math);
    }

    private function engine(DebtEngine $debt, MathUtility $math): AcquisitionEngine
    {
        return new AcquisitionEngine(
            $this->createStub(MarketEventPublisher::class),
            $debt,
            $math,
            new CorporateMetrics(),
        );
    }

    private function divestitureEngine(DebtEngine $debt, MathUtility $math): DivestitureEngine
    {
        return new DivestitureEngine(
            $this->createStub(MarketEventPublisher::class),
            $debt,
            $math,
            new CorporateMetrics(),
        );
    }

    public function testTheAcquisitionCeilingDominatesEveryBranchUnderTheMostAcquisitiveManager(): void
    {
        $ceiling = AcquisitionEngine::acquisitionHazardCeiling();

        foreach (ManagementStyle::cases() as $style) {
            $bias = ManagementProfile::forStyle($style, ManagementProfile::MAX_INTENSITY)->acquisitionBias();

            $branches = [
                AcquisitionEngine::MA_EMPIRE_BUILDER_PROB,
                AcquisitionEngine::MA_OVERVALUED_PROB * $bias,
                AcquisitionEngine::MA_MEGA_HOARDER_PROB * $bias,
                AcquisitionEngine::MA_HOARDER_PROB * $bias,
                AcquisitionEngine::MA_LOW_LEVERAGE_PROB * $bias,
                AcquisitionEngine::MA_MOD_LEVERAGE_PROB * $bias,
            ];

            // A primary branch and the cash fallback can both be live, so the union of the two must fit.
            foreach ($branches as $primary) {
                $fallback = AcquisitionEngine::MA_CASH_FALLBACK_PROB * $bias;
                $this->assertGreaterThanOrEqual($primary + $fallback, $ceiling, $style->name);
            }
        }
    }

    public function testTheDivestitureCeilingDominatesEveryState(): void
    {
        $ceiling = DivestitureEngine::divestitureHazardCeiling();

        $this->assertGreaterThanOrEqual(DivestitureEngine::DIV_DYING_ANNUAL_PROB, $ceiling);
        $this->assertGreaterThanOrEqual(DivestitureEngine::DIV_DISTRESSED_ANNUAL_PROB, $ceiling);
        $this->assertGreaterThanOrEqual(DivestitureEngine::DIV_PREMIUM_ANNUAL_PROB, $ceiling);
    }

    public function testARejectedGateDoesNoneOfTheExpensiveWork(): void
    {
        $dt = 1.0 / 14400.0;
        $math = new ScriptedDraws(checkProbability: function (float $hazard) use ($dt): bool {
            // Struck at the ceiling, not at some smaller hazard the gate could not justify.
            $this->assertContains(
                round($hazard, 12),
                [
                    round(AcquisitionEngine::acquisitionHazardCeiling() * $dt, 12),
                    round(DivestitureEngine::divestitureHazardCeiling() * $dt, 12),
                ],
            );

            return false;
        });
        $debt = $this->createMock(DebtEngine::class);
        $engine = $this->engine($debt, $math);
        $divestitures = $this->divestitureEngine($debt, $math);

        $debt->expects($this->never())->method('analyzeDebtHealth');
        $debt->expects($this->never())->method('analyzeTrailingDebtHealth');

        $stock = StockBuilder::create('GATE')->build();
        $macro = \App\DTO\MacroStateDTO::fromMacroState(MacroStateBuilder::create()->build());

        $this->assertNull($engine->evaluatePrivateAcquisition($stock, $macro, $dt));
        $this->assertNull($divestitures->evaluateCorporateDivestiture($stock, $macro, $dt));
        $this->assertSame(2, $math->callCount(), 'the two gate checks are the only draws');
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
        $this->debt->method('analyzeTrailingDebtHealth')->willReturn($this->healthyBalanceSheet());
        $this->math->script(checkProbability: true);

        $macro = \App\DTO\MacroStateDTO::fromMacroState(MacroStateBuilder::create()->build());
        $ceiling = AcquisitionEngine::acquisitionHazardCeiling();
        $acceptance = AcquisitionEngine::MA_EMPIRE_BUILDER_PROB / $ceiling;

        // Just inside the acceptance band: a deal.
        $this->math->script(generateUniform: $acceptance * 0.999, generateUniformBetween: 0.8);

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
        $this->debt->method('analyzeTrailingDebtHealth')->willReturn($this->healthyBalanceSheet());
        // The top of the conditional interval: above every hazard the ceiling admits.
        $this->math->script(checkProbability: true, generateUniform: 0.999999);

        $macro = \App\DTO\MacroStateDTO::fromMacroState(MacroStateBuilder::create()->build());

        $this->assertNull($this->engine->evaluatePrivateAcquisition($stock, $macro, $dt));
    }

    private function healthyBalanceSheet(): DebtHealthDTO
    {
        return DebtHealthBuilder::healthy()->withCoverage(8.0)->build();
    }
}
