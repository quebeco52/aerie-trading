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

        $ticker = $stock->getTicker();
        $this->store->record($ticker, $customerGamma, self::carriedReference($this->store->read($ticker), $customerGamma, (float) $stock->getPrice()));

        return $customerGamma;
    }

    /**
     * The reference price that keeps the desk's unhedged delta when its gamma is re-measured.
     *
     * The desk still owes old gamma × (P − ref) of hedge; re-marking at the new gamma preserves that residual,
     * so a re-quote between passes neither drops nor doubles the move not yet hedged.
     *
     * @param array{gamma: float, reference_price: float}|null $previous
     */
    private static function carriedReference(?array $previous, float $gamma, float $price): float
    {
        if ($previous === null || $gamma === 0.0) {
            return $price;
        }

        return $price - ($previous['gamma'] / $gamma) * ($price - $previous['reference_price']);
    }

    /**
     * The shares the desk must trade in one name to re-hedge the move since it last did.
     *
     * Returns signed shares — positive is a buy — ready to join the tick's net order flow. Marks the name
     * as hedged by the share it filled, so the same move is never hedged twice and a clipped remainder
     * carries to the next pass.
     *
     * @param float $passYears Time since the previous hedging pass, in years.
     */
    public function hedgeFlow(Stock $stock, float $passYears): float
    {
        $state = $this->store->read($stock->getTicker());
        [$shares, $reference] = $this->hedge($stock, $state, $passYears);

        if ($state !== null && $shares !== 0.0) {
            $this->store->record($stock->getTicker(), $state['gamma'], $reference);
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
        $marks = [];

        foreach ($stocks as $stock) {
            if ($stock->isBankrupt()) {
                continue;
            }

            $ticker = $stock->getTicker();
            $entry = $state[$ticker] ?? null;
            [$shares, $reference] = $this->hedge($stock, $entry, $passYears);

            if ($shares === 0.0 || $entry === null) {
                continue;
            }

            $flows[$ticker] = $shares;
            $marks[$ticker] = ['gamma' => $entry['gamma'], 'reference_price' => $reference];
        }

        $this->store->recordAll($marks);

        return $flows;
    }

    /**
     * The hedge one name's stored exposure implies, and the reference price it leaves, without touching the
     * store.
     *
     * @param array{gamma: float, reference_price: float}|null $state
     * @return array{0: float, 1: float} Signed shares, and the price the filled share of the move hedges to.
     */
    private function hedge(Stock $stock, ?array $state, float $passYears): array
    {
        $price = (float) $stock->getPrice();

        if ($state === null || $state['gamma'] === 0.0) {
            return [0.0, $price];
        }

        $move = $price - $state['reference_price'];

        if ($move === 0.0) {
            return [0.0, $price];
        }

        $wanted = $state['gamma'] * $move * self::DEALER_HEDGE_RATIO;

        // A short-gamma desk chasing a gap would otherwise size into a spiral the name cannot fill. The cap is
        // a share of ADV per trading day, pro rata to the pass, so it binds the same at any hedging cadence
        // (the pacing LiquidityEngine::corporateFlowSlice uses).
        $passDays = max(0.0, $passYears) * FinancialConstants::TRADING_DAYS_PER_YEAR;
        $ceiling = $this->liquidityEngine->averageDailyVolume($stock) * self::MAX_DEALER_HEDGE_ADV_MULTIPLE * $passDays;
        $shares = max(-$ceiling, min($ceiling, $wanted));

        // Only the filled share of the move is hedged; the remainder stays owed for later passes.
        return [$shares, $state['reference_price'] + ($move * $shares / $wanted)];
    }

}
