<?php

declare(strict_types=1);

namespace App\DTO;

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
     * Retrieves the map of all generated stream Z-scores to pass into SectorPhysicsResult.
     *
     * @return array<string, float>
     */
    public function getStreamZ(): array
    {
        return $this->nextZ;
    }
}
