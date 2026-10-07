<?php

declare(strict_types=1);

namespace App\Service\Season;

/** The running sums of one season entry's weekly returns, the benchmark's and the cash rate's over the same weeks. */
final readonly class SeasonStatisticsInput
{
    public function __construct(
        public int $periods,
        public float $sumDt,
        public float $sumReturn,
        public float $sumReturnSq,
        public float $sumBenchmark,
        public float $sumBenchmarkSq,
        public float $sumCross,
        public float $sumRiskFree,
    ) {}
}
