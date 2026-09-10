<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * What an order of a given size would actually fill at, and what the difference from mid consists of.
 *
 * The breakdown is kept rather than collapsed into one number so the fill can be shown to the trader and
 * audited afterwards. "You paid 3% over mid" is a bug report; "you paid 4bp of spread and 296bp of impact
 * on an order worth 80% of a day's volume" is an explanation.
 */
final readonly class ExecutionQuoteDTO
{
    /**
     * @param float $midPrice          The last published price, before any execution cost.
     * @param float $executionPrice    What the order fills at.
     * @param float $spreadCost        Total currency paid crossing the bid-ask spread.
     * @param float $impactCost        Total currency paid to the order's own temporary impact.
     * @param float $permanentImpact   Log return the fill leaves behind in the published price.
     * @param float $participationRate Order size as a fraction of average daily volume.
     */
    public function __construct(
        public float $midPrice,
        public float $executionPrice,
        public float $spreadCost,
        public float $impactCost,
        public float $permanentImpact,
        public float $participationRate,
    ) {}

    /** Total execution cost against a fill at mid, in currency. */
    public function totalCost(): float
    {
        return $this->spreadCost + $this->impactCost;
    }
}
