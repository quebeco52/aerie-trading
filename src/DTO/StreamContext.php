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

    /** Firm-wide N(0,1) demand innovation shared by every loaded stream this quarter (drawn lazily). */
    private ?float $firmInnovation = null;

    /** Namespace prefix under which regime clocks are persisted in the stream state map. */
    public const REGIME_STATE_PREFIX = 'state:regime:';

    /** Namespace prefix under which order backlogs (in quarters of expected stream revenue) are persisted. */
    public const BACKLOG_STATE_PREFIX = 'state:backlog:';

    /** Ceiling on a persisted backlog, in quarters of expected stream revenue. */
    public const MAX_BACKLOG_QUARTERS = 8.0;

    /** @var array<string, int> Regime clocks resolved this quarter (quarters active including this one; 0 = inactive). */
    private array $regimes = [];

    /**
     * @param array<string, float> $previousMomentum Map of previous quarter stream Z-scores ($stock->getEarningsMomentumZ())
     * @param MathUtility $mathUtility
     * @param float $firmFactorLoading Default one-factor loading rho applied to every stream drawn via generateZ().
     *                                 Segments of one firm share customers, brand and management, so their
     *                                 innovations are correlated (rho^2 = shared variance); 0.0 = independent.
     */
    public function __construct(
        private readonly array $previousMomentum,
        private readonly MathUtility $mathUtility,
        private readonly float $firmFactorLoading = 0.0,
        /** This quarter's realized N(0,1) demand factor of the firm's macro sector; null when the macro state carries none. */
        private readonly ?float $sectorInnovation = null,
        /** Loading rho_s of every revenue stream on the sector demand factor (rho_s^2 = variance shared with sector peers). */
        private readonly float $sectorFactorLoading = 0.0
    ) {}

    /**
     * Generates a stationary AR(1) Z-score for the given stream key and registers it for persistence.
     *
     * Revenue streams load on the firm-wide common innovation (one-factor model) unless an explicit
     * loading is given. Use generateExogenousZ() for drivers that are not firm demand (catastrophes,
     * credit defaults, regulatory events), which must stay independent of the sales cycle.
     *
     * @param string $key The unique identifier for this revenue stream (e.g., 'government_contracts').
     * @param float $phi  Autoregressive persistence parameter (0 = i.i.d., 1 = random walk).
     * @param float|null $commonLoading Loading on the firm factor for this stream; null = context default.
     * @return float The newly generated stationary Z-score ~ N(0, 1).
     */
    public function generateZ(string $key, float $phi, ?float $commonLoading = null): float
    {
        $firmLoading = max(0.0, min(1.0, $commonLoading ?? $this->firmFactorLoading));
        $prevZ = $this->previousMomentum[$key] ?? 0.0;

        // Two-factor model: e_t = rho_s S_t + rho_f F_t + sqrt(1 - rho_s^2 - rho_f^2) u_t. The sector factor
        // only applies to streams that load on firm demand at all (exogenous draws pass a zero loading), and
        // only when the macro state actually carries a realization for this firm's sector. The two common
        // terms are folded into one unit-variance composite so the single-index helper keeps its contract.
        $sectorLoading = ($firmLoading > 0.0 && $this->sectorInnovation !== null)
            ? max(0.0, min(1.0, $this->sectorFactorLoading))
            : 0.0;
        $compositeLoading = sqrt(min(1.0, ($firmLoading * $firmLoading) + ($sectorLoading * $sectorLoading)));

        if ($compositeLoading > 0.0) {
            $compositeInnovation = (($firmLoading * $this->getFirmInnovation()) + ($sectorLoading * (float) $this->sectorInnovation)) / $compositeLoading;
            $newZ = $this->mathUtility->generatePersistentZ($prevZ, $phi, $compositeInnovation, $compositeLoading);
        } else {
            $newZ = $this->mathUtility->generatePersistentZ($prevZ, $phi);
        }

        $this->nextZ[$key] = $newZ;

        return $newZ;
    }

    /**
     * Generates an AR(1) Z-score that is independent of the firm's demand factor. Reserved for exogenous
     * drivers (catastrophe claims, credit defaults, regulatory or operational tail events).
     */
    public function generateExogenousZ(string $key, float $phi): float
    {
        return $this->generateZ($key, $phi, 0.0);
    }

    /**
     * The firm-wide N(0,1) innovation for this quarter, drawn once on first use so every loaded stream
     * (and any model-level demand logic) sees the same realization.
     */
    public function getFirmInnovation(): float
    {
        return $this->firmInnovation ??= $this->mathUtility->generateStandardNormal();
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
     * Registers a persistent scalar state variable (not a Z-score) into the stream state map.
     *
     * Sector models use this to carry structural state across quarters (patent exclusivity clocks,
     * franchise indices). State keys MUST be re-registered every quarter, because the engine
     * replaces the entire momentum map with getStreamZ() on each earnings report.
     *
     * @param string $key   Namespaced state key (e.g. 'state:commercial_franchise').
     * @param float  $value The scalar value to carry into the next quarter.
     */
    public function registerState(string $key, float $value): void
    {
        $this->nextZ[$key] = $value;
    }

    /**
     * Reads a scalar state value carried forward from the previous quarter's stream map.
     *
     * @param string $key     Namespaced state key registered by a prior quarter.
     * @param float  $default Value returned when the state has never been persisted.
     */
    public function getPersistedState(string $key, float $default = 0.0): float
    {
        return isset($this->previousMomentum[$key]) ? (float) $this->previousMomentum[$key] : $default;
    }

    /**
     * Recognizes revenue for a long-cycle stream through a persistent order backlog.
     *
     * Percentage-of-completion accounting (ASC 606 over-time recognition): orders booked this quarter join the
     * opening backlog, and a fraction $burnRate of the total work available is executed and recognized. The
     * same identity models deferred revenue for subscriptions, where "orders" are bookings and $burnRate is
     * the inverse contract length. At steady state revenue equals orders and the backlog settles at
     * (1 - burnRate) / burnRate quarters of revenue, so a demand shock reaches revenue only at $burnRate per
     * quarter and the remainder persists in the backlog. The backlog is persisted normalized to expected
     * stream revenue so it survives growth and seeding, and is seeded at steady state on first use.
     *
     * @param string $key                   Stream key (persisted under BACKLOG_STATE_PREFIX).
     * @param float  $expectedStreamRevenue Steady-state quarterly revenue of the stream (expected revenue x weight).
     * @param float  $orderMultiplier       This quarter's order intake relative to steady state (1.0 = flat).
     * @param float  $burnRate              Fraction of available work executed per quarter (0.05 .. 1.0).
     * @return array{revenue: float, orders: float, backlog: float, backlog_quarters: float, book_to_bill: float}
     */
    public function recognizeBacklog(string $key, float $expectedStreamRevenue, float $orderMultiplier, float $burnRate): array
    {
        $burn = max(0.05, min(1.0, $burnRate));
        $steadyStateQuarters = (1.0 - $burn) / $burn;
        $base = max(1.0, $expectedStreamRevenue);

        $openingQuarters = max(0.0, $this->getPersistedState(self::BACKLOG_STATE_PREFIX . $key, $steadyStateQuarters));
        $orders = $base * max(0.0, $orderMultiplier);
        $available = ($openingQuarters * $base) + $orders;
        $revenue = $burn * $available;
        $closingBacklog = max(0.0, $available - $revenue);
        $closingQuarters = min(self::MAX_BACKLOG_QUARTERS, $closingBacklog / $base);

        $this->registerState(self::BACKLOG_STATE_PREFIX . $key, $closingQuarters);

        return [
            'revenue'          => $revenue,
            'orders'           => $orders,
            'backlog'          => $closingQuarters * $base,
            'backlog_quarters' => $closingQuarters,
            'book_to_bill'     => $revenue > 0.0 ? $orders / $revenue : 1.0,
        ];
    }

    /**
     * Advances a persistent two-state regime for this quarter (Hamilton 1989 Markov switching).
     *
     * Tail events such as strikes, price wars, consent decrees, recall recoveries or port congestion are not
     * one-quarter blips: once entered they persist for a random number of quarters. While inactive the regime
     * starts with probability $onsetHazard; while active it ends with probability $exitHazard, so the expected
     * duration is 1 / $exitHazard quarters. Models whose onset is driven by a Z threshold or a macro condition
     * pass an onset hazard of 0.0 and call startRegime() themselves once this method has processed the exit.
     *
     * @param string $key         Regime identifier (persisted under REGIME_STATE_PREFIX).
     * @param float  $onsetHazard Quarterly probability of entering the regime while inactive.
     * @param float  $exitHazard  Quarterly probability of leaving the regime while active.
     * @return int Quarters the regime has been active including this one (1 = onset quarter), 0 when inactive.
     */
    public function evolveRegime(string $key, float $onsetHazard, float $exitHazard): int
    {
        $elapsed = (int) round($this->getPersistedState(self::REGIME_STATE_PREFIX . $key, 0.0));

        if ($elapsed > 0) {
            $elapsed = $this->mathUtility->checkProbability(max(0.0, min(1.0, $exitHazard))) ? 0 : $elapsed + 1;
        } elseif ($onsetHazard > 0.0 && $this->mathUtility->checkProbability(min(1.0, $onsetHazard))) {
            $elapsed = 1;
        }

        $this->regimes[$key] = $elapsed;
        $this->registerState(self::REGIME_STATE_PREFIX . $key, (float) $elapsed);

        return $elapsed;
    }

    /**
     * Forces regime onset this quarter for triggers the model resolves itself (a Z-score crossing a
     * threshold, a macro condition). A no-op when the regime is already active.
     *
     * @return int Quarters active including this one (1 on a fresh onset).
     */
    public function startRegime(string $key): int
    {
        $elapsed = max(1, $this->regimes[$key] ?? 0);
        $this->regimes[$key] = $elapsed;
        $this->registerState(self::REGIME_STATE_PREFIX . $key, (float) $elapsed);

        return $elapsed;
    }

    /**
     * Quarters the regime has been active including this one, as resolved by evolveRegime()/startRegime()
     * earlier this quarter; 0 when inactive or not yet evolved.
     */
    public function getRegimeElapsed(string $key): int
    {
        return $this->regimes[$key] ?? 0;
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
                if ($targetWeight <= 0.0) {
                    $rawWeights[$key] = 0.0;
                    continue;
                }

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
                if ($targetWeight <= 0.0) {
                    $activeWeights[$key] = 0.0;
                    continue;
                }
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
     * Resolves the dominant shock Z-score by finding the stream or event shock with the highest absolute magnitude.
     *
     * @param array<int|string, float> $streamZs Array or dictionary of stream Z-scores
     * @param float|null $eventZ Optional event shock Z-score
     * @return float The dominant shock Z-score with the largest absolute value
     */
    public function resolveDominantShockZ(array $streamZs, ?float $eventZ = null): float
    {
        $primary = 0.0;
        foreach ($streamZs as $z) {
            if (abs($z) > abs($primary)) {
                $primary = (float) $z;
            }
        }

        if ($eventZ !== null && abs($eventZ) > abs($primary)) {
            $primary = (float) $eventZ;
        }

        if (empty($streamZs) && $eventZ !== null) {
            return (float) $eventZ;
        }

        return $primary;
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
