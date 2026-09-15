<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Everything an agent is allowed to see about one name at one tick.
 *
 * A deliberate bottleneck. Agents observe what a real participant could observe — a price, a published
 * fair value, a trend, realized volatility, the policy rate, the macro backdrop — and nothing that only the
 * engine knows.
 *
 * The return handed in is measured BEFORE any split this tick, and the split is reported separately as a
 * ratio. A 4-for-1 split quarters the price without anyone losing money; scoring it as a return would
 * execute every long belief at the exact top of the run that earned the split. Handing them the Stock
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
     * @param float  $averageDailyVolume Structural depth — shares, float and turnover, WITHOUT the volume-volatility activity multiplier — which sets how large an agent book can plausibly be. Sized on the activity-scaled figure, every book grew into a stressed tape and the passive money bought the spike.
     * @param float  $logReturn          The TOTAL return since the agents last acted, dividends included, for scoring their beliefs. An ex-dividend drop with the cash left out scored every payment as a loss for the long side.
     * @param float  $financialConditions Macro conditions index; passive flows respond to it.
     * @param float  $dt                 Elapsed simulated time in years.
     * @param float  $riskFreeRate       Annual rate cash earns; a belief is scored on what it made over it.
     * @param float  $annualizedVolatility Volatility as a decimal, the risk a belief is charged for its exposure and what the vol-sensitive holders size on. The engine replaces it with the realized measure it keeps in the name's book; the value handed in only seeds a book with no history.
     * @param float  $splitRatio         New shares per old share this tick; 1.0 when nothing happened. Agent books are in shares and must be restated.
     * @param ?float $marketLogMispricing Average log mispricing across the market as it stood when the tick opened; null until the market has one. A relative view has nothing to compare against without it.
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
        public float $riskFreeRate = 0.0,
        public float $annualizedVolatility = 0.0,
        public float $splitRatio = 1.0,
        public ?float $marketLogMispricing = null,
        /**
         * Whether this name is in the index.
         *
         * Defaults to true, which is what the market was before there was a membership at all: every listed
         * company counted, so every listed company was something a passive fund held.
         */
        public bool $isIndexMember = true,
    ) {}

    /**
     * The same view with the market's cross-section filled in. The engine, not the tracker, knows the
     * cross-section, because it is built from every name the engine traded last tick.
     */
    public function withMarketLogMispricing(float $marketLogMispricing): self
    {
        return new self(
            $this->ticker,
            $this->price,
            $this->perceivedFairValue,
            $this->momentumTrend,
            $this->averageDailyVolume,
            $this->logReturn,
            $this->financialConditions,
            $this->dt,
            $this->riskFreeRate,
            $this->annualizedVolatility,
            $this->splitRatio,
            $marketLogMispricing,
        );
    }

    /**
     * The same view with the volatility the agents have themselves observed, from the engine's book.
     */
    public function withAnnualizedVolatility(float $annualizedVolatility): self
    {
        return new self(
            $this->ticker,
            $this->price,
            $this->perceivedFairValue,
            $this->momentumTrend,
            $this->averageDailyVolume,
            $this->logReturn,
            $this->financialConditions,
            $this->dt,
            $this->riskFreeRate,
            $annualizedVolatility,
            $this->splitRatio,
            $this->marketLogMispricing,
        );
    }

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

    /**
     * Log mispricing against the average name: positive when this name is cheaper than the market is.
     *
     * Zero, not the absolute mispricing, when the market's cross-section is not known yet. A relative view
     * that fell back to the absolute one would be a second fundamentalist on the first tick.
     */
    public function relativeLogMispricing(): float
    {
        if ($this->marketLogMispricing === null || !is_finite($this->marketLogMispricing)) {
            return 0.0;
        }

        return $this->logMispricing() - $this->marketLogMispricing;
    }
}
