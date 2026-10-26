<?php

declare(strict_types=1);

namespace App\Service\Corporate\Segment;

use App\Data\Company\Sectors;
use App\Data\Company\Segments;
use App\Entity\Stock;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Math\FinancialConstants;
use App\Service\Math\TimeSeries;
use App\Service\Model\Strategy\OperatingStrategyInterface;

/**
 * A company in several markets (Segments) keeps its plant by segment. Each segment holds a share of the plant, sells
 * into its own industry's market (its own size, outlook, rivals and price) and builds toward that market; costs, the
 * balance sheet and management stay with the company. The books open at the first report that finds the segments'
 * revenue shares filed, each segment holding that share of the plant and the same share of its own market as the
 * company held of its one.
 */
final class SegmentBooks
{
    // --- Carried State ---
    /** Carried stream-state key prefix: a segment's share of the company's plant; the shares sum to one. */
    public const STATE_PLANT = EarningsEngine::CARRIED_STATE_PREFIX . 'segment_plant:';
    /**
     * Carried stream-state key prefix: a segment's market in units of the company's seeded one, struck when the books
     * open so the segment starts at the company's share of its own market.
     */
    public const STATE_MARKET = EarningsEngine::CARRIED_STATE_PREFIX . 'segment_market:';
    /** Carried stream-state key prefix: the outlook of a segment's industry at the last report, in log. */
    public const STATE_OUTLOOK = EarningsEngine::CARRIED_STATE_PREFIX . 'segment_outlook:';
    /** Carried stream-state key prefix: a segment's share of the quarter's expected revenue, which its lines split. */
    public const STATE_TARGET = EarningsEngine::CARRIED_STATE_PREFIX . 'segment_target:';
    /** Stream-state key prefix of a line's weight in the mix the plan steers to (StreamContext). */
    private const STREAM_WEIGHT_PREFIX = 'weight:';

    // --- Industry Ledger ---
    /** Joins a ticker and a segment into the key of the segment's record in its industry's ledger. */
    public const RECORD_KEY_SEPARATOR = '/';

    /**
     * Whether the company keeps plant by segment: it sells into several markets and its model does not plan the mix.
     */
    public static function keepsBooks(Stock $stock): bool
    {
        return Segments::forTicker($stock->getTicker()) !== null
            && !Sectors::strategyFor($stock->getIndustry())->plansOwnSegmentMix();
    }

    /**
     * Each segment's industry, the main segment's first.
     *
     * @return array<string, string>
     */
    public static function industries(Stock $stock): array
    {
        $industries = [Segments::MAIN => (string) $stock->getIndustry()];
        foreach (Segments::forTicker($stock->getTicker())['segments'] ?? [] as $key => $segment) {
            $industries[$key] = $segment['industry'];
        }

        return $industries;
    }

    /** The key of a segment's record in its industry's ledger: the ticker itself for the main segment. */
    public static function recordKey(string $ticker, string $segment): string
    {
        return $segment === Segments::MAIN ? $ticker : $ticker . self::RECORD_KEY_SEPARATOR . $segment;
    }

    /** The company a ledger record belongs to. */
    public static function ownerOf(string $recordKey): string
    {
        return explode(self::RECORD_KEY_SEPARATOR, $recordKey, 2)[0];
    }

    /**
     * Each segment's share of the plant, or null before the books open.
     *
     * @param array<string, mixed> $momentum
     * @return array<string, float>|null
     */
    public static function plant(array $momentum): ?array
    {
        $plant = self::filed($momentum, self::STATE_PLANT);

        return $plant === [] ? null : $plant;
    }

    /**
     * The state that opens the books: each segment holds its share of the plant and a market sized so that it holds
     * its share of that market now (the company's share where its industry's market is not known), given the
     * company's market growth and each segment's since trading opened.
     *
     * @param array<string, float> $plantShares    Segment => its share of the plant.
     * @param array<string, float> $segmentGrowths Segment => its market's growth since trading opened.
     * @param array<string, float> $marketShares   Segment => its share of its industry's market, where known.
     * @return array<string, float> Carried state.
     */
    public static function open(array $plantShares, float $firmMarketGrowth, array $segmentGrowths, float $firmMarketShare = 0.0, array $marketShares = []): array
    {
        $state = [];
        foreach ($plantShares as $segment => $share) {
            $known = $marketShares[$segment] ?? 0.0;
            $state[self::STATE_PLANT . $segment] = $share;
            $state[self::STATE_MARKET . $segment] = $share * $firmMarketGrowth / max(1e-9, $segmentGrowths[$segment] ?? 1.0)
                * ($firmMarketShare > 0.0 && $known > 0.0 ? $firmMarketShare / $known : 1.0);
        }

        return $state;
    }

    /**
     * A segment's share of its market when it enters its industry's ledger: its sales over the larger of two markets,
     * the one its industry's listed firms sell into and the one its company's reach implies for those sales, so a small
     * arm of a large company is small in a large industry, and no segment is a larger share of its market than its
     * company is of its own (a segment that outsells its industry's listed firms sells into more than their market).
     */
    public static function entryMarketShare(float $segmentRevenue, float $industryMarket, float $companyShare): float
    {
        $market = max($industryMarket, $companyShare > 0.0 ? $segmentRevenue / $companyShare : 0.0);

        return $market > 0.0 ? min(1.0, $segmentRevenue / $market) : $companyShare;
    }

