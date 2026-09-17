<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Service\Math\FinancialConstants;

/**
 * The arbitrage that keeps a fund near its basket, and the band inside which it does not bother.
 *
 * A fund's price is not its net asset value. It is set by whoever is buying and selling the FUND, which is
 * a different crowd from the one setting the prices of what the fund holds, and the two only agree because
 * somebody is paid to make them agree. An authorized participant closes a gap by buying the cheap side and
 * selling the dear one — creating shares against a delivered basket when the fund trades rich, redeeming
 * them for the basket when it trades cheap.
 *
 * That trade costs money, so it does not happen for small gaps. Petajisto (2017), *Inefficiencies in the
 * Pricing of Exchange-Traded Funds*: deviations from net asset value persist inside a band set by the
 * round-trip cost of assembling the basket, and the band is not a constant. It opens exactly when the
 * constituents become expensive to trade, which is why equity funds held their pricing through the 2020
 * stress and bond funds printed double-digit discounts in the same week. Nothing about that behaviour is
 * written in here; it falls out of reading the band off the live basket.
 *
 * Pure arithmetic. No persistence, no entity writes.
 */
final class AuthorizedParticipant
{
    /**
     * Half-width of the no-arbitrage band, as a fraction of net asset value.
     *
     * Inside it the gap is not worth closing and the fund wanders on its own order flow; at the edge the
     * arbitrage turns a profit and the AP appears. The creation fee is paid once; the basket has to be
     * crossed getting in and out, which is what the round-trip multiple counts.
     *
     * @param float $basketHalfSpread Weighted average half-spread of the constituents, as a fraction.
     */
    public function band(float $basketHalfSpread): float
    {
        $cost = FinancialConstants::ETF_CREATION_FEE
            + (max(0.0, $basketHalfSpread) * FinancialConstants::ETF_BASKET_ROUND_TRIP_MULTIPLE);

        return min(FinancialConstants::ETF_MAX_ARBITRAGE_BAND, max(FinancialConstants::ETF_HALF_SPREAD, $cost));
    }

    /**
     * Settles one tick of fund pricing: where the premium ends up, and what the AP had to create or redeem
     * to put it there.
     *
     * Net demand for the FUND pushes its price away from the basket, linearly in the size of that demand
     * against the size of the fund — the same linear permanent impact the equity book uses, for the same
     * reason (Huberman & Stanzl 2004: any other shape admits a round-trip that prints money). What is left
     * of yesterday's gap decays, because the deviation is transient and the AP community is working on it.
     *
     * Once the gap clears the band the AP takes the excess, and takes exactly the excess: it creates until
     * the trade stops paying, which is the band edge and not zero. A fund pinned to net asset value would
     * be a fund with no premium to observe.
     *
     * @param float $priorPremium  Where the fund closed last tick, as a fraction of net asset value.
     * @param float $netFlowValue  Net demand for fund shares this tick, in currency; positive is buying.
     * @param float $netAssets     The whole fund at net asset value.
     * @param float $band          Half-width from band().
     * @return array{premium: float, creationValue: float} The new premium, and the basket the AP had to
     *         buy (positive) or sell (negative) in currency.
     */
    public function settle(float $priorPremium, float $netFlowValue, float $netAssets, float $band): array
    {
        if ($netAssets <= 0.0) {
            return ['premium' => 0.0, 'creationValue' => 0.0];
        }

        $pressure = FinancialConstants::ETF_FLOW_PRESSURE * ($netFlowValue / $netAssets);
        $raw = ($priorPremium * FinancialConstants::ETF_PREMIUM_PERSISTENCE) + $pressure;

        if (abs($raw) <= $band) {
            return ['premium' => $raw, 'creationValue' => 0.0];
        }

        // The AP absorbs whatever is past the edge, and the basket it has to trade to do it is that excess
        // converted back through the same pressure the flow exerted. Creating shares means BUYING the
        // basket, so a premium sends demand into the constituents and a discount takes it out of them.
        $edge = $raw > 0.0 ? $band : -$band;
        $excess = $raw - $edge;
        $creationValue = ($excess / FinancialConstants::ETF_FLOW_PRESSURE) * $netAssets;

        return ['premium' => $edge, 'creationValue' => $creationValue];
    }

    /**
     * Spreads a creation basket over the names it is made of.
     *
     * A creation is not an abstraction: the AP buys every constituent in index weight, and each of those
     * purchases pays its own impact in its own name. This is the channel that makes money going into a fund
     * lift what the fund holds, and it is why an index inclusion is felt by the company rather than only
     * recorded by the committee.
     *
     * @param array<string, float> $weights Index weight per ticker; need not be normalized.
     * @param array<string, float> $prices  Last price per ticker.
     * @return array<string, float> Signed SHARES to trade per ticker, on the same sign convention as the
     *         order-flow store: positive is buying.
     */
    public function basketOrders(float $creationValue, array $weights, array $prices): array
    {
        $total = array_sum($weights);

        if ($creationValue === 0.0 || $total <= 0.0) {
            return [];
        }

        $orders = [];

        foreach ($weights as $ticker => $weight) {
            $price = $prices[$ticker] ?? 0.0;

            if ($weight <= 0.0 || $price <= 0.0) {
                continue;
            }

            $shares = ($creationValue * ($weight / $total)) / $price;

            if ($shares !== 0.0) {
                $orders[$ticker] = $shares;
            }
        }

        return $orders;
    }
}
