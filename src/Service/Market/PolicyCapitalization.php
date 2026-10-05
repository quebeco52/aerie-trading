<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\DTO\MacroStateDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use App\Service\Politics\PoliticsEngine;

/**
 * The laws the market expects, in the price: a firm is worth its cash flows under the corporate tax and the bank levy
 * it expects over its life, not under the ones its trailing earnings carry (Knight 2006: the Bush and Gore platforms
 * were priced into the firms they favoured as the odds moved; Snowberg, Wolfers & Zitzewitz 2007).
 *
 * The expected path runs from the laws in force, through the budget the sitting government will pass at its next round
 * and the next government's first budget as the market's forecast has it (App\Service\Politics\ElectionForecast), and
 * from the end of that government's term back toward the laws the
 * District has in force on average, as far each term as the Diet's own laws have drifted back from one term to the
 * next. Each part counts for the share of the firm's value accruing while it holds (MathUtility::perpetuityShareAfter()),
 * the tax phasing in at the speed the tax rate follows the law. What the trailing earnings already carry is not counted
 * again, so a law the market foresaw neither moves the price when it is passed nor when the earnings take it in.
 */
final class PolicyCapitalization
{
    // --- Past the Next Term (the Diet's own long runs: 16 games of 200 years on the full economy, var/harness/polls/lever_means.php) ---
    /** The corporate tax shift in force on average over the long run: +0.47 points (se 0.23). */
    public const LONG_RUN_CORPORATE_TAX_SHIFT = 0.0047;
    /** Share of the corporate tax shift's distance from that average still there a term later: its four-year autocorrelation, 0.662 (sd across games 0.14). */
    public const CORPORATE_TAX_TERM_PERSISTENCE = 0.662;
    /** The bank levy in force on average over the long run: 0.118% (se 0.006%). */
    public const LONG_RUN_BANK_LEVY_RATE = 0.00118;
    /** Share of the bank levy's distance from that average still there a term later: 0.660 (sd across games 0.14). */
    public const BANK_LEVY_TERM_PERSISTENCE = 0.660;

    // --- Discounting ---
    /** Smallest discount rate less growth a firm's value is spread at, the floor the intrinsic multiple puts under its spread (MathUtility::calculateIntrinsicFairValuePE()). */
    public const MIN_CAP_RATE = 0.005;

    /**
     * The corporate tax shift the market expects over a firm's life, each law weighted by the share of the firm's value
     * accruing while it holds, less the shift the trailing earnings carry.
     *
     * @param bool $previous Whether to read the forecast as it stood the tick before.
     */
    public static function corporateTaxShiftGap(MacroStateDTO $macro, float $capRate, bool $previous = false): float
    {
        $speed = MacroEngine::FISCAL_ADJUSTMENT_SPEED;
        $inForce = $macro->corporateTaxPolicyShift;
        [$sitting, $sittingFrom, $next, $nextFrom] = self::path(
            $macro,
            $previous,
            $inForce,
            [$macro->sittingCorporateTaxPolicyShift, $macro->previousSittingCorporateTaxPolicyShift],
            [$macro->expectedCorporateTaxPolicyShift, $macro->previousExpectedCorporateTaxPolicyShift]
        );
        $fromNext = MathUtility::perpetuityShareAfter($capRate, $nextFrom - $macro->totalTime, $speed);

        return ($inForce - $macro->corporateTaxShiftEmbodied)
            // The rate still closing on the law in force.
            + (($macro->corporateTaxShiftRealized - $inForce) * (1.0 - MathUtility::perpetuityShareAfter($capRate, 0.0, $speed)))
            + (($sitting - $inForce) * MathUtility::perpetuityShareAfter($capRate, $sittingFrom - $macro->totalTime, $speed))
            + (($next - $sitting) * $fromNext)
            - (($next - self::LONG_RUN_CORPORATE_TAX_SHIFT) * $fromNext * self::undoneLater($capRate, self::CORPORATE_TAX_TERM_PERSISTENCE));
    }

