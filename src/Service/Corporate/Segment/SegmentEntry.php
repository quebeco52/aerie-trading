<?php

declare(strict_types=1);

namespace App\Service\Corporate\Segment;

use App\Data\Company\IndustryOutlooks;
use App\Service\Math\Distributions;
use App\Service\Math\FinancialConstants;
use App\Service\Math\Valuation;
use App\Service\Model\BusinessModelInterface;

/**
 * A company entering an industry it is not in. It looks only next door (IndustryAdjacency), benchmarks the incumbents
 * there, and values a venture as what its plant will earn once it has learned the trade, less what learning costs it
 * first: the further from what it knows, the less of its rivals' margin it ever earns and the longer it loses money.
 * Whether it goes is a rare event its spare cash makes possible, so entry comes when a company has the means and a
 * market worth the stretch, not on a schedule. A segment entered in play (Segments::enteredKey()) is booked like any
 * other (SegmentBooks); what is its own is the plant it still has under construction and the margin it has yet to
 * learn.
 */
final class SegmentEntry
{
    // --- Choosing a Market ---
    /**
     * Share of its own plant a company builds into a new industry: 3%, enough to matter to it, while its first year's
     * learning costs it ~5% of a typical company's operating income.
     */
    public const VENTURE_PLANT_SHARE = 0.03;
    /**
     * Most of its new market a venture is built to serve: 5%, a scale an entrant can win, so a large company entering a
     * small market builds for that market and not for its own size (Biggadike 1979: scale against the market is what
     * decides an entry).
     */
    public const MAX_VENTURE_MARKET_SHARE = 0.05;
    /**
     * How much of the incumbents' margin a company gives up for each step away from what it knows: once learned it
     * earns their margin times (1 - 0.2 x (1 - closeness)), 96% next door and 85% on a stretch. Berger & Ofek (1995)
     * find diversified firms ~13-15% below standalone peers, a gap smaller where the businesses are related and partly
     * selection (Graham, Lemmon & Wolf 2002).
     */
    public const ATTAINMENT_DISTANCE_PENALTY = 0.2;
    /**
     * What each step away from what it knows really costs a company of its rivals' margin, whether it builds or buys:
     * three times what a builder counts on, so a venture next door turns out ~8% below its plan on average and one on a
     * stretch ~35% below, and a business bought in a related market earns ~70% of what it did and one on a stretch
     * ~55%, so about twice as many of those are sold again within a decade (Kaplan & Weisbach 1992: ~44% of large
     * acquisitions were sold within a decade, diversifying ones several times as often as related ones). Managers
     * overrate how far their skills carry (Roll 1986), the more so the further from home: diversifying bidders lose
     * value (Morck, Shleifer & Vishny 1990) and firms leave the businesses least like their own (Chang 1996).
     */
    public const REALISED_DISTANCE_PENALTY = 0.6;
    /**
     * Rate per year at which a company with the cash and a market worth entering goes ahead, before its manager's
     * appetite for deals: 0.015, about once in sixty-five years, as entry by listed firms is rare (Chang 1996; Miller &
     * Yang 2016).
     */
    public const ENTRY_RATE = 0.015;
    /** Years of play before any company enters: a year of reports has filed what each industry's plant earns. */
    public const FIRST_ENTRY_YEARS = 1.0;

    // --- How It Turns Out ---
    /**
     * How widely what a business entered in play turns out to earn spreads around what its company expects, as a log
     * standard deviation of its margin once learned or integrated: 0.3, about the spread of margins among one
     * industry's firms, at any distance. Distance lowers what it turns out to earn (REALISED_DISTANCE_PENALTY), not how
     * widely, so no entrant keeps a margin far above every incumbent's.
     */
    public const OUTCOME_SPREAD = 0.3;

    // --- Buying In ---
    /**
     * Years over which what a business bought into a new industry earns under its new owner shows: 2, the time a
     * buyer takes to integrate a target and find out what it has bought, after which the business is judged on what it
     * earns.
     */
    public const INTEGRATION_YEARS = 2.0;

