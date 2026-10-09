<?php

namespace App\Scheduler;

use App\Service\Market\Ticker\HistoryPruner;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run
            ->add(
                // A BACKSTOP, NOT THE ENFORCEMENT. The cutoffs are spans of SIMULATED time, and this trigger
                // is wall-clock: a simulation running a thousand years a real day gets its one-simulated-year
                // retention applied once per thousand simulated years, which is no retention at all. The
                // ticker enforces it on the simulated clock (see HistoryPruner); this entry only covers an
                // installation whose ticker is not running.
                RecurringMessage::cron(
                    '0 3 * * *',
                    new RunCommandMessage(sprintf(
                        'app:prune-history --years=%s --per-year=%d',
                        HistoryPruner::DEFAULT_YEARS_KEPT,
                        HistoryPruner::THINNED_ROWS_PER_YEAR
                    ))
                )
            );
    }
}