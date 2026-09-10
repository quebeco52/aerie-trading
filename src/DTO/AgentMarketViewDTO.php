<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Everything an agent is allowed to see about one name at one tick.
 *
 * A deliberate bottleneck. Agents observe what a real participant could observe — a price, a published
 * fair value, a trend, the macro backdrop — and nothing that only the engine knows. Handing them the Stock
 * entity would let a strategy read next quarter's earnings off the balance sheet and trade on it, which
 * would be a strategy that cannot lose rather than one that competes.
 */
final readonly class AgentMarketViewDTO
{
    /**
     * @param string $ticker             The name.
     * @param float  $price              Last published price.
     * @param float  $perceivedFairValue What analysts currently think it is worth.
     * @param float  $momentumTrend      Exponentially weighted sum of recent log returns.
     * @param float  $averageDailyVolume Depth, which sets how large an agent book can plausibly be.
     * @param float  $logReturn          The return since the agents last acted, for scoring their beliefs.
     * @param float  $financialConditions Macro conditions index; passive flows respond to it.
     * @param float  $dt                 Elapsed simulated time in years.
     */
    public function __construct(
        public string $ticker,
        public float $price,
        public float $perceivedFairValue,
        public float $momentumTrend,
        public float $averageDailyVolume,
        public float $logReturn,
        public float $financialConditions,
        public float $dt,
    ) {}

    /**
     * Log mispricing: positive when the name trades below what it is thought to be worth.
     *
     * In logs rather than as a percentage so the signal is symmetric — a name at half fair value and one at
     * twice it are the same distance from home in opposite directions, which a percentage gap is not.
     */
    public function logMispricing(): float
    {
        if ($this->price <= 0.0 || $this->perceivedFairValue <= 0.0) {
            return 0.0;
        }

        return log($this->perceivedFairValue / $this->price);
    }
}
