<?php

declare(strict_types=1);

namespace App\Service\Corporate\Industry;

use App\Data\Company\IndustryOutlooks;
use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\Earnings\CapitalBudgetEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Math\Distributions;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use App\Service\Math\Valuation;

/**
 * Where each industry's long-run demand is heading and what the public believes about it.
 *
 * The true level x walks at random around the path the industry's model expects, compensated so its level is a
 * martingale. Every quarter the public reads it through noisy industry data (orders, surveys) and learns it by a
 * local-level filter; its belief is a log mean m and variance v, and what it expects the level to be is e^{m + v/2}.
 * The unlisted rest of the industry builds toward that belief as listed firms do, at the flexible accelerator's speed
 * and never by selling plant back. Today's market is known at the open (x = 0, belief centred on it), so only the
 * future is uncertain. Spending moves between industries and not into being: after each step the true levels are
 * rescaled so the industries' opening shares of spending, at their new levels, still sum to one.
 *
 * Stepped once per quarter for every industry, before any report reads it. Draws are hashed from the run's salt, so
 * they take nothing from any random stream. An industry with no uncertainty is not tracked, financial ones included,
 * so spending moves only among the industries that drift.
 *
 * @phpstan-import-type OutlookState from IndustryShareStoreInterface
 * @phpstan-import-type OutlookRecord from IndustryShareStoreInterface
 */
final class IndustryOutlook
{
    // --- Filed Outlook ---
    /**
     * Carried stream-state key: the level the public expected the firm's industry demand to reach at its last report,
     * in log against the expected path (0 for an untracked industry), which its market size reads.
     */
    public const STATE_BELIEF = EarningsEngine::CARRIED_STATE_PREFIX . 'industry_outlook';

    public function __construct(
        private readonly IndustryShareStoreInterface $store,
        private readonly CorporateMetrics $corporateMetrics,
    ) {}

    /**
     * Steps every industry to the quarter this tick falls in, opening the outlooks on the first call.
     *
     * @param iterable<Stock> $stocks The board, for the industries it holds and their opening sizes.
     * @return array<string, array{level: float, revision: float}> Each industry the quarter's turn revised: the level
     *                                                              the public now expects and how far it moved, as a
     *                                                              fraction. Empty on any other tick.
     */
    public function advance(iterable $stocks, MacroStateDTO $macroState, int $tick, int $ticksPerYear): array
    {
        $quarter = intdiv($tick, max(1, intdiv($ticksPerYear, (int) FinancialConstants::QUARTERS_PER_YEAR)));
        $state = $this->store->readOutlooks();
        // Opened once: on the first call, or where a harness has written only the salt to draw from.
        if ($state === null || $state['quarter'] < 0) {
            $this->store->writeOutlooks(['salt' => $state['salt'] ?? random_int(1, PHP_INT_MAX >> 1), 'quarter' => $quarter, 'industries' => $this->open($stocks, $macroState)]);

            return [];
        }
        if ($quarter <= $state['quarter']) {
            return [];
        }

        for ($next = $state['quarter'] + 1; $next <= $quarter; ++$next) {
            $state = self::step($state, $next, $macroState, $tick);
        }
        $this->store->writeOutlooks($state);

        $revisions = [];
        foreach ($state['industries'] as $industry => $record) {
            if ($record['revised_tick'] === $tick) {
                $revisions[$industry] = ['level' => exp(self::belief($record)), 'revision' => self::revision($record)];
            }
        }

        return $revisions;
    }

    /**
     * Every non-financial industry on the board at its known level, with the settled belief centred on it and its share
     * of spending: the market its listed firms sell into, read from their revenue over their addressable share.
     *
     * @param iterable<Stock> $stocks
     * @return array<string, OutlookRecord>
     */
    private function open(iterable $stocks, MacroStateDTO $macroState): array
    {
        $markets = [];
        foreach ($stocks as $stock) {
            $industry = (string) $stock->getIndustry();
            $strategy = Sectors::strategyFor($industry);
            if ($industry === '' || $stock->isBankrupt() || $strategy->isFinancial() || IndustryOutlooks::driftVol($industry) <= 0.0) {
                continue;
            }
            $capital = $strategy->getEvaluationCapital((float) $stock->getTotalEquity(), $stock->getInvestedCapital());
            $share = $this->corporateMetrics->calculateAddressableShare($stock, $capital, $macroState);
            $markets[$industry] = max($markets[$industry] ?? 0.0, $share > 0.0 ? (float) $stock->getTotalRevenue() / $share : 0.0);
        }

        $records = [];
        foreach ($markets as $industry => $market) {
            $variance = self::steadyVariance(IndustryOutlooks::driftVol($industry), IndustryOutlooks::learningHalfLife($industry));
            $records[$industry] = ['x' => 0.0, 'm' => -$variance / 2.0, 'v' => $variance, 'f' => 0.0, 'w' => $market, 'prior_belief' => 0.0, 'revised_tick' => -1];
        }

        return $records;
    }

