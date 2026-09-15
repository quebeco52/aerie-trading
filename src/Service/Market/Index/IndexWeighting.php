<?php

declare(strict_types=1);

namespace App\Service\Market\Index;

/**
 * How an index divides itself between its constituents.
 *
 * A weighting scheme is the whole substance of an index beyond its membership: the same thirty companies
 * weighted two different ways are two different investments, with different risk, different turnover and
 * different returns. So it is declared per index rather than assumed, and the committee strikes the target
 * weights from it at each review.
 *
 * Whatever the scheme, the index RUNS as a modified capitalisation index between reviews. The committee
 * converts each target weight into an adjusted-weight factor on the constituent's float-adjusted
 * capitalisation, and the level is then struck from those adjusted capitalisations exactly as a cap-weighted
 * index is. That is how S&P computes every non-cap-weighted index it publishes, and it is what makes the
 * weights DRIFT with prices between reviews instead of being silently rebalanced every tick — a fund that
 * rebalanced continuously would be selling every winner and buying every loser at no cost, which is not a
 * portfolio anyone can actually run.
 */
enum IndexWeighting: string
{
    case FloatCapitalisation = 'float_cap';
    case InverseVolatility = 'inverse_volatility';

    /** Plain-language name for the factsheet. */
    public function label(): string
    {
        return match ($this) {
            self::FloatCapitalisation => 'Float-adjusted capitalisation',
            self::InverseVolatility => 'Inverse volatility',
        };
    }

    /** What the scheme does and why, for the methodology card. */
    public function description(): string
    {
        return match ($this) {
            self::FloatCapitalisation => 'Each constituent weighs what the tradable part of it is worth. The weights need no maintenance between reviews: as a price moves, the constituent\'s capitalisation moves with it and the weight stays correct on its own.',
            self::InverseVolatility => 'Each constituent weighs the reciprocal of its trailing volatility, so the quietest names carry the most and the fund equalises risk contributions rather than capital. Weights are struck at each review and then drift with prices until the next one.',
        };
    }
}