    /**
     * Each segment's share of the company's plant when its books open: the lines' mix the plan was steering to, summed
     * by segment, or where the plan keeps no mix, the last report's revenue.
     *
     * @param array<string, mixed> $momentum
     * @return array<string, float>
     */
    public static function openingPlantShares(string $ticker, array $momentum): array
    {
        $weights = [];
        foreach ($momentum as $key => $value) {
            if (str_starts_with((string) $key, self::STREAM_WEIGHT_PREFIX)) {
                $segment = Segments::segmentOf($ticker, substr((string) $key, strlen(self::STREAM_WEIGHT_PREFIX)));
                $weights[$segment] = ($weights[$segment] ?? 0.0) + max(0.0, (float) $value);
            }
        }
        $total = array_sum($weights);
        if ($total <= 0.0) {
            return SegmentOutlook::filedShares($momentum);
        }

        return array_map(static fn(float $weight): float => $weight / $total, $weights);
    }

    /**
     * The segments whose lines are all by-products of the main segment's plant (an airline's belly cargo flies on its
     * passenger aircraft): they sell into their own industry at its price, but their plant is built and worn with the
     * main segment's.
     *
     * @return list<string>
     */
    public static function jointSegments(Stock $stock): array
    {
        $joint = Sectors::strategyFor($stock->getIndustry())->jointProductLines();
        $segments = [];
        foreach (Segments::forTicker($stock->getTicker())['segments'] ?? [] as $key => $segment) {
            if ($joint !== [] && array_diff($segment['streams'], $joint) === []) {
                $segments[] = $key;
            }
        }

        return $segments;
    }

    /**
     * How far each segment's market has grown past the economy since trading opened: its industry's faded secular
     * excess and the outlook its last report filed, as the industry ledger grows the market.
     *
     * @return array<string, float>
     */
    public static function marketGrowths(Stock $stock, float $totalTime): array
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $growths = [];
        foreach (self::industries($stock) as $segment => $industry) {
            $excess = IndustryShareLedger::secularExcessGrowth(Sectors::strategyFor($industry), $stock);
            $growths[$segment] = exp(
                TimeSeries::fadedExcessIntegral($excess, 0.0, $totalTime, FinancialConstants::SECULAR_EXCESS_HALF_LIFE_YEARS)
                + (float) ($momentum[self::STATE_OUTLOOK . $segment] ?? 0.0),
            );
        }

