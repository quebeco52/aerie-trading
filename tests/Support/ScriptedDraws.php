<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Math\MathUtility;

/**
 * A MathUtility that never draws: every draw method answers from a script, so a test fixes the path a model takes.
 *
 * Script a method by its name, in the constructor or script(): a value answers every call, a list the next calls in
 * order, a closure each call from its arguments. Unscripted, a primitive answers what createStub(MathUtility::class)
 * does: 0.0 for uniforms, normals and the uniform-driven sizes, false for checkProbability, 0 for the counts. The
 * steps built on normals (persistent Z, Student-t, lognormal synergy, the jump steps, QE variance, two-factor OU) run
 * MathUtility's own formula over the scripted primitives, so zero normals and no events give the noiseless step.
 *
 * A call past the end of its list answers as unscripted. No call reaches a random generator: every draw method is
 * overridden, ScriptedDrawsTest fails when MathUtility gains one this class does not cover, and scripting a name it
 * does not know throws. callCount() counts the calls a method took, those a step makes to its primitives included.
 */
final class ScriptedDraws extends MathUtility
{
    /** Unscripted answer per draw method; null runs MathUtility's own step over the scripted primitives. */
    private const UNSCRIPTED = [
        'generateUniform' => 0.0,
        'generateUniformBetween' => 0.0,
        'generateIndex' => 0,
        'checkProbability' => false,
        'generateStandardNormal' => 0.0,
        'generateExponential' => 0.0,
        'generateGeometricCount' => 0,
        'generatePoissonCount' => 0,
        'generateParetoSeverity' => 0.0,
        'generatePersistentZ' => null,
        'generateStudentsT' => null,
        'calculateLogNormalSynergy' => null,
        'calculateJumpDiffusion' => null,
        'calculateSVJJJumps' => null,
        'calculateCompensatedKouJump' => null,
        'calculateQEVarianceStep' => null,
        'calculateTwoFactorOU' => null,
    ];

    /** @var array<string, mixed> Each method's answer outside its list: a value, a closure, or null for a step. */
    private array $constants = self::UNSCRIPTED;

    /** @var array<string, list<mixed>> Answers still waiting, front first. */
    private array $queues = [];

    /** @var array<string, int> */
    private array $calls = [];

    /** Named arguments script the draw methods of the same name; see script(). */
    public function __construct(mixed ...$scripts)
    {
        parent::__construct();
        $this->script(...$scripts);
    }

    /**
     * Scripts each named draw method, replacing what it had: a value answers every call, a list the next calls in
     * order, a closure each call from its arguments, and null leaves the method unscripted.
     */
    public function script(mixed ...$scripts): self
    {
        foreach ($scripts as $method => $script) {
            if (!array_key_exists($method, self::UNSCRIPTED)) {
                throw new \InvalidArgumentException("ScriptedDraws cannot script {$method}().");
            }
            $isList = is_array($script) && array_is_list($script);
            $this->queues[$method] = $isList ? $script : [];
            // A result array (string keys) is a single answer, not a list.
            $this->constants[$method] = $isList || $script === null ? self::UNSCRIPTED[$method] : $script;
        }

        return $this;
    }

    /** Calls the named draw method has taken, or every draw method together when no name is given. */
    public function callCount(?string $method = null): int
    {
        return $method === null ? array_sum($this->calls) : ($this->calls[$method] ?? 0);
    }

    public function generateUniform(): float
    {
        return $this->answer(__FUNCTION__, []);
    }

    public function generateUniformBetween(float $min, float $max): float
    {
        return $this->answer(__FUNCTION__, [$min, $max]);
    }

    public function generateIndex(int $count): int
    {
        return $this->answer(__FUNCTION__, [$count]);
    }

    public function checkProbability(float $probability): bool
    {
        return $this->answer(__FUNCTION__, [$probability]);
    }

    public function generateStandardNormal(): float
    {
        return $this->answer(__FUNCTION__, []);
    }

