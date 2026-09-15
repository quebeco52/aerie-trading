<?php

declare(strict_types=1);

namespace App\Service\Market\Index;

/**
 * What an index ranks its eligible universe on when it decides who is in.
 *
 * Selection and weighting are separate decisions and are declared separately. A sector index selects on
 * membership of a sector and then weights by size; a low-volatility index selects on quiet and then weights
 * on quiet as well. Conflating the two is what makes an index framework only able to express one index.
 *
 * Every ranking here is stated BEST FIRST, so the banding that protects a sitting member from being evicted
 * by ranking noise is one comparison whatever the index is measuring.
 */
enum IndexRanking: string
{
    case FloatCapitalisation = 'float_cap';
    case TrailingVolatility = 'trailing_volatility';

    /** Plain-language name for the factsheet. */
    public function label(): string
    {
        return match ($this) {
            self::FloatCapitalisation => 'Largest float-adjusted capitalisation',
            self::TrailingVolatility => 'Lowest trailing volatility',
        };
    }
}