    /**
     * One quarter for every industry: the truth walks, the fringe builds toward the belief it held over the quarter,
     * the public takes in the quarter's reading, and the truth is rescaled to the fixed total of spending. The
     * rescaling is common knowledge (total spending is measured), so beliefs take it too.
     *
     * @param OutlookState                                   $state
     * @param (\Closure(string): array{float, float})|null $parameters Industry => its drift volatility and learning
     *                                                                  half-life; IndustryOutlooks when null.
     * @return OutlookState
     */
    public static function step(array $state, int $quarter, MacroStateDTO $macroState, int $tick, ?\Closure $parameters = null): array
    {
        $parameters ??= static fn(string $industry): array => [IndustryOutlooks::driftVol($industry), IndustryOutlooks::learningHalfLife($industry)];
        $dt = FinancialConstants::QUARTER_YEARS;
        $trendGrowth = MacroConstants::TREND_REAL_GROWTH + max(0.0, $macroState->inflationEma);
        $industries = $state['industries'];

        foreach ($industries as $industry => $record) {
            [$driftVol, $halfLife] = $parameters($industry);
            $stepVariance = ($driftVol ** 2) * $dt;
            if ($stepVariance <= 0.0) {
                continue;
            }
            $belief = self::belief($record);
            $truth = $record['x'] - ($stepVariance / 2.0) + (sqrt($stepVariance) * self::draw($state['salt'], $industry, $quarter, 'truth'));
            $fringeGrowth = Valuation::flexibleAcceleratorGrowth($trendGrowth, $belief - $record['f'], CapitalBudgetEngine::CAPITAL_ERROR_CORRECTION_SPEED);

            $readingVariance = TimeSeries::localLevelReadingVariance($stepVariance, self::steadyGain($halfLife));
            $reading = $truth + (sqrt($readingVariance) * self::draw($state['salt'], $industry, $quarter, 'reading'));
            [$mean, $variance] = TimeSeries::localLevelStep($record['m'] - ($stepVariance / 2.0), $record['v'], $reading, $stepVariance, $readingVariance);

            $industries[$industry] = [
                'x' => $truth,
                'm' => $mean,
                'v' => $variance,
                'f' => $record['f'] + (($fringeGrowth - $trendGrowth) * $dt),
                'w' => $record['w'],
                'prior_belief' => $belief,
                'revised_tick' => $tick,
            ];
        }

        $weights = array_sum(array_column($industries, 'w'));
        $spending = 0.0;
        foreach ($industries as $record) {
            $spending += $record['w'] * exp($record['x']);
        }
        $shift = $weights > 0.0 && $spending > 0.0 ? log($spending / $weights) : 0.0;
        foreach ($industries as $industry => $record) {
            $industries[$industry]['x'] = $record['x'] - $shift;
            $industries[$industry]['m'] = $record['m'] - $shift;
        }

        return ['salt' => $state['salt'], 'quarter' => $quarter, 'industries' => $industries];
    }

    /**
     * The level the public expects an industry's long-run demand to be at against its expected path, in log: m + v/2.
     *
     * @param OutlookRecord $record
     */
    public static function belief(array $record): float
    {
        return $record['m'] + ($record['v'] / 2.0);
    }

    /**
     * How far the public's expected level moved at the last quarter's turn, as a fraction: e^{belief - prior} - 1. Zero
     * before the first revision.
     *
     * @param OutlookRecord $record
     */
    public static function revision(array $record): float
    {
        return $record['revised_tick'] < 0 ? 0.0 : exp(self::belief($record) - $record['prior_belief']) - 1.0;
    }

    /** Share of a reading's surprise the settled belief takes in each quarter, from its learning half-life in years. */
    public static function steadyGain(float $halfLife): float
    {
        return 1.0 - (0.5 ** (FinancialConstants::QUARTER_YEARS / max(FinancialConstants::QUARTER_YEARS, $halfLife)));
    }

    /** The settled belief's variance, where a new outlook starts. */
    public static function steadyVariance(float $driftVol, float $halfLife): float
    {
        return TimeSeries::localLevelSteadyVariance(($driftVol ** 2) * FinancialConstants::QUARTER_YEARS, self::steadyGain($halfLife));
    }

    /** A standard normal hashed from the run's salt and its key: the same key always draws the same. */
    private static function draw(int $salt, string $industry, int $quarter, string $kind): float
    {
        return Distributions::standardNormalQuantile(MathUtility::hashedUniform($salt, "outlook:{$industry}:{$quarter}:{$kind}"));
    }
}