    // --- Growing into the Market ---
    /**
     * Years over which a venture grows toward the share of its new market it means to reach, e-folding: 5, so it has
     * gone ~85% of the way within a decade (Biggadike 1979: the ventures that built share fast fared best).
     */
    public const VENTURE_GROWTH_YEARS = 5.0;
    /**
     * Fastest a venture grows toward its target share, in log a year: 0.2, about 22% a year, as far as a company can
     * hire, train and sell for a new business (Penrose 1959: the managers a firm has bound how fast it can grow). Over
     * the growth years it bounds the target too: a venture means to reach no more than e, ~2.7 times, the share it
     * entered at.
     */
    public const MAX_VENTURE_GROWTH = 0.2;

    // --- Learning the Trade ---
    /**
     * Years over which a business built into a new industry closes its gap to the margin it will earn once it has
     * learned the trade, e-folding: 3, so a venture starting three to four and a half times that margin short loses
     * money for three to four and a half years and reaches 90% of that margin in ten to eleven (Biggadike 1979: 68
     * corporate ventures lost heavily through their first four years and took ten to twelve to match their parents).
     */
    public const LEARNING_YEARS = 3.0;
    /** How far short of its learned margin a venture next door starts, as a share of that margin. */
    public const LEARNING_GAP_BASE = 2.5;
    /** What each step away from what it knows adds to that: a stretch starts ~4.4 times short. */
    public const LEARNING_GAP_DISTANCE = 2.5;

    /** The share of its learned margin a business still falls short by after dt more years of learning. */
    public static function learningLeft(float $gap, float $dt): float
    {
        return $gap * exp(-$dt / self::LEARNING_YEARS);
    }

    /** How far short of its learned margin a business built into an industry this close to its own starts. */
    public static function learningGap(float $closeness): float
    {
        return self::LEARNING_GAP_BASE + (self::LEARNING_GAP_DISTANCE * (1.0 - $closeness));
    }

    /**
     * The plant a venture is built with: its share of the company's plant, or the plant that serves its share of its
     * new market at the turnover there, whichever is less.
     */
    public static function ventureConstruction(float $plantBase, float $marketRevenue, float $turnover): float
    {
        return min(self::VENTURE_PLANT_SHARE * max(0.0, $plantBase), $turnover > 0.0 ? self::MAX_VENTURE_MARKET_SHARE * max(0.0, $marketRevenue) / $turnover : 0.0);
    }

    /** The margin a company earns in an industry this close to its own once it has learned the trade. */
    public static function attainedMargin(float $peerMargin, float $closeness): float
    {
        return $peerMargin * (1.0 - (self::ATTAINMENT_DISTANCE_PENALTY * (1.0 - $closeness)));
    }

    /**
     * Whether a business can be built in an industry and run on plant whose output its market prices: not a financial
     * book, not output sold at a world price or charged by units produced, and an industry whose outlook the board
     * tracks.
     */
    public static function isEnterable(string $industry, BusinessModelInterface $strategy): bool
    {
        return !$strategy->isFinancial() && $strategy->getIndustrySubstitutability() > 0.0 && !$strategy->depreciatesByUnitsOfProduction()
            && $strategy->allowsPhysicalOrganicCapex() && IndustryOutlooks::driftVol($industry) > 0.0;
    }

    /**
     * What a business in a new industry is worth over its cost, per unit of its plant (Valuation::ventureValue()): its
     * return on that plant once learned against its company's hurdle, growing at the economy's long-run rate plus its
     * industry's secular excess as that fades, less the first year's learning cost as that fades with learning. A
     * business bought as a going concern has none to learn.
     */
    public static function value(float $learnedReturn, float $hurdle, float $longRunGrowth, float $excessGrowth, float $learningCost): float
    {
        return Valuation::ventureValue($learnedReturn, $hurdle, $longRunGrowth, $excessGrowth, FinancialConstants::SECULAR_EXCESS_HALF_LIFE_YEARS / M_LN2, $learningCost, self::LEARNING_YEARS);
    }

    /** The chance a company that could enter goes ahead over dt years, at its manager's appetite for deals. */
    public static function entryChance(float $appetite, float $dt): float
    {
        return 1.0 - exp(-self::ENTRY_RATE * max(0.0, $appetite) * max(0.0, $dt));
    }

