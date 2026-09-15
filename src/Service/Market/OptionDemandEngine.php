<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\DTO\OptionQuoteDTO;
use App\Entity\OptionContract;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;

/**
 * The public's standing position in a name's option chain.
 *
 * Without this the chain is inert. A listed market whose only participants are the handful of players has
 * no open interest, and a desk that is short nothing has no hedge to place — so the one thing that makes
 * options worth simulating, the flow a dealer is forced to trade to stay flat, would never happen.
 *
 * The model is Bollen & Whaley's (2004) net buying pressure: public order flow in listed options is
 * persistently one-sided, the desk absorbs it, and the resulting inventory is what moves implied volatility
 * and forces the hedge. Three facts shape where that position sits, each of them measured rather than
 * assumed:
 *
 *   - It is a NET LONG. The public buys optionality and the desk sells it. That sign is the reason dealers
 *     are structurally short gamma and the reason selling options is a business at all.
 *   - It sits OUT of the money, around a quarter to a third of a delta. The buyer is paying for convexity;
 *     a position at the money is mostly underlying, which they could have bought directly.
 *   - In SINGLE NAMES it tilts to calls, not puts. This is the cross-sectional split Bollen & Whaley found
 *     and it is the opposite of the index: single-name demand is the lottery preference of Bali, Cakici &
 *     Whitelaw (2011), while index demand is portfolio insurance. The tilt swings toward puts as the market
 *     frightens, which is what steepens a skew during a selloff.
 *
 * The book moves TOWARD its target rather than onto it. A position that rebuilt itself every tick would be
 * a flow rather than a holding, and the hedge would then be driven by the demand model's own noise instead
 * of by the price.
 */
final class OptionDemandEngine
{
    public function __construct(
        private readonly LiquidityEngine $liquidityEngine,
    ) {}

    /**
     * Moves the public's position in one name's chain toward what the public would want to hold.
     *
     * @param array<int, OptionContract>      $contracts The name's listed chain.
     * @param array<string, OptionQuoteDTO>   $quotes    Its quotes this sweep, keyed by contract ticker.
     * @param float                           $marketVolatility Live market volatility.
     * @param float                           $baselineVolatility What that volatility is normally.
     * @param float                           $dt        Years since the last sweep.
     */
    public function evolve(
        Stock $stock,
        array $contracts,
        array $quotes,
        float $marketVolatility,
        float $baselineVolatility,
        float $dt
    ): void {
        if ($contracts === [] || $dt <= 0.0) {
            return;
        }

        $budget = $this->contractBudget($stock);
        $callShare = $this->callShare($marketVolatility, $baselineVolatility);

        // Two books, weighted separately, because the call side and the put side are answering different
        // questions and the fear tilt moves capital between them rather than scaling them together.
        $weights = ['CALL' => [], 'PUT' => []];
        $totals = ['CALL' => 0.0, 'PUT' => 0.0];

        foreach ($contracts as $contract) {
            $quote = $quotes[$contract->getTicker()] ?? null;

            if ($quote === null) {
                continue;
            }

            $weight = $this->demandWeight($quote);
            $side = $contract->isCall() ? 'CALL' : 'PUT';

            $weights[$side][$contract->getTicker()] = $weight;
            $totals[$side] += $weight;
        }

        // Exponential approach to target over the demand horizon, in time rather than in ticks so the book
        // is rebuilt at the same speed whatever the tick rate.
        $approach = 1.0 - exp(-$dt / FinancialConstants::OPTION_PUBLIC_DEMAND_HORIZON_YEARS);

        foreach ($contracts as $contract) {
            $side = $contract->isCall() ? 'CALL' : 'PUT';
            $weight = $weights[$side][$contract->getTicker()] ?? 0.0;

            $target = $totals[$side] > 0.0
                ? ($budget * ($side === 'CALL' ? $callShare : 1.0 - $callShare) * ($weight / $totals[$side]))
                : 0.0;

            $current = (float) $contract->getStructuralOpenInterest();

            $contract->setStructuralOpenInterest($this->step($current, $target, $approach));
        }
    }

    /**
     * One step of the book toward its target, in whole contracts.
     *
     * A proportional step alone does not converge on an integer position: once the remaining gap is small
     * enough that the step rounds to zero, the book stops short and STAYS short, which left every name
     * holding slightly less than the model said it wanted, always in the same direction. Moving at least one
     * contract whenever a whole contract is still outstanding removes that, and it is the honest rule
     * anyway — a contract is the smallest thing the public can trade, so the smallest move is one of them,
     * never a fraction that rounds away.
     *
     * Never overshoots: the step is clamped to the gap it is closing.
     */
    private function step(float $current, float $target, float $approach): int
    {
        $gap = $target - $current;

        if (abs($gap) < 1.0) {
            return (int) round($current);
        }

        $step = $gap * $approach;

        if (abs($step) < 1.0) {
            $step = $gap > 0.0 ? 1.0 : -1.0;
        }

        return (int) round($current + max(-abs($gap), min(abs($gap), $step)));
    }

    /**
     * The size of the public's whole book in one name, in contracts.
     *
     * Struck against average daily volume converted to contract-equivalents, which is the same unit the
     * agent population sizes its capital in: an option book is only as big as the underlying it is written
     * on can absorb, and expressing it any other way makes the same figure mean different things on a
     * mega-cap and a small one.
     */
    public function contractBudget(Stock $stock): float
    {
        $dailyVolumeInContracts = $this->liquidityEngine->structuralDailyVolume($stock)
            / (float) FinancialConstants::OPTION_CONTRACT_MULTIPLIER;

        return $dailyVolumeInContracts * FinancialConstants::OPTION_PUBLIC_OPEN_INTEREST_ADV_MULTIPLE;
    }

    /**
     * Share of the public's book that goes to calls.
     *
     * Single-name demand is call-led in calm conditions and swings to puts as the market frightens. The
     * swing is bounded to the interval by construction rather than clamped after the fact.
     */
    public function callShare(float $marketVolatility, float $baselineVolatility): float
    {
        $fear = $baselineVolatility > 0.0
            ? ($marketVolatility - $baselineVolatility) / $baselineVolatility
            : 0.0;

        $share = FinancialConstants::OPTION_PUBLIC_CALL_SHARE
            - (max(0.0, $fear) * FinancialConstants::OPTION_PUBLIC_FEAR_PUT_SENSITIVITY * FinancialConstants::OPTION_PUBLIC_CALL_SHARE);

        return max(0.0, min(1.0, $share));
    }

    /**
     * Where on the chain one contract sits in the public's preference.
     *
     * A Gaussian kernel in absolute delta, decaying with time to expiry. It is an empirical demand profile
     * — this is where listed open interest actually concentrates — not a pricing law, and nothing downstream
     * treats it as one: it decides only how a fixed book is distributed across a ladder, never what any of
     * it is worth.
     */
    public function demandWeight(OptionQuoteDTO $quote): float
    {
        $moneyness = abs($quote->delta) - FinancialConstants::OPTION_PUBLIC_TARGET_DELTA;
        $dispersion = FinancialConstants::OPTION_PUBLIC_DELTA_DISPERSION;

        $deltaWeight = exp(-($moneyness * $moneyness) / (2.0 * $dispersion * $dispersion));
        $expiryWeight = exp(-FinancialConstants::OPTION_PUBLIC_EXPIRY_DECAY * $quote->timeToExpiry);

        return $deltaWeight * $expiryWeight;
    }
}
