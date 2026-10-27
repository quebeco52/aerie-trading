<?php

declare(strict_types=1);

namespace App\Service\View;

/**
 * What moved a share price, split three ways as a terminal's return attribution does: the market, the company's peers
 * beyond the market, and the company itself. Weekly returns are regressed on the market and on the peers' return net of
 * the market (so the two parts do not overlap); a period's move is then each beta times what that factor did, and the
 * company's part is what is left.
 */
final class ReturnAttribution
{
    // --- Estimation ---
    /** Fewest weekly returns the betas are estimated from: half a year. */
    public const MIN_WEEKS = 26;

    /**
     * The newest $window weeks' move split three ways, in simple returns that add up to the period's total; and the
     * market beta. Null with too few weeks, or with a market that never moved.
     *
     * @param  list<float> $own    the company's weekly log returns, oldest first
     * @param  list<float> $market the market's, the same weeks
     * @param  list<float> $peers  the peers', the same weeks
     * @return array{total: float, market: float, peers: float, company: float, beta: float}|null
     */
    public static function attribute(array $own, array $market, array $peers, int $window): ?array
    {
        $n = count($own);
        if ($n < self::MIN_WEEKS || $n !== count($market) || $n !== count($peers) || $window < 1) {
            return null;
        }
        $peerBeta = self::slope($peers, $market);
        $beta = self::slope($own, $market);
        if ($peerBeta === null || $beta === null) {
            return null;
        }
        $peerOwn = array_map(static fn(float $p, float $m): float => $p - $peerBeta * $m, $peers, $market);
        $peerLoading = self::slope($own, $peerOwn) ?? 0.0;

        $recent = max(0, $n - $window);
        $total = array_sum(array_slice($own, $recent));
        $marketPart = $beta * array_sum(array_slice($market, $recent));
        $peerPart = $peerLoading * array_sum(array_slice($peerOwn, $recent));

        // Log parts add up; the simple total does not split. Each part is scaled by the same ratio so the three add
        // to the simple total shown beside them.
        $simple = exp($total) - 1.0;
        $scale = abs($total) < 1e-9 ? 1.0 : $simple / $total;

        return [
            'total' => $simple,
            'market' => $scale * $marketPart,
            'peers' => $scale * $peerPart,
            'company' => $scale * ($total - $marketPart - $peerPart),
            'beta' => $beta,
        ];
    }

    /**
     * Least-squares slope of $y on $x with an intercept, or null when $x does not vary.
     *
     * @param list<float> $y
     * @param list<float> $x
     */
    private static function slope(array $y, array $x): ?float
    {
        $n = count($x);
        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;
        $sxx = 0.0;
        $sxy = 0.0;
        foreach ($x as $i => $xi) {
            $sxx += ($xi - $meanX) ** 2;
            $sxy += ($xi - $meanX) * ($y[$i] - $meanY);
        }

        return $sxx > 1e-18 ? $sxy / $sxx : null;
    }
}
