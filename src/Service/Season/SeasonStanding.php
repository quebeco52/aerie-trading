<?php

declare(strict_types=1);

namespace App\Service\Season;

use App\Entity\SeasonEntry;

/** One row of a season table: return since entry at a given value, and the risk figures of the weekly record. */
final class SeasonStanding
{
    /** Place among qualified entries, set by SeasonService::rank(); null when unranked. */
    public ?int $rank = null;

    public function __construct(
        public readonly SeasonEntry $entry,
        public readonly string $username,
        public readonly int $userId,
        public readonly float $value,
        public readonly ?float $return,
        public readonly ?float $benchmarkReturn,
        public readonly ?float $sharpe,
        public readonly ?float $beta,
        public readonly ?float $alpha,
        public readonly ?float $volatility,
        public readonly float $maxDrawdown,
        public readonly int $weeks,
        public readonly bool $qualified,
        public readonly bool $forfeited,
    ) {}

    public static function fromEntry(SeasonEntry $entry, float $value, float $benchmarkLevel): self
    {
        $in = $entry->statisticsInput();

        return new self(
            entry: $entry,
            username: $entry->getUser()->getUsername() ?? 'Anonymous Trader',
            userId: (int) $entry->getUser()->getId(),
            value: $value,
            return: SeasonStatistics::growth($value, $entry->getStartValue()),
            benchmarkReturn: SeasonStatistics::growth($benchmarkLevel, $entry->getBenchmarkStartLevel()),
            sharpe: SeasonStatistics::sharpe($in),
            beta: SeasonStatistics::beta($in),
            alpha: SeasonStatistics::alpha($in),
            volatility: SeasonStatistics::volatility($in),
            maxDrawdown: $entry->getMaxDrawdown(),
            weeks: $entry->getPeriods(),
            qualified: $entry->isQualified(),
            forfeited: $entry->isForfeited(),
        );
    }

    /** Return over the benchmark's, over the same span; null when either is unknown. */
    public function excess(): ?float
    {
        return $this->return === null || $this->benchmarkReturn === null ? null : $this->return - $this->benchmarkReturn;
    }
}
