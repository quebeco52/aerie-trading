<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\DTO\OptionQuoteDTO;
use App\Entity\OptionContract;
use App\Entity\Stock;
use App\Service\Market\Gamma\DealerGammaStoreInterface;
use App\Service\Math\FinancialConstants;

/**
 * The flow an option desk is forced to trade in the underlying to stay flat.
 *
 * This is the reason listed options belong in this simulation at all. Everything else about a chain is a
 * side bet — a contract whose value is a function of a price it does not affect. Hedging is the part that
 * reaches back: a desk that has sold optionality to the public must buy the underlying as it rises and sell
 * it as it falls, simply to keep its delta at zero, and that flow is real, mechanical and large.
 *
 * The sign is what matters. The public is a net BUYER of options (see OptionDemandEngine), so the desk is
 * net short them, so the desk is short GAMMA: its hedge chases the price instead of leaning against it, and
 * a move is amplified by exactly the amount the desk has to trade to survive it. That is the documented
 * channel — Barbon & Buraschi (2020), Baltussen, Da, Lammers & Radeva (2021) — and it is why a market can
 * be quiet for weeks and then gap on nothing: the position that was absorbing moves became the position
 * that propagates them.
 *
 * Two properties keep it honest:
 *
 *   - The hedge answers LAST tick's move, not this one. A desk observes a price and then trades; letting it
 *     trade the move it is currently causing would close an algebraic loop inside a single tick and the
 *     stability of the market would depend on the size of an open interest number.
 *   - The flow goes into the ORDER FLOW STORE rather than moving the price directly. It is then charged the
 *     same impact as everything else in the tick and, because the impact-variance EMA measures it, the
 *     diffusion gives back exactly the variance it supplies. Moving the price here instead would have
 *     stacked a fresh source of volatility on a budget that had already been spent.
 */
final class DealerGammaEngine
{
    public function __construct(
        private readonly DealerGammaStoreInterface $store,
        private readonly LiquidityEngine $liquidityEngine,
    ) {}

    /**
     * Measures the desk's exposure in one name from a freshly quoted chain.
     *
     * @param array<int, OptionContract>    $contracts
     * @param array<string, OptionQuoteDTO> $quotes Keyed by contract ticker.
     * @return float Shares of delta the public gains per 1.00 move; what the desk must buy to match it.
     */
    public function refresh(Stock $stock, array $contracts, array $quotes): float
    {
        $customerGamma = 0.0;

        foreach ($contracts as $contract) {
            $quote = $quotes[$contract->getTicker()] ?? null;

            if ($quote === null) {
                continue;
            }

            // Gamma is per share of underlying, a contract is a hundred of them, and open interest counts
            // contracts. A call and a put both have positive gamma, so a net long public is net long gamma
            // whichever side it bought.
            $customerGamma += $contract->customerOpenInterest()
                * FinancialConstants::OPTION_CONTRACT_MULTIPLIER
                * $quote->gamma;
        }

        $this->store->record($stock->getTicker(), $customerGamma, (float) $stock->getPrice());

        return $customerGamma;
    }

    /**
     * The shares the desk must trade in one name to re-hedge the move since it last did.
     *
     * Returns signed shares — positive is a buy — ready to join the tick's net order flow. Marks the name
     * as hedged, so the same move is never hedged twice.
     */
    public function hedgeFlow(Stock $stock): float
    {
        $state = $this->store->read($stock->getTicker());

        if ($state === null || $state['gamma'] === 0.0) {
            return 0.0;
        }

        $price = (float) $stock->getPrice();
        $move = $price - $state['reference_price'];

        if ($move === 0.0) {
            return 0.0;
        }

        $shares = $state['gamma'] * $move * FinancialConstants::DEALER_HEDGE_RATIO;

        // A desk cannot demand more liquidity in one tick than the name trades in a quarter of a day. Past
        // that the impact law is extrapolation, and a short-gamma desk chasing a gap would otherwise size
        // its way into an unbounded spiral against a market that cannot fill it.
        $ceiling = $this->liquidityEngine->averageDailyVolume($stock) * FinancialConstants::MAX_DEALER_HEDGE_ADV_MULTIPLE;
        $shares = max(-$ceiling, min($ceiling, $shares));

        // The exposure the hedge was computed from is already in hand, so the mark moves forward in one
        // write rather than a read to recover a number this method is holding.
        $this->store->record($stock->getTicker(), $state['gamma'], $price);

        return $shares;
    }
}
