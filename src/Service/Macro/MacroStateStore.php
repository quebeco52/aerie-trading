<?php

declare(strict_types=1);

namespace App\Service\Macro;

use Psr\Log\LoggerInterface;

/**
 * The macroeconomic state in Redis: the one reader and writer of its key.
 *
 * The ticker loads the state once, steps it in memory and saves it after every tick, so the pages and the trading
 * desks read the economy the tick runs on. The simulation clock is committed to the database, so a state found at
 * start-up is realigned to it, and a missing one is refused once the clock has run.
 */
class MacroStateStore
{
    public const KEY = 'macroeconomic_state';

    /** Whether this store has saved a state, after which a missing one is lost rather than not yet opened. */
    private bool $statePersisted = false;

    public function __construct(
        private readonly \Redis $redis,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * The saved state as its wire payload, or an empty one when Redis holds none that decodes.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $raw = $this->redis->get(self::KEY);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger?->error('Failed to decode macroeconomic state from Redis: ' . $e->getMessage());

            return [];
        }
        if (!is_array($decoded)) {
            $this->logger?->warning('Corrupt macroeconomic state in Redis: expected array, got ' . gettype($decoded));

            return [];
        }

        return $decoded;
    }

    /** The saved state, or null when Redis holds none that decodes. */
    public function saved(): ?MacroState
    {
        $payload = $this->payload();
        if ($payload === []) {
            return null;
        }

        return MacroState::fromArray($payload);
    }

    /**
     * The saved state, or the opening state when there is none yet.
     *
     * A store that has saved a state and then finds none has lost it to a flush, an eviction or a corrupt
     * write. Opening a fresh economy there would run Year-1 prices under the current board and carry that clock into
     * the database, so it stops instead; on restart, alignClock() refuses the missing state against the database
     * clock.
     *
     * @throws \RuntimeException When the state this store saved is gone.
     */
    public function load(): MacroState
    {
        $state = $this->saved();
        if ($state !== null) {
            return $state;
        }

        if ($this->statePersisted) {
            throw $this->lost();
        }

        return new MacroState();
    }

    /**
     * Writes the state over the saved one. Once this store has saved a state, the key must still be there:
     * a key gone mid-run was flushed under a running ticker, and writing the old economy back would carry it into a
     * market reset.
     *
     * @throws \RuntimeException When the state this store saved is gone.
     * @throws \JsonException    When the state holds a value JSON cannot carry (a NaN or an infinity).
     */
    public function save(MacroState $state): void
    {
        $encoded = json_encode($state->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $written = $this->statePersisted
            ? $this->redis->set(self::KEY, $encoded, ['xx'])
            : $this->redis->set(self::KEY, $encoded);
        if ($written === true) {
            $this->statePersisted = true;
        } elseif ($this->statePersisted) {
            throw $this->lost();
        }
    }

    /**
     * The saved state with its clock set to the authoritative simulation time, saved again when that moved it.
     *
     * The macro state is cached in Redis and the simulation clock is committed to the database, so a cache
     * that has lost writes comes back describing an economy at the wrong moment. Everything else in the state
     * — the rate, the regime, where the cycle stands — is a level with no record of when it was taken, and
     * there is nothing to reconcile it against; only time itself can be corrected, and it has to be, because
     * the expiry grids and sampling cadences read off it. The ticker does this once at start-up.
     *
     * A cache with no state at all is refused once the clock has run: an opening state stamped with a late clock is
     * a Year-1 economy (deflator 1.0) under a board carrying every year of nominal growth since, and the firms that
     * report on it book their plant at a price level a hundredth of the one they bought it at.
     *
     * @throws \RuntimeException When Redis holds no macro state and the clock is past the opening.
     */
    public function alignClock(float $totalTime): MacroState
    {
        $state = $this->saved();
        if ($state === null) {
            if ($totalTime > 0.0) {
                throw new \RuntimeException(sprintf(
                    'No macroeconomic state in Redis (key "%s") while the simulation clock stands at year %.4f. '
                    . 'Restore the key, or start over with app:market-reset; opening a fresh economy here would run '
                    . 'Year-1 prices under the current board.',
                    self::KEY,
                    $totalTime,
                ));
            }

            return new MacroState();
        }

        if ($state->totalTime === $totalTime) {
            return $state;
        }

        $this->logger?->warning(sprintf(
            'Macroeconomic clock realigned from %.6f to %.6f simulation years to match the database.',
            $state->totalTime,
            $totalTime,
        ));

        $state->totalTime = $totalTime;
        $this->save($state);

        return $state;
    }

    private function lost(): \RuntimeException
    {
        return new \RuntimeException(sprintf(
            'The macroeconomic state saved under Redis key "%s" is gone. Stop the ticker and restore the key, or '
            . 'start over with app:market-reset.',
            self::KEY,
        ));
    }
}
