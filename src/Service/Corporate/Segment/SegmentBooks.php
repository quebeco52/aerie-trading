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
    /** Carried stream-state key prefix: a segment's operating margin at the balanced price. */
    public const STATE_MARGIN = EarningsEngine::CARRIED_STATE_PREFIX . 'segment_margin:';
    /** Carried stream-state key prefix: a segment's capital turnover, its annual revenue per unit of its plant. */
    public const STATE_TURNOVER = EarningsEngine::CARRIED_STATE_PREFIX . 'segment_turnover:';
    /** Stream-state key prefix of a line's weight in the mix the plan steers to (StreamContext). */
    private const STREAM_WEIGHT_PREFIX = 'weight:';

    // --- Industry Ledger ---
    /** Joins a ticker and a segment into the key of the segment's record in its industry's ledger. */
    public const RECORD_KEY_SEPARATOR = '/';

    /** Whether the company keeps plant by segment: it sells into several markets. */
    public static function keepsBooks(Stock $stock): bool
    {
        return Segments::forTicker($stock->getTicker()) !== null;
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
     * The state that gives each segment its own economics (Segments::economicsOf()), struck so the company's margin and
     * turnover stay what they are and every segment keeps its share of the revenue: a segment that turns its capital
     * slower holds more of the plant than its sales, and its market, measured in plant, grows with it.
     *
     * @param array<string, mixed> $momentum
     * @return array<string, float> Carried state.
     */
    public static function openEconomics(string $ticker, array $momentum, float $companyTurnover, float $companyMargin): array
    {
        $plant = self::plant($momentum) ?? [];
        $markets = self::filed($momentum, self::STATE_MARKET);
        $economics = Segments::economicsOf($ticker);

        // The plant was split at the company's turnover, so its shares are the segments' shares of the revenue.
        $plantPerSale = 0.0;
        $marginPerSale = 0.0;
        foreach ($plant as $segment => $share) {
            $plantPerSale += $share / ($economics[$segment]['turnover'] ?? 1.0);
            $marginPerSale += $share * ($economics[$segment]['margin'] ?? 1.0);
        }
        if ($plantPerSale <= 0.0 || $marginPerSale <= 0.0) {
            return [];
        }

        $state = [];
        foreach ($plant as $segment => $share) {
            $turnover = $economics[$segment]['turnover'] ?? 1.0;
            $resplit = ($share / $turnover) / $plantPerSale;
            $state[self::STATE_PLANT . $segment] = $resplit;
            $state[self::STATE_TURNOVER . $segment] = $companyTurnover * $turnover * $plantPerSale;
            $state[self::STATE_MARGIN . $segment] = $companyMargin * ($economics[$segment]['margin'] ?? 1.0) / $marginPerSale;
            if ($share > 0.0 && isset($markets[$segment])) {
                $state[self::STATE_MARKET . $segment] = $markets[$segment] * $resplit / $share;
            }
        }

        return $state;
    }

    /**
     * The company's economics from its segments': its turnover, the revenue its plant earns at its segments' own, each
     * segment's share of that revenue, and its margin, its segments' margins over that revenue. Null before the
     * segments have economics of their own.
     *
     * @param array<string, mixed> $momentum
     * @return array{turnover: float, margin: float, sales: array<string, float>, margins: array<string, float>, turnovers: array<string, float>}|null
     */
    public static function companyEconomics(array $momentum): ?array
    {
        $plant = self::plant($momentum) ?? [];
        $margins = self::filed($momentum, self::STATE_MARGIN);
        $turnovers = self::filed($momentum, self::STATE_TURNOVER);
        if ($plant === [] || $turnovers === []) {
            return null;
        }

        $turnover = 0.0;
        foreach ($plant as $segment => $share) {
            $turnover += $share * ($turnovers[$segment] ?? 0.0);
        }
        if ($turnover <= 0.0) {
            return null;
        }

        $sales = [];
        $margin = 0.0;
        foreach ($plant as $segment => $share) {
            $sales[$segment] = $share * ($turnovers[$segment] ?? 0.0) / $turnover;
            $margin += $sales[$segment] * ($margins[$segment] ?? 0.0);
        }

        return ['turnover' => $turnover, 'margin' => $margin, 'sales' => $sales, 'margins' => $margins, 'turnovers' => $turnovers];
    }

    /**
     * The state that opens the books: each segment holds its share of the plant and a market sized so that it holds
     * its share of that market now (the company's share where its industry's market is not known), given the
     * company's market growth and each segment's since trading opened. A segment that sells nothing yet is left out
     * until it does (enter()); one sold later keeps its place at none.
     *
     * @param array<string, float> $plantShares    Segment => its share of the plant.
     * @param array<string, float> $segmentGrowths Segment => its market's growth since trading opened.
     * @param array<string, float> $marketShares   Segment => its share of its industry's market, where known.
     * @return array<string, float> Carried state.
     */
    public static function open(array $plantShares, float $firmMarketGrowth, array $segmentGrowths, float $firmMarketShare = 0.0, array $marketShares = []): array
    {
        $state = [];
        foreach (array_filter($plantShares, static fn(float $share): bool => $share > 0.0) as $segment => $share) {
            $known = $marketShares[$segment] ?? 0.0;
            $state[self::STATE_PLANT . $segment] = $share;
            $state[self::STATE_MARKET . $segment] = $share * $firmMarketGrowth / max(1e-9, $segmentGrowths[$segment] ?? 1.0)
                * ($firmMarketShare > 0.0 && $known > 0.0 ? $firmMarketShare / $known : 1.0);
        }

        return $state;
    }

    /**
     * Plant shares with one segment set to a share and the others scaled to the rest in the proportions they held.
     *
     * @param array<string, float> $plant Segment => plant share.
     * @return array<string, float>
     */
    public static function reshare(array $plant, string $segment, float $share): array
    {
        $share = max(0.0, min(1.0, $share));
        $others = array_sum($plant) - ($plant[$segment] ?? 0.0);
        $shares = [];
        foreach ($plant as $key => $held) {
            $shares[$key] = $others > 0.0 ? $held * (1.0 - $share) / $others : 0.0;
        }
        $shares[$segment] = $share;

        return $shares;
    }

    /**
     * Whether a segment's plant still sells into its industry: the main segment always, another while it holds plant
     * (not one sold, whose record is the buyer's plant now, nor one not yet entered).
     */
    public static function holdsPlant(Stock $stock, string $segment): bool
    {
        return $segment === Segments::MAIN || ((self::plant($stock->getEarningsMomentumZ() ?? []) ?? [])[$segment] ?? 0.0) > 0.0;
    }

    /**
     * The state that brings a segment in once the books are open: the plant its share of the sales calls for at its own
     * turnover, taken from the main segment; a market in which it holds its share of its industry's (open()); and the
     * main segment's margin and turnover scaled as its lore sets them (Segments::economicsOf()). Nothing while that
     * share is none.
     *
     * @param array<string, mixed> $momentum
     * @param float $salesShare  The segment's expected share of the company's sales.
     * @param float $marketShare Its share of its industry's market, where known.
     * @return array<string, float> Carried state.
     */
    public static function enter(string $ticker, array $momentum, string $segment, float $salesShare, float $firmMarketGrowth, float $segmentGrowth, float $firmShare, float $marketShare): array
    {
        $mainPlant = (self::plant($momentum) ?? [])[Segments::MAIN] ?? 0.0;
        $relative = Segments::economicsOf($ticker)[$segment] ?? ['margin' => 1.0, 'turnover' => 1.0];
        $margins = self::filed($momentum, self::STATE_MARGIN);
        $turnovers = self::filed($momentum, self::STATE_TURNOVER);
        $company = self::companyEconomics($momentum);
        $turnover = ($turnovers[Segments::MAIN] ?? 0.0) * $relative['turnover'];
        $share = min($mainPlant, max(0.0, $company !== null && $turnover > 0.0 ? $salesShare * $company['turnover'] / $turnover : $salesShare));
        if ($share <= 0.0) {
            return [];
        }

        $state = self::open([$segment => $share], $firmMarketGrowth, [$segment => $segmentGrowth], $firmShare, $marketShare > 0.0 ? [$segment => $marketShare] : []);
        $state[self::STATE_PLANT . Segments::MAIN] = $mainPlant - $share;
        if (isset($margins[Segments::MAIN], $turnovers[Segments::MAIN])) {
            $state[self::STATE_MARGIN . $segment] = $margins[Segments::MAIN] * $relative['margin'];
            $state[self::STATE_TURNOVER . $segment] = $turnover;
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

        // A segment sold no longer sells into its market.
        $plant = self::plant($momentum) ?? [];
        $markets = self::filed($momentum, self::STATE_MARKET);
        $total = 0.0;
        foreach (self::marketGrowths($stock, $totalTime) as $segment => $growth) {
            $total += ($plant[$segment] ?? 0.0) > 0.0 ? ($markets[$segment] ?? 0.0) * $growth : 0.0;
        }

        return max(1e-9, $total);
    }

    /**
     * The company's secular growth now: its model's, or for a company that keeps plant by segment each segment's on its
     * plant.
     */
    public static function fadedSecularGrowth(OperatingStrategyInterface $strategy, Stock $stock, float $totalTime): float
    {
        $plant = self::plant($stock->getEarningsMomentumZ() ?? []);
        if ($plant === null) {
            return $strategy->getFadedSecularGrowthRate($stock, $totalTime);
        }

        $growth = 0.0;
        foreach ($plant as $segment => $share) {
            $growth += $share * self::segmentGrowth($strategy, $stock, $segment, $totalTime);
        }

        return $growth;
    }

    /**
     * A segment's secular growth now: its industry's, the main segment's its company model's, and along the path the
     * model plans where it plans one (OperatingStrategyInterface::resolveSegmentPath()).
     *
     * @param OperatingStrategyInterface $strategy The company's model.
     */
    public static function segmentGrowth(OperatingStrategyInterface $strategy, Stock $stock, string $segment, float $totalTime): float
    {
        $segmentStrategy = $segment === Segments::MAIN ? $strategy : Sectors::strategyFor(self::industries($stock)[$segment] ?? '');

        return $segmentStrategy->getFadedSecularGrowthRate($stock, $totalTime) + ($strategy->resolveSegmentPath($stock, $segment)['growth'] ?? 0.0);
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
     * The company's quarter from its segments': the share of its plant that runs, the price its output fetches (so that
     * price times load is the revenue its plant earns against the balanced price), and each segment's share of the
     * revenue, after what its own industry's rivals took from it.
     *
     * @param array<string, float> $plant   Segment => plant share.
     * @param array<string, float> $prices  Segment => its industry's price level for it.
     * @param array<string, float> $loads   Segment => the share of its plant whose output sells.
     * @param array<string, float> $drains  Segment => the demand its industry's rivals took from it (negative) or
     *                                      ceded.
     * @param array<string, float> $running Segment => the share of its plant that runs, where not the share that sells
     *                                      (a by-product flies whether or not its space is sold).
     * @param array<string, float> $revenue Segment => its share of the revenue the plant earns at the balanced price;
     *                                      its plant share where the segments turn their plant alike.
     * @return array{load: float, price: float, targets: array<string, float>}
     */
    public static function aggregate(array $plant, array $prices, array $loads, array $drains = [], array $running = [], array $revenue = []): array
    {
        $load = 0.0;
        $sales = [];
        $demand = [];
        foreach ($plant as $segment => $share) {
            $load += $share * ($running[$segment] ?? $loads[$segment] ?? 1.0);
            $sales[$segment] = ($revenue[$segment] ?? $share) * ($loads[$segment] ?? 1.0) * ($prices[$segment] ?? 1.0);
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
     * The plant shares after a deal that buys plant into one segment's industry: that segment's plant grows by what was
     * bought, the others' shares shrink with it. What is bought into the main business brings its by-products with it
     * (an airline bought comes with its holds), in the proportion the company runs them.
     *
     * @param array<string, float> $plant Segment => plant share.
     * @param list<string>         $joint Segments whose plant rides the main segment's.
     * @return array<string, float>
     */
    public static function acquireInto(array $plant, string $segment, float $capacity, float $acquiredCapacity, array $joint = []): array
    {
        $total = $capacity + max(0.0, $acquiredCapacity);
        if ($total <= 0.0) {
            return $plant;
        }

        $slices = self::boughtSlices($plant, $segment, $joint);
        $shares = [];
        foreach ($plant + [$segment => 0.0] as $key => $share) {
            $shares[$key] = (($share * $capacity) + (max(0.0, $acquiredCapacity) * ($slices[$key] ?? 0.0))) / $total;
        }

        return $shares;
    }

    /**
     * The books after a takeover into one segment, as acquireInto() moves the plant at the capital bought: the segment
     * bought into (with the by-products that ride the main plant, each its share) takes the target's revenue and
     * operating income into its own turnover and margin, the others keep theirs. Empty before the books open.
     *
     * @param array<string, mixed> $momentum
     * @param list<string>         $joint
     * @return array<string, float> Carried state.
     */
    public static function acquireIntoBooks(array $momentum, string $segment, float $capital, float $acquiredCapital, float $acquiredRevenue, float $acquiredIncome, array $joint = []): array
    {
        $plant = self::plant($momentum);
        if ($plant === null) {
            return [];
        }

        $state = [];
        foreach (self::acquireInto($plant, $segment, $capital, $acquiredCapital, $joint) as $key => $share) {
            $state[self::STATE_PLANT . $key] = $share;
        }

        $economics = self::companyEconomics($momentum);
        if ($economics === null) {
            return $state;
        }
        foreach (self::boughtSlices($plant, $segment, $joint) as $key => $slice) {
            $share = $plant[$key] ?? 0.0;
            $ownCapital = $share * $capital;
            $turnover = $economics['turnovers'][$key] ?? $economics['turnover'];
            $ownRevenue = $ownCapital * $turnover;
            $revenue = $ownRevenue + ($slice * max(0.0, $acquiredRevenue));
            $state[self::STATE_TURNOVER . $key] = ($ownCapital + ($slice * max(0.0, $acquiredCapital))) > 0.0
                ? $revenue / ($ownCapital + ($slice * max(0.0, $acquiredCapital)))
                : $turnover;
            $state[self::STATE_MARGIN . $key] = $revenue > 0.0
                ? (($ownRevenue * ($economics['margins'][$key] ?? $economics['margin'])) + ($slice * $acquiredIncome)) / $revenue
                : ($economics['margins'][$key] ?? $economics['margin']);
        }

        return $state;
    }

    /**
     * How what a deal buys into a segment splits across the books: all of it to that segment, or bought into the main
     * business, between it and the by-products that ride its plant in the proportion it runs them.
     *
     * @param array<string, float> $plant Segment => plant share.
     * @param list<string>         $joint
     * @return array<string, float> Segment => its slice of the deal.
     */
    public static function boughtSlices(array $plant, string $segment, array $joint = []): array
    {
        if ($segment !== Segments::MAIN) {
            return [$segment => 1.0];
        }

        $group = array_filter(array_intersect_key($plant, array_flip([Segments::MAIN, ...$joint])), static fn(float $share): bool => $share > 0.0);
        $total = array_sum($group);

        return $total > 0.0 ? array_map(static fn(float $share): float => $share / $total, $group) : [Segments::MAIN => 1.0];
    }

    /**
     * The plant shares after a segment is sold whole: it holds none, and the rest of the plant is the others' in the
     * proportions they held it.
     *
     * @param array<string, float> $plant Segment => plant share.
     * @return array<string, float>
     */
    public static function sellSegment(array $plant, string $segment): array
    {
        $kept = 1.0 - ($plant[$segment] ?? 0.0);
        if ($kept <= 0.0) {
            return $plant;
        }

        $shares = [];
        foreach ($plant as $key => $share) {
            $shares[$key] = $key === $segment ? 0.0 : $share / $kept;
        }

        return $shares;
    }

    /**
     * What idling a unit of a segment's plant saves, over its revenue: the company's avoidable cost, or nothing for a
     * by-product, whose plant runs whatever its own industry buys.
     */
    public static function avoidableCostRatio(Stock $stock, string $segment, float $companyRatio): float
    {
        return in_array($segment, self::jointSegments($stock), true) ? 0.0 : $companyRatio;
    }

    /**
     * A segment's shares of what its company sells and earns: its share of the quarter's revenue, and of the operating
     * income that revenue makes at each segment's margin. What a sale of it gives up, as its share of the plant is the
     * share of the assets that leave.
     *
     * @param array<string, mixed> $momentum
     * @return array{revenue: float, income: float}
     */
    public static function earningShares(array $momentum, string $segment): array
    {
        $targets = self::filed($momentum, self::STATE_TARGET);
        $margins = self::filed($momentum, self::STATE_MARGIN);
        $income = 0.0;
        foreach ($targets as $key => $target) {
            $income += $target * ($margins[$key] ?? 1.0);
        }

        return [
            'revenue' => $targets[$segment] ?? 0.0,
            'income' => $income > 0.0 ? ($targets[$segment] ?? 0.0) * ($margins[$segment] ?? 1.0) / $income : ($targets[$segment] ?? 0.0),
        ];
    }

    /**
     * The segment a company that keeps plant by segment would sell whole: of those it can sell apart from its main
     * business (not one whose output rides the main plant, nor one on its model's own path, whose value is mostly the
     * path still ahead and not the share of the income a sale is priced on), the one whose plant earns least, its share
     * of the operating income over its share of the plant. Null when it has none.
     */
    public static function weakestSegment(Stock $stock): ?string
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $weakest = null;
        $lowest = INF;
        foreach (self::plant($momentum) ?? [] as $segment => $share) {
            if ($segment === Segments::MAIN || $share <= 0.0 || in_array($segment, self::jointSegments($stock), true)
                || Sectors::strategyFor($stock->getIndustry())->resolveSegmentPath($stock, $segment) !== null) {
                continue;
            }
            $earning = self::earningShares($momentum, $segment)['income'] / $share;
            if ($earning < $lowest) {
                $lowest = $earning;
                $weakest = $segment;
            }
        }

        return $weakest;
    }

    /**
     * The segment a deal buys into, drawn in proportion to the plant each holds, so deals keep the mix in expectation;
     * a by-product's plant is bought only with the plant it rides, and none into a segment on its model's own path. The
     * main segment before the books open.
     *
     * @param float $uniform A draw on [0, 1).
     */
    public static function acquiringSegment(Stock $stock, float $uniform): string
    {
        $plant = self::plant($stock->getEarningsMomentumZ() ?? []) ?? [];
        // A segment that rides its model's own path would not carry a bought rival along it.
        $strategy = Sectors::strategyFor($stock->getIndustry());
        foreach (array_keys($plant) as $segment) {
            if (in_array($segment, self::jointSegments($stock), true) || $strategy->resolveSegmentPath($stock, $segment) !== null) {
                unset($plant[$segment]);
            }
        }
        $plant = array_filter($plant, static fn(float $share): bool => $share > 0.0);
        $total = array_sum($plant);
        $cumulative = 0.0;
        foreach ($plant as $segment => $share) {
            $cumulative += $share / $total;
            if ($uniform < $cumulative) {
                return $segment;
            }
        }

        return Segments::MAIN;
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
