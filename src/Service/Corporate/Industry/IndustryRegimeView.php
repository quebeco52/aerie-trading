<?php

declare(strict_types=1);

namespace App\Service\Corporate\Industry;

/**
 * The industry-wide regimes as one firm's report opens: which are running and for how many of its quarters, and
 * how many listed firms share the industry. The opening state never changes during the report, so the macro
 * attribution's replays of the quarter read exactly what the real physics read. A regime the physics starts is
 * held here until the engine commits it to the ledger once, after the real call.
 */
final class IndustryRegimeView
{
    /** @var array<string, int> Regime key => the regime's run length in quarters, for each one started this report. */
    private array $started = [];

    /**
     * @param array<string, int> $elapsed Regime key => quarters it has run including this one; absent when inactive.
     * @param int                $members Listed firms in the industry with a live record, this one included.
     */
    public function __construct(
        private readonly array $elapsed,
        private readonly int $members = 1,
    ) {}

    /** Quarters the regime has run including this one (1 = the quarter it began in), 0 when it is not running. */
    public function elapsed(string $key): int
    {
        return max(0, $this->elapsed[$key] ?? 0);
    }

    /** Listed firms in the industry with a live record, this one included; at least one. */
    public function members(): int
    {
        return max(1, $this->members);
    }

    /**
     * Starts the regime for the whole industry this quarter, to run for the given number of quarters.
     *
     * @return int The quarters it has run including this one: 1, unless it was already running.
     */
    public function start(string $key, int $quarters): int
    {
        if ($this->elapsed($key) > 0) {
            return $this->elapsed($key);
        }
        $this->started[$key] = max(1, $quarters);

        return 1;
    }

    /** @return array<string, int> Regime key => run length in quarters, for each regime started this report. */
    public function startedRegimes(): array
    {
        return $this->started;
    }
}