    /**
     * What a venture turns out to earn once learned against what its company expects, in log: a lognormal draw whose
     * mean is the margin it really attains, which falls short of the one counted on by more the further it is from home
     * (REALISED_DISTANCE_PENALTY), so each venture surprises and distant ones disappoint.
     *
     * @param float $uniform A draw on (0, 1).
     */
    public static function outcome(float $closeness, float $uniform): float
    {
        $spread = self::OUTCOME_SPREAD;
        $shortfall = log((1.0 - (self::REALISED_DISTANCE_PENALTY * (1.0 - $closeness))) / (1.0 - (self::ATTAINMENT_DISTANCE_PENALTY * (1.0 - $closeness))));

        return $shortfall + ($spread * Distributions::standardNormalQuantile($uniform)) - ($spread * $spread / 2.0);
    }

    /**
     * What a business bought into an industry this close to its buyer's turns out to earn against what its buyer counts
     * on, in log: the buyer counts on what the business earned before, as a going concern brings its trade with it, but
     * under an owner further from what it knows it keeps less of its rivals' margin (REALISED_DISTANCE_PENALTY), the
     * gap that makes diversifying acquisitions the ones sold again (Kaplan & Weisbach 1992: ~44% of large acquisitions
     * were sold within a decade, diversifying ones ~4 times as often as related ones); spread as a venture's outcome
     * is.
     *
     * @param float $uniform A draw on (0, 1).
     */
    public static function boughtOutcome(float $closeness, float $uniform): float
    {
        $spread = self::OUTCOME_SPREAD;

        return log(1.0 - (self::REALISED_DISTANCE_PENALTY * (1.0 - $closeness))) + ($spread * Distributions::standardNormalQuantile($uniform)) - ($spread * $spread / 2.0);
    }

    /** The share of what a bought business will turn out to earn still to show after dt more years of integration. */
    public static function integrationLeft(float $unshown, float $dt): float
    {
        return max(0.0, $unshown - (max(0.0, $dt) / self::INTEGRATION_YEARS));
    }

    /**
     * How far a venture means to grow its share of its new market, in log: to the share a typical incumbent there holds
     * (Biggadike 1979: entrants that succeeded reached a share like an incumbent's, not the one their parents held at
     * home), within the room its fringe leaves and the growth it can manage (MAX_VENTURE_GROWTH); none where it entered
     * at that share already.
     */
    public static function ventureTarget(float $entryShare, float $typicalShare, float $room): float
    {
        $share = min($typicalShare, $room);

        return $entryShare > 0.0 && $share > $entryShare ? min(log($share / $entryShare), self::MAX_VENTURE_GROWTH * self::VENTURE_GROWTH_YEARS) : 0.0;
    }

    /** How far a venture growing toward its target share has gone, in log, after this many years. */
    public static function pathLevel(float $target, float $years): float
    {
        return $target * (1.0 - exp(-max(0.0, $years) / self::pathYears($target)));
    }

    /** How fast it is growing toward its target share then, per year. */
    public static function pathGrowth(float $target, float $years): float
    {
        return $target / self::pathYears($target) * exp(-max(0.0, $years) / self::pathYears($target));
    }

    /**
     * The e-folding time of a venture's path: its growth time, or longer where that would grow it faster than it can,
     * as for a target set before ventureTarget() bounded it.
     */
    private static function pathYears(float $target): float
    {
        return max(self::VENTURE_GROWTH_YEARS, $target / self::MAX_VENTURE_GROWTH);
    }

    /**
     * The market a company enters, drawn in proportion to what each is worth to it, so the most valuable is the
     * likeliest but not a certainty and companies do not all pile into the same one. Null where none is worth anything.
     *
     * @param array<string, float> $values Industry => the venture's value.
     * @param float                $uniform A draw on (0, 1).
     */
    public static function choose(array $values, float $uniform): ?string
    {
        $values = array_filter($values, static fn(float $value): bool => $value > 0.0);
        $total = array_sum($values);
        if ($total <= 0.0) {
            return null;
        }

        $cumulative = 0.0;
        $last = null;
        foreach ($values as $industry => $value) {
            $cumulative += $value / $total;
            $last = (string) $industry;
            if ($uniform < $cumulative) {
                return $last;
            }
        }

        return $last;
    }
}