    /**
     * The bank levy the market expects over a bank's life, weighted the same way, less the levy its trailing earnings
     * carry. A levy is charged in full from the day it is passed.
     *
     * @param bool $previous Whether to read the forecast as it stood the tick before.
     */
    public static function bankLevyGap(MacroStateDTO $macro, float $capRate, bool $previous = false): float
    {
        $inForce = $macro->bankLevyRate;
        [$sitting, $sittingFrom, $next, $nextFrom] = self::path(
            $macro,
            $previous,
            $inForce,
            [$macro->sittingBankLevyRate, $macro->previousSittingBankLevyRate],
            [$macro->expectedBankLevyRate, $macro->previousExpectedBankLevyRate]
        );
        $fromNext = MathUtility::perpetuityShareAfter($capRate, $nextFrom - $macro->totalTime);

        return ($inForce - $macro->bankLevyEmbodied)
            + (($sitting - $inForce) * MathUtility::perpetuityShareAfter($capRate, $sittingFrom - $macro->totalTime))
            + (($next - $sitting) * $fromNext)
            - (($next - self::LONG_RUN_BANK_LEVY_RATE) * $fromNext * self::undoneLater($capRate, self::BANK_LEVY_TERM_PERSISTENCE));
    }

    /**
     * A fair value struck on trailing earnings, repriced for the laws expected: after-tax earnings scaled from the
     * effective rate they carry to the one expected, less the present value of the levy expected beyond the one carried.
     *
     * @param float $effectiveTaxCarried  The firm's effective tax rate at the rate in force.
     * @param float $effectiveTaxExpected Its effective tax rate at the rate in force plus the expected shift's gap.
     * @param float $levyGap              The expected levy less the one carried (bankLevyGap()).
     * @param float $levyBasePerShare     What the levy is charged on, per share (annualBankLevyBase()).
     */
    public static function reprice(float $fairValue, float $effectiveTaxCarried, float $effectiveTaxExpected, float $levyGap, float $levyBasePerShare, float $capRate): float
    {
        $afterTax = (1.0 - $effectiveTaxExpected) / max(0.01, 1.0 - $effectiveTaxCarried);

        return max(0.01, ($fairValue * $afterTax) - ($levyGap * $levyBasePerShare / $capRate));
    }

    /**
     * How much of the next government's departure from the long-run law the governments after it undo, in value from
     * the day it takes effect: each term keeps the persistence of the last's departure, so the k-th term after holds
     * rho^k of it, and the steps down, each at a term's discount further, sum to (1 - rho) e^{-cT} / (1 - rho e^{-cT}).
     */
    private static function undoneLater(float $capRate, float $persistence): float
    {
        $termDiscount = exp(-$capRate * PoliticsEngine::ELECTION_TERM_YEARS);

        return (1.0 - $persistence) * $termDiscount / (1.0 - ($persistence * $termDiscount));
    }

    /**
     * The laws ahead and when, as they stand this tick or stood the tick before: the sitting government's coming budget,
     * then the next government's first. Without a reading of the first, the laws in force stand until the second; without
     * a forecast, the sitting government's stand until the long-run drift takes over a term on.
     *
     * @param array{0: float, 1: float} $sitting  The sitting government's law, this tick and the tick before.
     * @param array{0: float, 1: float} $expected The next government's law, this tick and the tick before.
     * @return array{0: float, 1: float, 2: float, 3: float} The sitting law and when, the next law and when.
     */
    private static function path(MacroStateDTO $macro, bool $previous, float $inForce, array $sitting, array $expected): array
    {
        $sittingFrom = $previous ? $macro->previousSittingPolicyFrom : $macro->sittingPolicyFrom;
        $sittingLaw = $sittingFrom < 0.0 ? $inForce : $sitting[$previous ? 1 : 0];
        $sittingFrom = $sittingFrom < 0.0 ? $macro->totalTime : $sittingFrom;

        $nextFrom = $previous ? $macro->previousExpectedPolicyFrom : $macro->expectedPolicyFrom;
        if ($nextFrom < 0.0) {
            return [$sittingLaw, $sittingFrom, $sittingLaw, $sittingFrom];
        }

        return [$sittingLaw, $sittingFrom, $expected[$previous ? 1 : 0], max($sittingFrom, $nextFrom)];
    }
}
