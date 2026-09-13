<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * The fitted sovereign term structure as of one tick: the Nelson-Siegel-Svensson factors, the term premium
 * components, and the central bank duration extraction that shifts them.
 *
 * MacroState publishes the benchmark 2y/5y/10y/30y points, but a bond rarely has a benchmark maturity left:
 * a ten-year issued three years ago is a seven-year, and next quarter it is a 6.75. Discounting that from the
 * four quoted points means interpolating a curve that has a published functional form, so this carries the
 * factors themselves and the desk evaluates the same function the macro engine did.
 *
 * The slope here is the fitted beta1 (policy rate minus level), NOT MacroState::$nsSlope, which is the
 * 10y-minus-policy-rate reporting metric. Feeding the reporting slope into the curve function produces a
 * plausible-looking curve that reprices nothing correctly.
 */
final readonly class SovereignCurveDTO
{
    /**
     * @param float $level                 Asymptotic long-term yield level (beta0).
     * @param float $slope                 Fitted short-rate slope (beta1), policy rate minus level.
     * @param float $curvature1            Medium-term hump (beta2).
     * @param float $curvature2            Long-term secondary hump (beta3).
     * @param float $baseTermPremium       Ten-year term premium before duration scaling.
     * @param float $longEndPremium        Structural premium accruing past the ten-year point.
     * @param float $balanceSheetIntensity QE (positive) or QT (negative) duration extraction.
     */
    public function __construct(
        public float $level,
        public float $slope,
        public float $curvature1,
        public float $curvature2,
        public float $baseTermPremium,
        public float $longEndPremium,
        public float $balanceSheetIntensity,
    ) {}
}
