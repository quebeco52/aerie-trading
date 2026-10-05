<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\DTO\MacroStateDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use App\Service\Politics\PoliticsEngine;

/**
 * The laws the market expects, in the price: a firm is worth its cash flows under the laws it expects over its life,
 * not under the ones its trailing earnings carry (Knight 2006: the Bush and Gore platforms were priced into the firms
 * they favoured as the odds moved; Snowberg, Wolfers & Zitzewitz 2007). Priced are the laws a firm's own accounts
 * answer to: the corporate tax, the bank levy, the rules on extraction a mine or field pays for in its unit costs, and
 * the stamp duty a broker's commissions thin with.
 *
 * The expected path runs from the laws in force, through the budget the sitting government will pass at its next round
 * and the next government's first budget as the market's forecast has it (App\Service\Politics\ElectionForecast), and
 * from the end of that government's term back toward the laws the District has in force on average, as far each term
 * as the Diet's own laws have drifted back from one term to the next. Each part counts for the share of the firm's
 * value accruing while it holds (MathUtility::perpetuityShareAfter()), the tax phasing in at the speed the tax rate
 * follows the law. A law is measured as the earnings feel it: the levy and the tax shift as they stand, the rules on
 * extraction by the cost factor they put on each unit, the duty by the turnover it leaves. What the trailing earnings
 * already carry is not counted again, so a law the market foresaw neither moves the price when it is passed nor when
 * the earnings take it in.
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
    /** The cost factor the rules on extraction put on each unit on average over the long run (MathUtility::calculateExtractionCostFactor()): 1.0035 (se 0.0006). */
    public const LONG_RUN_EXTRACTION_COST_FACTOR = 1.0035;
    /** Share of that factor's distance from its average still there a term later: 0.567 (sd across games 0.17). */
    public const EXTRACTION_COST_TERM_PERSISTENCE = 0.567;
    /** The share turnover the stamp duty leaves on average over the long run, against the founding duty's (MathUtility::calculateStampDutyVolumeFactor()): 0.949 (se 0.004). */
    public const LONG_RUN_STAMP_DUTY_VOLUME_FACTOR = 0.949;
    /** Share of that factor's distance from its average still there a term later: 0.360 (sd across games 0.13), the duty being no revenue the Council guards. */
    public const STAMP_DUTY_VOLUME_TERM_PERSISTENCE = 0.360;

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

        return self::expectedOverLife($macro, $capRate, $previous, 'corporateTax', $macro->corporateTaxPolicyShift, static fn (float $law): float => $law, self::LONG_RUN_CORPORATE_TAX_SHIFT, self::CORPORATE_TAX_TERM_PERSISTENCE, $speed)
            - $macro->corporateTaxShiftEmbodied
            // The rate still closing on the law in force.
            + (($macro->corporateTaxShiftRealized - $macro->corporateTaxPolicyShift) * (1.0 - MathUtility::perpetuityShareAfter($capRate, 0.0, $speed)));
    }

    /**
     * The bank levy the market expects over a bank's life, weighted the same way, less the levy its trailing earnings
     * carry. A levy is charged in full from the day it is passed.
     *
     * @param bool $previous Whether to read the forecast as it stood the tick before.
     */
    public static function bankLevyGap(MacroStateDTO $macro, float $capRate, bool $previous = false): float
    {
        return self::expectedOverLife($macro, $capRate, $previous, 'bankLevyRate', $macro->bankLevyRate, static fn (float $law): float => $law, self::LONG_RUN_BANK_LEVY_RATE, self::BANK_LEVY_TERM_PERSISTENCE)
            - $macro->bankLevyEmbodied;
    }

    /**
     * The cost factor the market expects the rules on extraction to put on each unit over a firm's life, weighted the
     * same way, less the factor its trailing earnings carry. The rules reach unit costs from the day they are passed.
     *
     * @param bool $previous Whether to read the forecast as it stood the tick before.
     */
    public static function extractionCostGap(MacroStateDTO $macro, float $capRate, bool $previous = false): float
    {
        return self::expectedOverLife($macro, $capRate, $previous, 'extractionStringency', $macro->extractionStringency, MathUtility::calculateExtractionCostFactor(...), self::LONG_RUN_EXTRACTION_COST_FACTOR, self::EXTRACTION_COST_TERM_PERSISTENCE)
            - $macro->extractionCostFactorEmbodied;
    }

    /**
     * The share turnover the market expects the stamp duty to leave over a firm's life, weighted the same way, less the
     * turnover factor its trailing earnings carry. A duty thins turnover from the day it is passed.
     *
     * @param bool $previous Whether to read the forecast as it stood the tick before.
     */
    public static function stampDutyVolumeGap(MacroStateDTO $macro, float $capRate, bool $previous = false): float
    {
        return self::expectedOverLife($macro, $capRate, $previous, 'stampDutyRate', $macro->stampDutyRate, MathUtility::calculateStampDutyVolumeFactor(...), self::LONG_RUN_STAMP_DUTY_VOLUME_FACTOR, self::STAMP_DUTY_VOLUME_TERM_PERSISTENCE)
            - $macro->stampDutyVolumeFactorEmbodied;
    }

    /**
     * What the laws expected beyond the ones the trailing earnings carry add to a year's earnings after tax, per share:
     * less the levy, which is not deductible, and less the extraction rules' extra cost and plus the duty's extra
     * turnover, both before tax.
     *
     * @param array<string, float> $basesPerShare What each law is charged on or moves, per share, keyed by lever
     *                                            (OperatingStrategyInterface::annualBankLevyBase(), annualExtractionCostBase(),
     *                                            annualStampDutyTurnoverBase()).
     * @param float $effectiveTaxExpected The firm's effective tax rate at the rate in force plus the expected shift's gap.
     * @param bool  $previous             Whether to read the forecast as it stood the tick before.
     */
    public static function earningsGap(MacroStateDTO $macro, array $basesPerShare, float $effectiveTaxExpected, float $capRate, bool $previous = false): float
    {
        $gap = 0.0;
        if (($basesPerShare['bankLevyRate'] ?? 0.0) > 0.0) {
            $gap -= self::bankLevyGap($macro, $capRate, $previous) * $basesPerShare['bankLevyRate'];
        }
        if (($basesPerShare['extractionStringency'] ?? 0.0) > 0.0) {
            $gap -= (1.0 - $effectiveTaxExpected) * self::extractionCostGap($macro, $capRate, $previous) * $basesPerShare['extractionStringency'];
        }
        if (($basesPerShare['stampDutyRate'] ?? 0.0) > 0.0) {
            $gap += (1.0 - $effectiveTaxExpected) * self::stampDutyVolumeGap($macro, $capRate, $previous) * $basesPerShare['stampDutyRate'];
        }

        return $gap;
    }

    /**
     * A fair value struck on trailing earnings, repriced for the laws expected: after-tax earnings scaled from the
     * effective rate they carry to the one expected, plus the present value of the earnings the other laws expected
     * add or take beyond the ones carried.
     *
     * @param float $effectiveTaxCarried  The firm's effective tax rate at the rate in force.
     * @param float $effectiveTaxExpected Its effective tax rate at the rate in force plus the expected shift's gap.
     * @param float $earningsGap          A year's after-tax earnings per share the other laws expected add (earningsGap()).
     */
    public static function reprice(float $fairValue, float $effectiveTaxCarried, float $effectiveTaxExpected, float $earningsGap, float $capRate): float
    {
        $afterTax = (1.0 - $effectiveTaxExpected) / max(0.01, 1.0 - $effectiveTaxCarried);

        return max(0.01, ($fairValue * $afterTax) + ($earningsGap / $capRate));
    }

    /**
     * A law as the market expects it over a firm's life, measured as the earnings feel it: the law in force, the sitting
     * government's coming budget's from its round, the next government's from its first budget, and from the end of
     * that government's term the drift back toward the long-run law, each counting for the share of the firm's value
     * accruing after it starts.
     *
     * @param string                $lever       The lever, keyed as PoliticsEngine::LEVER_FIELDS.
     * @param float                 $law         The law in force.
     * @param \Closure(float): float $measure     The law as the earnings feel it.
     * @param float                 $longRun     The measured law's long-run average.
     * @param float                 $persistence Share of the measured law's distance from that average still there a term later.
     * @param float                 $speed       Speed at which a change reaches its full size once started, a year; INF for at once.
     */
    private static function expectedOverLife(MacroStateDTO $macro, float $capRate, bool $previous, string $lever, float $law, \Closure $measure, float $longRun, float $persistence, float $speed = INF): float
    {
        $inForce = $measure($law);
        [$sitting, $sittingFrom, $next, $nextFrom] = self::path($macro, $previous, $lever, $inForce, $measure);
        $fromNext = MathUtility::perpetuityShareAfter($capRate, $nextFrom - $macro->totalTime, $speed);

        return $inForce
            + (($sitting - $inForce) * MathUtility::perpetuityShareAfter($capRate, $sittingFrom - $macro->totalTime, $speed))
            + (($next - $sitting) * $fromNext)
            - (($next - $longRun) * $fromNext * self::undoneLater($capRate, $persistence));
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
     * The laws ahead and when, as they stand this tick or stood the tick before, measured as the earnings feel them: the
     * sitting government's coming budget, then the next government's first. Without a reading of the first, the law in
     * force stands until the second; without a forecast, the sitting government's stands until the long-run drift takes
     * over a term on.
     *
     * @param \Closure(float): float $measure The law as the earnings feel it.
     * @return array{0: float, 1: float, 2: float, 3: float} The sitting law and when, the next law and when.
     */
    private static function path(MacroStateDTO $macro, bool $previous, string $lever, float $inForce, \Closure $measure): array
    {
        $sittingLevers = $previous ? $macro->previousSittingLevers : $macro->sittingLevers;
        $sittingFrom = $previous ? $macro->previousSittingPolicyFrom : $macro->sittingPolicyFrom;
        $sittingLaw = $sittingFrom < 0.0 || !isset($sittingLevers[$lever]) ? $inForce : $measure($sittingLevers[$lever]);
        $sittingFrom = $sittingFrom < 0.0 ? $macro->totalTime : $sittingFrom;

        $expectedLevers = $previous ? $macro->previousExpectedLevers : $macro->expectedLevers;
        $nextFrom = $previous ? $macro->previousExpectedPolicyFrom : $macro->expectedPolicyFrom;
        if ($nextFrom < 0.0 || !isset($expectedLevers[$lever])) {
            return [$sittingLaw, $sittingFrom, $sittingLaw, $sittingFrom];
        }

        return [$sittingLaw, $sittingFrom, $measure($expectedLevers[$lever]), max($sittingFrom, $nextFrom)];
    }
}
