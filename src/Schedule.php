<?php

namespace App;

use App\Command\PruneHistoryCommand;
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
                // Run exactly at 3:00 AM every day. Retention is in SIMULATED years: the command measures
                // against the simulation clock, not the wall clock (see PruneHistoryCommand).
                RecurringMessage::cron(
                    '0 3 * * *',
                    new RunCommandMessage(sprintf(
                        'app:prune-history --years=%s --ratio=1000',
                        PruneHistoryCommand::DEFAULT_YEARS_KEPT
                    ))
                )
            );
    }
}