        return $growths;
    }

    /**
     * The company's market against its seeded one: the sum of its segments' markets. Null before the books open.
     */
    public static function firmMarketGrowth(Stock $stock, float $totalTime): ?float
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        if (self::plant($momentum) === null) {
            return null;
        }

        $markets = self::filed($momentum, self::STATE_MARKET);
        $total = 0.0;
        foreach (self::marketGrowths($stock, $totalTime) as $segment => $growth) {
            $total += ($markets[$segment] ?? 0.0) * $growth;
        }

        return max(1e-9, $total);
    }

    /**
     * The company's secular growth now: its model's, or for a company that keeps plant by segment each segment's
     * industry's on its plant.
     */
    public static function fadedSecularGrowth(OperatingStrategyInterface $strategy, Stock $stock, float $totalTime): float
    {
        $plant = self::plant($stock->getEarningsMomentumZ() ?? []);
        if ($plant === null) {
            return $strategy->getFadedSecularGrowthRate($stock, $totalTime);
        }

        $industries = self::industries($stock);
        $growth = 0.0;
        foreach ($plant as $segment => $share) {
            $segmentStrategy = $segment === Segments::MAIN ? $strategy : Sectors::strategyFor($industries[$segment] ?? '');
            $growth += $share * $segmentStrategy->getFadedSecularGrowthRate($stock, $totalTime);
        }

        return $growth;
    }

    /**
     * Each segment's share of its own market: its plant share of the company's capital over its market, given the
     * company's capital against its seeded market. Empty before the books open.
     *
     * @param float $seededScale The company's capital over its seeded market, unscaled by growth.
     * @return array<string, float>
     */
    public static function addressableShares(Stock $stock, float $seededScale, float $totalTime): array
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $plant = self::plant($momentum) ?? [];
        $markets = self::filed($momentum, self::STATE_MARKET);
        $growths = self::marketGrowths($stock, $totalTime);

        $shares = [];
        foreach ($plant as $segment => $plantShare) {
            $market = ($markets[$segment] ?? 0.0) * ($growths[$segment] ?? 1.0);
            $shares[$segment] = $market > 0.0 ? $seededScale * $plantShare / $market : 0.0;
        }

        return $shares;
    }

    /**
     * The company's quarter from its segments': the share of its plant that runs, the price its output fetches, and
     * each segment's share of the revenue, after what its own industry's rivals took from it.
     *
     * @param array<string, float> $plant   Segment => plant share.
     * @param array<string, float> $prices  Segment => its industry's price level for it.
     * @param array<string, float> $loads   Segment => the share of its plant whose output sells.
     * @param array<string, float> $drains  Segment => the demand its industry's rivals took from it (negative) or
     *                                      ceded.
     * @param array<string, float> $running Segment => the share of its plant that runs, where not the share that sells
     *                                      (a by-product flies whether or not its space is sold).
     * @return array{load: float, price: float, targets: array<string, float>}
     */
    public static function aggregate(array $plant, array $prices, array $loads, array $drains = [], array $running = []): array
    {
        $load = 0.0;
        $sales = [];
        $demand = [];
        foreach ($plant as $segment => $share) {
            $load += $share * ($running[$segment] ?? $loads[$segment] ?? 1.0);
            $sales[$segment] = $share * ($loads[$segment] ?? 1.0) * ($prices[$segment] ?? 1.0);
            $demand[$segment] = $sales[$segment] * max(0.0, 1.0 + ($drains[$segment] ?? 0.0));
        }
        $revenue = array_sum($sales);
        $sold = array_sum($demand);

        return [
            'load' => $load,
            'price' => $load > 0.0 ? $revenue / $load : 1.0,
            'targets' => $sold > 0.0 ? array_map(static fn(float $amount): float => $amount / $sold, $demand) : $plant,
        ];
    }

    /**
     * The plant shares after the quarter's build: each segment adds the growth capex it was allotted and loses the wear
     * on its idle plant, which is not replaced; the wear on plant that runs is.
     *
     * @param array<string, float> $plant       Segment => plant share.
     * @param array<string, float> $growthCapex Segment => growth capex allotted.
     * @param array<string, float> $loads       Segment => the share of its plant that runs.
     * @param float                $wear        The quarter's wear on the whole plant; 0 where all of it is replaced.
     * @return array<string, float>
     */
    public static function rollPlant(array $plant, float $plantBase, array $growthCapex, array $loads, float $wear): array
    {
        if ($plantBase <= 0.0) {
            return $plant;
        }

        $amounts = [];
        foreach ($plant as $segment => $share) {
            $amounts[$segment] = max(0.0, ($share * $plantBase) + ($growthCapex[$segment] ?? 0.0) - ($wear * $share * (1.0 - ($loads[$segment] ?? 1.0))));
        }
        $total = array_sum($amounts);

        return $total > 0.0 ? array_map(static fn(float $amount): float => $amount / $total, $amounts) : $plant;
    }

    /**
     * The plant shares after a deal that buys plant into the main segment's industry (a takeover folds the target into
     * the buyer's industry): the main segment's plant grows by what was bought, the others' shares shrink with it.
     *
     * @param array<string, float> $plant Segment => plant share.
     * @return array<string, float>
     */
    public static function acquireIntoMain(array $plant, float $capacity, float $acquiredCapacity): array
    {
        $total = $capacity + max(0.0, $acquiredCapacity);
        if ($total <= 0.0) {
            return $plant;
        }

        $shares = [];
        foreach ($plant as $segment => $share) {
            $shares[$segment] = (($share * $capacity) + ($segment === Segments::MAIN ? max(0.0, $acquiredCapacity) : 0.0)) / $total;
        }
        if (!isset($shares[Segments::MAIN])) {
            $shares[Segments::MAIN] = max(0.0, $acquiredCapacity) / $total;
        }

        return $shares;
    }

    /**
     * A model's line weights rescaled so each segment's lines take the segment's share of the quarter's revenue, split
     * among them as the model splits them. Null for a company whose books are not open.
     *
     * @param array<string, mixed> $momentum
     * @param array<string, float> $weights Line => weight.
     * @return array<string, float>|null
     */
    public static function splitToTargets(?string $ticker, array $momentum, array $weights): ?array
    {
        $targets = self::filed($momentum, self::STATE_TARGET);
        if ($ticker === null || $targets === []) {
            return null;
        }

        $segmentWeights = [];
        foreach ($weights as $line => $weight) {
            $segment = Segments::segmentOf($ticker, (string) $line);
            $segmentWeights[$segment] = ($segmentWeights[$segment] ?? 0.0) + max(0.0, $weight);
        }

        $split = [];
        foreach ($weights as $line => $weight) {
            $segment = Segments::segmentOf($ticker, (string) $line);
            $split[$line] = $segmentWeights[$segment] > 0.0 ? max(0.0, $weight) / $segmentWeights[$segment] * ($targets[$segment] ?? 0.0) : 0.0;
        }
        $total = array_sum($split);

        // A segment the model sells nothing in leaves its share to the others.
        return $total > 0.0 ? array_map(static fn(float $weight): float => $weight / $total, $split) : null;
    }

    /**
     * @param array<string, mixed> $momentum
     * @return array<string, float>
     */
    private static function filed(array $momentum, string $prefix): array
    {
        $values = [];
        foreach ($momentum as $key => $value) {
            if (str_starts_with((string) $key, $prefix)) {
                $values[substr((string) $key, strlen($prefix))] = (float) $value;
            }
        }

        return $values;
    }
}
