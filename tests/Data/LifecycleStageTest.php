<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\LifecycleStage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LifecycleStageTest extends TestCase
{
    /** @return array<string, array{bool, bool, bool, LifecycleStage}> Dickinson (2011) Table 1 */
    public static function cashFlowPatternProvider(): array
    {
        return [
            'introduction (- - +)' => [false, false, true, LifecycleStage::Introduction],
            'growth (+ - +)'       => [true, false, true, LifecycleStage::Growth],
            'mature (+ - -)'       => [true, false, false, LifecycleStage::Mature],
            'shake-out (- - -)'    => [false, false, false, LifecycleStage::ShakeOut],
            'shake-out (+ + +)'    => [true, true, true, LifecycleStage::ShakeOut],
            'shake-out (+ + -)'    => [true, true, false, LifecycleStage::ShakeOut],
            'decline (- + +)'      => [false, true, true, LifecycleStage::Decline],
            'decline (- + -)'      => [false, true, false, LifecycleStage::Decline],
        ];
    }

    #[DataProvider('cashFlowPatternProvider')]
    public function testDickinsonCashFlowPatternsMapToStages(bool $cfo, bool $cfi, bool $cff, LifecycleStage $expected): void
    {
        $this->assertSame($expected, LifecycleStage::fromCashFlowSigns($cfo, $cfi, $cff));
    }

    public function testOnlyIntroductionStageWithholdsDistributions(): void
    {
        foreach (LifecycleStage::cases() as $stage) {
            $this->assertSame($stage !== LifecycleStage::Introduction, $stage->initiatesDistributions(), $stage->value);
        }
    }

    public function testEveryStageCarriesDisplayCopy(): void
    {
        foreach (LifecycleStage::cases() as $stage) {
            $this->assertNotSame('', $stage->label(), $stage->value);
            $this->assertNotSame('', $stage->description(), $stage->value);
            $this->assertMatchesRegularExpression('/^[+−±] \/ [+−±] \/ [+−±]/u', $stage->cashFlowSignature(), $stage->value);
        }
    }

    /**
     * The displayed sign pattern must round-trip through the classifier, so the UI can never
     * show a signature that the engine would file under a different stage.
     */
    public function testSinglePatternSignaturesRoundTripThroughTheClassifier(): void
    {
        foreach (LifecycleStage::cases() as $stage) {
            if ($stage === LifecycleStage::ShakeOut) {
                continue; // Shake-out aggregates three patterns; its signature lists them rather than one triple.
            }

            $signs = explode(' / ', $stage->cashFlowSignature());
            $this->assertCount(3, $signs, $stage->value);

            $choices = array_map(static fn (string $sign): array => $sign === '±' ? [true, false] : [$sign === '+'], $signs);
            foreach ($choices[0] as $cfo) {
                foreach ($choices[1] as $cfi) {
                    foreach ($choices[2] as $cff) {
                        $this->assertSame($stage, LifecycleStage::fromCashFlowSigns($cfo, $cfi, $cff), $stage->cashFlowSignature());
                    }
                }
            }
        }
    }

    public function testSheddingStagesAreDeclineAndShakeOut(): void
    {
        $this->assertTrue(LifecycleStage::Decline->isShedding());
        $this->assertTrue(LifecycleStage::ShakeOut->isShedding());
        $this->assertFalse(LifecycleStage::Mature->isShedding());
        $this->assertFalse(LifecycleStage::Growth->isShedding());
    }
}
