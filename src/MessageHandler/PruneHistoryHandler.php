<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PruneHistoryMessage;
use App\Service\Market\Ticker\HistoryPruner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PruneHistoryHandler
{
    public function __construct(
        private HistoryPruner $pruner,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(PruneHistoryMessage $message): void
    {
        $report = $this->pruner->prune($message->years, $message->rowsPerYear);

        // Logged rather than returned: nothing waits on this, and the row counts are the only evidence that
        // the retention is being enforced at all — the failure this exists to fix was silent for years.
        $this->logger->info('Retention applied at simulation year {year}.', [
            'year' => round($report['now'], 4),
            'cutoff' => round($report['cutoff'], 4),
            'history_rows' => array_sum($report['tables']),
            'settled_contracts' => $report['options'],
            'macro_rows' => $report['macro'],
        ]);
    }
}
