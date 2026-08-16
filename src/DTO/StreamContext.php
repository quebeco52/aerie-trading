<?php

declare(strict_types=1);

namespace App\DTO;

use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;

/**
 * Encapsulates the generation, registration, and persistence of autoregressive AR(1) stream Z-scores.
 * Enforces single-point-of-definition to prevent key mismatches and mathematical state pollution.
 */
class StreamContext
{
    /** @var array<string, float> */
    private array $nextZ = [];

    /**
     * @param array<string, float> $previousMomentum Map of previous quarter stream Z-scores ($stock->getEarningsMomentumZ())
     * @param MathUtility $mathUtility
     */
    public function __construct(
        private readonly array $previousMomentum,
        private readonly MathUtility $mathUtility
    ) {}

    /**
     * Generates a stationary AR(1) Z-score for the given stream key and registers it for persistence.
     *
     * @param string $key The unique identifier for this revenue stream (e.g., 'government_contracts').
     * @param float $phi  Autoregressive persistence parameter (0 = i.i.d., 1 = random walk).
     * @return float The newly generated stationary Z-score ~ N(0, 1).
     */
    public function generateZ(string $key, float $phi): float
    {
        $prevZ = $this->previousMomentum[$key] ?? 0.0;
        $newZ = $this->mathUtility->generatePersistentZ($prevZ, $phi);
        $this->nextZ[$key] = $newZ;

        return $newZ;
    }

    /**
     * Registers an explicit or composite Z-score directly into the stream state.
     *
     * @param string $key The unique identifier for this revenue stream.
     * @param float $z    The Z-score to register.
     */
    public function registerZ(string $key, float $z): void
    {
        $this->nextZ[$key] = $z;
    }

    /**
     * Evolve and resolve active mean-reverting revenue stream weights for this quarter.
     *
     * @param array<string, float> $targetWeights Map of stream keys to strategic target weights
     * @param float $adaptationRate Adaptation speed scalar (alpha)
     * @param float $reversionSpeed Mean reversion speed scalar (kappa)
     * @param float $minFloor       Minimum structural floor clamp per stream
     * @param float $maxCeiling     Maximum structural ceiling clamp per stream
     * @return array<string, float> Simplex-normalized active weights for this quarter
     */
    public function resolveActiveStreamWeights(
        array $targetWeights,
        float $adaptationRate = FinancialConstants::DEFAULT_MIX_ADAPTATION_RATE,
        float $reversionSpeed = FinancialConstants::DEFAULT_MIX_REVERSION_SPEED,
        float $minFloor = FinancialConstants::DEFAULT_MIN_STREAM_WEIGHT_FLOOR,
        float $maxCeiling = FinancialConstants::DEFAULT_MAX_STREAM_WEIGHT_CEILING
    ): array {
        $normalizedTargets = $this->mathUtility->normalizeWeightsSimplex($targetWeights);
        if (empty($normalizedTargets)) {
            $total = array_sum($targetWeights);
            if ($total > 0.0) {
                foreach ($targetWeights as $k => $w) {
                    $normalizedTargets[$k] = $w / $total;
                }
            } else {
                $count = max(1, count($targetWeights));
                foreach ($targetWeights as $k => $w) {
                    $normalizedTargets[$k] = 1.0 / $count;
                }
            }
        }

        $hasPreviousShares = true;
        foreach (array_keys($normalizedTargets) as $key) {
            if (!isset($this->previousMomentum["share:{$key}"])) {
                $hasPreviousShares = false;
                break;
            }
        }

        if ($hasPreviousShares) {
            $rawWeights = [];
            foreach ($normalizedTargets as $key => $targetWeight) {
                $prevWeight = (float) ($this->previousMomentum["weight:{$key}"] ?? $targetWeight);
                $prevShare  = (float) $this->previousMomentum["share:{$key}"];

                $raw = $this->mathUtility->calculateMeanRevertingWeight(
                    $prevWeight,
                    $prevShare,
                    $targetWeight,
                    $adaptationRate,
                    $reversionSpeed,
                    $minFloor,
                    $maxCeiling
                );
                $rawWeights[$key] = $raw;
            }
            $activeWeights = $this->mathUtility->normalizeWeightsSimplex($rawWeights) ?: $rawWeights;
        } else {
            $activeWeights = [];
            foreach ($normalizedTargets as $key => $targetWeight) {
                $activeWeights[$key] = (float) ($this->previousMomentum["weight:{$key}"] ?? $targetWeight);
            }
            $activeWeights = $this->mathUtility->normalizeWeightsSimplex($activeWeights) ?: $activeWeights;
        }

        foreach ($activeWeights as $key => $weight) {
            $this->registerZ("weight:{$key}", $weight);
        }

        return $activeWeights;
    }

    /**
     * Calculates and registers realized revenue shares for multi-quarter mix persistence.
     *
     * @param array<string, float> $streamRevenues Map of stream keys to recognized dollar revenues
     */
    public function recordStreamShares(array $streamRevenues): void
    {
        $totalRevenue = array_sum($streamRevenues);

        foreach ($streamRevenues as $key => $revenue) {
            $share = $totalRevenue > 0.0 ? ($revenue / $totalRevenue) : (1.0 / max(1, count($streamRevenues)));
            $this->registerZ("share:{$key}", $share);
        }
    }

    /**
     * Retrieves the map of all generated stream Z-scores to pass into SectorPhysicsResult.
     *
     * @return array<string, float>
     */
    public function getStreamZ(): array
    {
        return $this->nextZ;
    }
}
