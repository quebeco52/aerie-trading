<?php

declare(strict_types=1);

namespace App\Tests\Meta;

use App\Service\Math\MathUtility;
use App\Service\Math\StochasticProcesses;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class ScriptedDrawsTest extends TestCase
{
    /** MathUtility's public instance methods that are not draws. */
    private const NOT_DRAWS = ['__construct', 'beginDrawLog', 'endDrawLog'];

    /**
     * A draw method MathUtility gains must be scripted here too, or a test double would hand the model real draws.
     * The final jumping log-OU step cannot be overridden; it draws through the normal and the jump step.
     */
    public function testEveryOverridableDrawMethodIsScripted(): void
    {
        $unscripted = [];
        foreach ((new \ReflectionClass(MathUtility::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || in_array($method->getName(), self::NOT_DRAWS, true)) {
                continue;
            }
            if ($method->isFinal()) {
                $this->assertSame('stepJumpingLogOu', $method->getName(), 'A new final draw method cannot be scripted.');

                continue;
            }
            if ((new \ReflectionMethod(ScriptedDraws::class, $method->getName()))->getDeclaringClass()->getName() !== ScriptedDraws::class) {
                $unscripted[] = $method->getName();
            }
        }

        $this->assertSame([], $unscripted);
    }

    public function testUnscriptedPrimitivesAnswerWhatACreateStubDoes(): void
    {
        $draws = new ScriptedDraws();

        $this->assertSame(
            [0.0, 0.0, 0, false, 0.0, 0.0, 0, 0, 0.0],
            [
                $draws->generateUniform(),
                $draws->generateUniformBetween(2.0, 3.0),
                $draws->generateIndex(5),
                $draws->checkProbability(1.0),
                $draws->generateStandardNormal(),
                $draws->generateExponential(2.0),
                $draws->generateGeometricCount(0.5),
                $draws->generatePoissonCount(3.0),
                $draws->generateParetoSeverity(1.0, 1.5, 10.0),
            ],
        );
    }

    public function testUnscriptedStepsRunTheirOwnFormulaWithoutNoise(): void
    {
        $draws = new ScriptedDraws();

        $this->assertEqualsWithDelta(0.4, $draws->generatePersistentZ(0.5, 0.8), 1e-12);
        $this->assertSame(0.0, $draws->generateStudentsT());
        $this->assertEqualsWithDelta(exp(0.1), $draws->calculateLogNormalSynergy(0.1, 0.3), 1e-12);
        $this->assertSame(['multiplier' => 1.0, 'shock_pct' => null, 'exponent' => null], $draws->calculateJumpDiffusion(5.0, -0.1, 0.2, 0.25));
        $this->assertSame(1.0, $draws->calculateSVJJJumps(5.0, 0.3, 10.0, 5.0, 0.01, 0.25)['price_multiplier']);
        $this->assertEqualsWithDelta(
            -StochasticProcesses::calculateKouCompensator(1.0, 0.2, 20.0, 10.0, 0.5, 0.25),
            $draws->calculateCompensatedKouJump(1.0, 0.2, 20.0, 10.0, 0.5, 0.25),
            1e-15,
        );
        $this->assertSame(
            StochasticProcesses::calculateSchwartz1Factor(1.0, 0.5, 1.0, 0.2, 0.25, 0.0),
            $draws->stepJumpingLogOu(1.0, 0.5, 1.0, 0.2, 5.0, -0.1, 0.2, 0.25)['level'],
        );
    }

    public function testAListAnswersInOrderThenTheUnscriptedValue(): void
    {
        $draws = new ScriptedDraws(generateStandardNormal: [1.5, -2.0], checkProbability: [true]);

        $this->assertSame([1.5, -2.0, 0.0], [$draws->generateStandardNormal(), $draws->generateStandardNormal(), $draws->generateStandardNormal()]);
        $this->assertSame([true, false], [$draws->checkProbability(0.0), $draws->checkProbability(1.0)]);
    }

    public function testAValueAnswersEveryCallAndAClosureAnswersFromTheArguments(): void
    {
        $draws = new ScriptedDraws(
            generateUniform: 0.75,
            generatePersistentZ: static fn(float $previousZ, float $phi): float => $previousZ === -9.9 ? 2.5 : $phi,
            calculateJumpDiffusion: ['multiplier' => 1.25, 'shock_pct' => 25.0, 'exponent' => 0.223],
        );

        $this->assertSame([0.75, 0.75], [$draws->generateUniform(), $draws->generateUniform()]);
        $this->assertSame([2.5, 0.6], [$draws->generatePersistentZ(-9.9, 0.6), $draws->generatePersistentZ(0.0, 0.6)]);
        $this->assertSame(1.25, $draws->calculateJumpDiffusion(1.0, 0.0, 0.1, 0.25)['multiplier']);
    }

    /** A step left to run itself takes its innovation from the scripted normals, firm factor first. */
    public function testAnUnscriptedStepDrawsTheScriptedNormals(): void
    {
        $draws = new ScriptedDraws(generateStandardNormal: [1.0]);

        $z = $draws->generatePersistentZ(0.0, 0.6);

        $this->assertEqualsWithDelta(StochasticProcesses::persistentInnovationScale(0.6), $z, 1e-12);
        $this->assertSame(1, $draws->callCount('generatePersistentZ'));
        $this->assertSame(2, $draws->callCount());
    }

    public function testScriptReplacesAMethodsScriptAndRejectsAnUnknownName(): void
    {
        $draws = new ScriptedDraws(generateUniform: 0.25);
        $draws->script(generateUniform: [0.5]);

        $this->assertSame([0.5, 0.0], [$draws->generateUniform(), $draws->generateUniform()]);
        $this->expectException(\InvalidArgumentException::class);
        $draws->script(stepJumpingLogOu: 1.0);
    }
}