    public function generateExponential(float $rate = 1.0): float
    {
        return $this->answer(__FUNCTION__, [$rate]);
    }

    public function generateGeometricCount(float $exitProbability): int
    {
        return $this->answer(__FUNCTION__, [$exitProbability]);
    }

    public function generatePoissonCount(float $mean): int
    {
        return $this->answer(__FUNCTION__, [$mean]);
    }

    public function generateParetoSeverity(float $scale, float $alpha, float $cap): float
    {
        return $this->answer(__FUNCTION__, [$scale, $alpha, $cap]);
    }

    public function generatePersistentZ(float $previousZ, float $phi, ?float $commonInnovation = null, float $commonLoading = 0.0): float
    {
        return $this->answer(__FUNCTION__, [$previousZ, $phi, $commonInnovation, $commonLoading])
            ?? parent::generatePersistentZ($previousZ, $phi, $commonInnovation, $commonLoading);
    }

    public function generateStudentsT(int $df = 4): float
    {
        return $this->answer(__FUNCTION__, [$df]) ?? parent::generateStudentsT($df);
    }

    public function calculateLogNormalSynergy(float $mu, float $sigma): float
    {
        return $this->answer(__FUNCTION__, [$mu, $sigma]) ?? parent::calculateLogNormalSynergy($mu, $sigma);
    }

    public function calculateJumpDiffusion(float $lambda, float $jumpMean, float $jumpVol, float $dt): array
    {
        return $this->answer(__FUNCTION__, [$lambda, $jumpMean, $jumpVol, $dt])
            ?? parent::calculateJumpDiffusion($lambda, $jumpMean, $jumpVol, $dt);
    }

    public function calculateSVJJJumps(float $lambda, float $pUp, float $etaUp, float $etaDown, float $muV, float $dt): array
    {
        return $this->answer(__FUNCTION__, [$lambda, $pUp, $etaUp, $etaDown, $muV, $dt])
            ?? parent::calculateSVJJJumps($lambda, $pUp, $etaUp, $etaDown, $muV, $dt);
    }

    public function calculateCompensatedKouJump(float $lambda, float $pUp, float $etaUp, float $etaDown, float $cap, float $dt): float
    {
        return $this->answer(__FUNCTION__, [$lambda, $pUp, $etaUp, $etaDown, $cap, $dt])
            ?? parent::calculateCompensatedKouJump($lambda, $pUp, $etaUp, $etaDown, $cap, $dt);
    }

    public function calculateQEVarianceStep(float $currentVar, float $theta, float $kappa, float $sigma, float $dt, ?float $varianceShock = null): float
    {
        return $this->answer(__FUNCTION__, [$currentVar, $theta, $kappa, $sigma, $dt, $varianceShock])
            ?? parent::calculateQEVarianceStep($currentVar, $theta, $kappa, $sigma, $dt, $varianceShock);
    }

    public function calculateTwoFactorOU(
        float $chi,
        float $xi,
        float $kappaChi,
        float $kappaXi,
        float $thetaChi,
        float $thetaXi,
        float $sigChi,
        float $sigXi,
        float $rho,
        float $dt,
        ?float $dW1 = null,
        ?float $dW2 = null,
    ): array {
        $arguments = [$chi, $xi, $kappaChi, $kappaXi, $thetaChi, $thetaXi, $sigChi, $sigXi, $rho, $dt, $dW1, $dW2];

        return $this->answer(__FUNCTION__, $arguments) ?? parent::calculateTwoFactorOU(...$arguments);
    }

    /**
     * The scripted answer to this call: the next queued one, else the constant, else null for a step to run itself.
     *
     * @param list<mixed> $arguments
     */
    private function answer(string $method, array $arguments): mixed
    {
        $this->calls[$method] = ($this->calls[$method] ?? 0) + 1;
        if (($this->queues[$method] ?? []) !== []) {
            return array_shift($this->queues[$method]);
        }
        $constant = $this->constants[$method];

        return $constant instanceof \Closure ? $constant(...$arguments) : $constant;
    }
}
