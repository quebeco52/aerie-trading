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
 * The neutral reading is the steady state the economy opens in (MacroStateDTO's defaults), except for a series measured
 * against a trend, whose neutral is that trend: resetting nominal output to its opening level would attribute every
 * year of growth since to the business cycle.
 */
final class MacroDriverAttribution
{
    // --- Neutral Readings ---
    /**
     * Series and the trend each is neutral at: nominal output at potential, equity wealth at its trend, the IG, HY and
     * interbank spreads at their decade averages, which their readers measure them against rather than the base they
     * are built from.
     */
    public const NEUTRAL_TREND = [
        'nominal_gdp_index' => 'potential_gdp_index',
        'equity_wealth_ratio' => 'equity_wealth_trend',
        'macro_credit_spread' => 'macro_credit_spread_trend',
        'macro_credit_spread_ema' => 'macro_credit_spread_trend',
        'interbank_liquidity_spread_ema' => 'interbank_liquidity_spread_trend',
        'high_yield_credit_spread_ema' => 'high_yield_credit_spread_trend',
    ];

    /**
     * Smoothed series and their spot, for a model that reads both: one driver in two parts, the smoothed level over its
     * trend and the spot's move off that level. The level's counterfactual carries the spot with it and the move's
     * holds the spot at the level, so neither invents the other and the two add up to the spot over its trend.
     */
    public const SMOOTHED_SPOT = ['macro_credit_spread_ema' => 'macro_credit_spread'];

    /**
     * Trend series and the price level: references other readings are measured against, never a driver of their own.
     */
    public const TREND_FIELDS = ['equity_wealth_trend', 'residential_wealth_trend', 'consumer_price_level', 'energy_price_index_decade_trend'];

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
        $fields = $strategy->getOperatingMacroFields();
        $states = [];

        foreach ($fields as $field) {
            $readings = self::counterfactualReadings($field, $actual, $fields);
            if ($readings !== null) {
                $states[$field] = MacroStateDTO::fromArray(array_replace($actual, $readings));
            }
        }

        return $states;
    }

    /**
     * The readings one input's counterfactual replaces, or null when the input is no driver or sits at its neutral: the
     * input at its neutral reading, split into level and move beside its smoothed series or spot (SMOOTHED_SPOT).
     *
     * @param array<string, mixed> $actual MacroStateDTO::toArray() of the quarter.
     * @param list<string>         $fields The inputs the model reads.
     * @return array<string, int|float|bool>|null
     */
    public static function counterfactualReadings(string $field, array $actual, array $fields): ?array
    {
        $smoothed = array_search($field, self::SMOOTHED_SPOT, true);
        $neutral = is_string($smoothed) && in_array($smoothed, $fields, true) && is_float($actual[$smoothed] ?? null)
            ? $actual[$smoothed]
            : self::neutralReading($field, $actual);
        if ($neutral === null || !array_key_exists($field, $actual) || $neutral === $actual[$field]) {
            return null;
        }

        $readings = [$field => $neutral];
        $spot = self::SMOOTHED_SPOT[$field] ?? null;
        if ($spot !== null && in_array($spot, $fields, true) && is_float($actual[$spot] ?? null) && is_float($neutral) && is_float($actual[$field])) {
            $readings[$spot] = $actual[$spot] + ($neutral - $actual[$field]);
        }

        return $readings;
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
