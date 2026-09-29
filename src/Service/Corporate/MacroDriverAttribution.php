<?php

declare(strict_types=1);

namespace App\Service\Corporate;

use App\DTO\MacroStateDTO;
use App\Service\Model\BusinessModelInterface;

/**
 * Measures what each macro input did to a firm's revenue streams this quarter, by the firm's own model rather than by
 * an estimate beside it. For each input the model declares it reads, the quarter is re-run with that one input at its
 * neutral reading and the quarter's own random draws; the difference in each stream's revenue is the input's
 * contribution. A one-at-a-time counterfactual, so contributions that interact need not sum to the total move.
 *
 * The neutral reading is the steady state the economy opens in (MacroStateDTO's defaults), except for a level series
 * that trends, whose neutral is the trend it is measured against: resetting it to its opening level would attribute
 * every year of growth since to the business cycle.
 */
final class MacroDriverAttribution
{
    // --- Neutral Readings ---
    /** Trending level series and the trend each is neutral at: nominal output at potential, equity wealth at its trend. */
    public const NEUTRAL_TREND = [
        'nominal_gdp_index' => 'potential_gdp_index',
        'equity_wealth_ratio' => 'equity_wealth_trend',
    ];

    /** Trend series: the reference other readings are measured against, never a driver of their own. */
    public const TREND_FIELDS = ['equity_wealth_trend'];

    /** @var array<string, mixed>|null */
    private static ?array $steadyState = null;

    /**
     * The quarter's macro state with one input at its neutral reading, for each input the model declares that is
     * away from it this quarter.
     *
     * @return array<string, MacroStateDTO> Field => the counterfactual state.
     */
    public static function counterfactualStates(BusinessModelInterface $strategy, MacroStateDTO $macroState): array
    {
        $actual = $macroState->toArray();
        $states = [];

        foreach ($strategy->getOperatingMacroFields() as $field) {
            $neutral = self::neutralReading($field, $actual);
            if ($neutral === null || $neutral === $actual[$field]) {
                continue;
            }

            $states[$field] = MacroStateDTO::fromArray(array_replace($actual, [$field => $neutral]));
        }

        return $states;
    }

    /**
     * The reading an input is neutral at, or null for one that is no driver: a trend, or a field the state does not
     * carry as a number or a flag.
     *
     * @param array<string, mixed> $actual MacroStateDTO::toArray() of the quarter.
     */
    public static function neutralReading(string $field, array $actual): int|float|bool|null
    {
        if (in_array($field, self::TREND_FIELDS, true) || !array_key_exists($field, $actual)) {
            return null;
        }

        if (isset(self::NEUTRAL_TREND[$field])) {
            return $actual[self::NEUTRAL_TREND[$field]] ?? null;
        }

        self::$steadyState ??= (new MacroStateDTO())->toArray();
        $neutral = self::$steadyState[$field] ?? null;

        return is_int($neutral) || is_float($neutral) || is_bool($neutral) ? $neutral : null;
    }
}
