<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;

/**
 * A one-shot reading of the Authority's balance sheet step and the curve it leaves, for tests that probe either from a
 * given state. It is not the engine's sequence, which applies the balance sheet, rolls the book coupon and moves the
 * term premium and expectations before it fits the curve (MacroEngine::step()); the state is left as given.
 */
final class YieldCurveProbe
{
    /**
     * @return array{
     *     new_balance_sheet_intensity: float,
     *     new_hold_timer: float,
     *     level: float,
     *     curvature: float,
     *     curvature2: float,
     *     beta1: float,
     *     base_term_premium: float,
     *     long_end_premium: float,
     *     structural_10y: float,
     *     yield_2y: float,
     *     yield_5y: float,
     *     yield_10y: float,
     *     yield_30y: float,
     *     risk_neutral_10y: float,
     *     term_premium_10y: float
     * } Decomposition containing yields, NSS factors, and QE/QT intensities.
     */
    public static function balanceSheetAndCurve(MonetaryPolicySubsystem $monetary, MacroState $state, float $targetInflation, float $naturalRate, float $dt): array
    {
        $balanceSheet = $monetary->calculateBalanceSheetOperations($state, $dt);

        $evaluated = clone $state;
        $evaluated->balanceSheetIntensity = $balanceSheet['new_balance_sheet_intensity'];

        return array_merge($balanceSheet, $monetary->calculateYieldCurve($evaluated, $targetInflation, $naturalRate));
    }
}
