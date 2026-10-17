<?php

declare(strict_types=1);

namespace App\Service\Market\Option;

use App\DTO\OptionQuoteDTO;
use App\Entity\OptionContract;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use App\Service\Market\Pricing\LiquidityEngine;

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
 * The desk hedges the delta it owes, against the shares it holds: a move trades gamma times the move, new open
 * interest trades its delta the pass it appears, and an expiry hands the hedge back to the market.
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
    // --- Dealer Gamma Hedging (Barbon & Buraschi 2020; Baltussen, Da, Lammers & Radeva 2021) ---
    /** Share of the desk's delta exposure that actually reaches the market as a hedge. A desk nets customer flow against itself first and only hedges the residual, so the whole of its book never trades. */
    public const DEALER_HEDGE_RATIO = 0.80;
    /** Ceiling on the desk's hedging flow per trading day as a share of the name's average daily volume, the Rule 10b-18 pacing corporate flow uses. Past it the impact law is extrapolation; the clipped remainder carries to the next pass. */
    public const MAX_DEALER_HEDGE_ADV_MULTIPLE = 0.25;
    /** Gap to its target the desk treats as already hedged: numerical dust in the book, not a trade. */
    public const MIN_HEDGE_SHARES = 1e-6;

    public function __construct(
        private readonly DealerGammaStoreInterface $store,
        private readonly LiquidityEngine $liquidityEngine,
    ) {}

    /**
     * Measures the desk's exposure in one name from a freshly quoted chain, keeping the shares it already holds.
     *
     * A book the store has never seen is taken as already hedged, so a cold start trades no catch-up; a new listing
     * opens with no open interest and nothing to hedge.
     *
     * @param array<int, OptionContract>    $contracts
     * @param array<string, OptionQuoteDTO> $quotes Keyed by contract ticker.
     * @return float Shares of delta the public gains per 1.00 move; what the desk must buy to match it.
     */
    public function refresh(Stock $stock, array $contracts, array $quotes): float
    {
        $customerGamma = 0.0;
        $customerDelta = 0.0;

        foreach ($contracts as $contract) {
            $quote = $quotes[$contract->getTicker()] ?? null;

            if ($quote === null) {
                continue;
            }

            // Gamma is per share of underlying, a contract is a hundred of them, and open interest counts
            // contracts. A call and a put both have positive gamma, so a net long public is net long gamma
            // whichever side it bought; its delta is signed, a put's negative.
            $shares = $contract->customerOpenInterest() * FinancialConstants::OPTION_CONTRACT_MULTIPLIER;
            $customerGamma += $shares * $quote->gamma;
            $customerDelta += $shares * $quote->delta;
        }

        $ticker = $stock->getTicker();
        $previous = $this->store->read($ticker);

        if ($previous === null && $contracts === []) {
            return 0.0;
        }

        $this->store->record($ticker, [
            'gamma' => $customerGamma,
            'delta' => $customerDelta,
            'reference_price' => (float) $stock->getPrice(),
            'held' => $previous['held'] ?? (self::DEALER_HEDGE_RATIO * $customerDelta),
        ]);

        return $customerGamma;
    }

    /**
     * The shares the desk must trade in one name to bring its hedge to the delta it owes now.
     *
     * Returns signed shares — positive is a buy — ready to join the tick's net order flow, and books them to the
     * desk's holding, so the same delta is never hedged twice and a clipped remainder carries to the next pass.
     *
     * @param float $passYears Time since the previous hedging pass, in years.
     */
    public function hedgeFlow(Stock $stock, float $passYears): float
    {
        $state = $this->store->read($stock->getTicker());
        $shares = $this->hedge($stock, $state, $passYears);

        if ($state !== null && $shares !== 0.0) {
            $state['held'] += $shares;
            $this->store->record($stock->getTicker(), $state);
        }

        return $shares;
    }

    /**
     * Hedges every name in one pass, against one read of the store and one write back to it.
     *
     * A read and a write per name turned a pass over the market into a couple of hundred round trips
     * against a store that holds all of it in a single hash, and that was running on every tick. The
     * arithmetic is identical; only the number of times the desk asks for it has changed.
     *
     * @param array<int, Stock> $stocks
     * @param float             $passYears Time since the previous hedging pass, in years.
     * @return array<string, float> Signed shares to trade, by ticker; names with nothing to do are absent.
     */
    public function hedgeMarket(array $stocks, float $passYears): array
    {
        $state = $this->store->readAll();

        $flows = [];
        $entries = [];

        foreach ($stocks as $stock) {
            if ($stock->isBankrupt()) {
                continue;
            }

            $ticker = $stock->getTicker();
            $entry = $state[$ticker] ?? null;
            $shares = $this->hedge($stock, $entry, $passYears);

            if ($shares === 0.0 || $entry === null) {
                continue;
            }

            $flows[$ticker] = $shares;
            $entry['held'] += $shares;
            $entries[$ticker] = $entry;
        }

        $this->store->recordAll($entries);

        return $flows;
    }

    /**
     * The hedge one name's stored book implies, without touching the store: the desk's target is the hedge ratio of
     * the public's delta, carried from where it was measured to today's price by its gamma, and the trade is the gap
     * to what the desk holds. A move trades gamma times the move; new open interest trades its delta at once; an
     * expiry hands the hedge back.
     *
     * @param array{gamma: float, delta: float, reference_price: float, held: float}|null $state
     * @return float Signed shares.
     */
    private function hedge(Stock $stock, ?array $state, float $passYears): float
    {
        if ($state === null) {
            return 0.0;
        }

        $price = (float) $stock->getPrice();
        $target = self::DEALER_HEDGE_RATIO * ($state['delta'] + ($state['gamma'] * ($price - $state['reference_price'])));
        $wanted = $target - $state['held'];

        if (abs($wanted) < self::MIN_HEDGE_SHARES) {
            return 0.0;
        }

        // A short-gamma desk chasing a gap would otherwise size into a spiral the name cannot fill. The cap is
        // a share of ADV per trading day, pro rata to the pass, so it binds the same at any hedging cadence
        // (the pacing LiquidityEngine::corporateFlowSlice uses).
        $passDays = max(0.0, $passYears) * FinancialConstants::TRADING_DAYS_PER_YEAR;
        $ceiling = $this->liquidityEngine->averageDailyVolume($stock) * self::MAX_DEALER_HEDGE_ADV_MULTIPLE * $passDays;

        return max(-$ceiling, min($ceiling, $wanted));
    }
}
