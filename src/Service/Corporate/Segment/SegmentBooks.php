<?php

declare(strict_types=1);

namespace App\Service\Corporate\Segment;

use App\Data\Company\IndustryAdjacency;
use App\Data\Company\Sectors;
use App\Data\Company\Segments;
use App\Entity\Stock;
use App\Service\Corporate\CapExEngine;
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
    /**
     * Carried stream-state key prefix: 1 when a segment's new plant cleared the hurdle at its industry's price at the
     * last report, 0 when it did not (CapitalBudgetEngine).
     */
    public const STATE_BUILDS = EarningsEngine::CARRIED_STATE_PREFIX . 'segment_builds:';
    /**
     * Carried stream-state key prefix: 1 when a segment's plant earns its cost of capital at a balanced price at the
     * last report, its after-tax margin times its turnover, 0 when it does not (CapitalBudgetEngine). Filed for a
     * company on one plant as its main segment's.
     */
    public const STATE_PAYS = EarningsEngine::CARRIED_STATE_PREFIX . 'segment_pays:';
    /**
     * Carried stream-state key prefix: the plant a segment the company is building into a new industry still has under
     * construction, in currency; it joins the segment's plant share as it is placed in service.
     */
    public const STATE_PENDING = EarningsEngine::CARRIED_STATE_PREFIX . 'segment_pending:';
    /**
     * Carried stream-state key prefix: how far a business built into a new industry still falls short of the margin it
     * will earn once it has learned the trade, as a share of that margin; it fades as the business learns.
     */
    public const STATE_LEARNING = EarningsEngine::CARRIED_STATE_PREFIX . 'segment_learning:';
    /** Carried stream-state key prefix: how far short of its learned margin a venture started (STATE_LEARNING). */
    public const STATE_LEARNING_START = EarningsEngine::CARRIED_STATE_PREFIX . 'segment_learning_start:';
    /**
     * Carried stream-state key prefix: what a venture turns out to earn once learned against what its company expected,
     * in log; drawn at entry and revealed only as it learns (SegmentEntry::outcome()).
     */
    public const STATE_VENTURE_OUTCOME = EarningsEngine::CARRIED_STATE_PREFIX . 'segment_venture_outcome:';
    /**
     * Carried stream-state key prefix: how far a venture means to grow its share of its new market, in log
     * (SegmentEntry::pathLevel()).
     */
    public const STATE_VENTURE_TARGET = EarningsEngine::CARRIED_STATE_PREFIX . 'segment_venture_target:';
    /** Carried stream-state key prefix: when a venture entered its industry, in years of play. */
    public const STATE_VENTURE_START = EarningsEngine::CARRIED_STATE_PREFIX . 'segment_venture_start:';
    /** Carried stream-state key prefix: when its company gave up on a venture that does not earn its cost, in years. */
    public const STATE_VENTURE_ABANDONED = EarningsEngine::CARRIED_STATE_PREFIX . 'segment_venture_abandoned:';
    /**
     * Carried stream-state key prefix: the share of what a business bought into a new industry will turn out to earn
     * still to show, 1 at the deal and none once integrated (SegmentEntry::INTEGRATION_YEARS).
     */
    public const STATE_INTEGRATING = EarningsEngine::CARRIED_STATE_PREFIX . 'segment_integrating:';
    /**
     * Carried stream-state key prefix: when its company put up for sale a business it bought that does not earn its
     * cost.
     */
    public const STATE_FOR_SALE = EarningsEngine::CARRIED_STATE_PREFIX . 'segment_for_sale:';
    /** Carried stream-state key prefix: the goodwill a company paid for a business it bought into a new industry. */
    public const STATE_GOODWILL = EarningsEngine::CARRIED_STATE_PREFIX . 'segment_goodwill:';
    /** Stream-state key prefix of a line's weight in the mix the plan steers to (StreamContext). */
    private const STREAM_WEIGHT_PREFIX = 'weight:';

    // --- Industry Ledger ---
    /** Joins a ticker and a segment into the key of the segment's record in its industry's ledger. */
    public const RECORD_KEY_SEPARATOR = '/';

    /**
     * Whether the company keeps plant by segment: its seeded lines sell into several markets, or it has opened books to
     * enter a new one.
     */
    public static function keepsBooks(Stock $stock): bool
    {
        return Segments::forTicker($stock->getTicker()) !== null || self::plant($stock->getEarningsMomentumZ() ?? []) !== null;
    }

    /**
     * Each segment's industry, the main segment's first, then the seeded segments', then those the company entered in
     * play (Segments::enteredKey()), whether their plant is in service yet or still being built.
     *
     * @return array<string, string>
     */
    public static function industries(Stock $stock): array
    {
        $industries = [Segments::MAIN => (string) $stock->getIndustry()];
        foreach (Segments::forTicker($stock->getTicker())['segments'] ?? [] as $key => $segment) {
            $industries[$key] = $segment['industry'];
        }
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        foreach ([...array_keys(self::filed($momentum, self::STATE_PLANT)), ...array_keys(self::filed($momentum, self::STATE_PENDING))] as $segment) {
            $industry = Segments::enteredIndustry((string) $segment);
            if ($industry !== null) {
                $industries[(string) $segment] = $industry;
            }
        }

        return $industries;
    }

    /** A segment's name as the company reports it: its lore's, or for one entered in play, its industry's business. */
    public static function label(Stock $stock, string $segment): string
    {
        $books = Segments::forTicker($stock->getTicker());
        if ($segment === Segments::MAIN) {
            return $books['main'] ?? (string) $stock->getIndustry();
        }
        $entered = Segments::enteredIndustry($segment);

        return $books['segments'][$segment]['label'] ?? ($entered === null ? $segment : IndustryAdjacency::label($entered) ?? $entered);
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

        return $segmentStrategy->getFadedSecularGrowthRate($stock, $totalTime) + (self::segmentPath($strategy, $stock, $segment, $totalTime)['growth'] ?? 0.0);
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
     * The segments a deal can buy into, each with its share of the plant: not a by-product, whose plant is bought only
     * with the plant it rides; not one on its model's own path, which would not carry a bought rival along it; not a
     * venture its company has given up on, nor one without plant. The main segment alone before the books open.
     *
     * @return array<string, float>
     */
    public static function dealSegments(Stock $stock): array
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $plant = self::plant($momentum) ?? [Segments::MAIN => 1.0];
        $strategy = Sectors::strategyFor($stock->getIndustry());
        foreach (array_keys($plant) as $segment) {
            if (in_array($segment, self::jointSegments($stock), true) || $strategy->resolveSegmentPath($stock, $segment) !== null
                || self::isAbandoned($momentum, $segment)) {
                unset($plant[$segment]);
            }
        }

        return array_filter($plant, static fn(float $share): bool => $share > 0.0);
    }

    /**
     * Whether any segment a deal can buy into (dealSegments()) earned its cost of capital at a balanced price at the
     * last report (STATE_PAYS): a company none of whose businesses does looks outside them for its deals. One not yet
     * filed counts as earning it.
     */
    public static function paysAnywhere(Stock $stock): bool
    {
        $pays = self::filed($stock->getEarningsMomentumZ() ?? [], self::STATE_PAYS);
        foreach (array_keys(self::dealSegments($stock)) as $segment) {
            if (($pays[$segment] ?? 1.0) > 0.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the company still runs, or is still building, a business it entered in play and has not given up on: it
     * takes on one new industry at a time.
     */
    public static function isDiversifying(Stock $stock): bool
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        foreach ([...array_keys(self::dealSegments($stock)), ...array_keys(self::pending($momentum))] as $segment) {
            if (Segments::enteredIndustry((string) $segment) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The revenue targets after a deal brings a segment in between reports: it takes its share of the sales at its
     * plant and turnover (companyEconomics()), the others the rest in the proportions they had (each its share of the
     * sales where none were filed), so a sale before the next report prices it on what it sells.
     *
     * @param array<string, mixed> $momentum
     * @return array<string, float> Carried state.
     */
    public static function boughtTargets(array $momentum, string $segment): array
    {
        $sales = self::companyEconomics($momentum)['sales'] ?? [];
        $targets = self::filed($momentum, self::STATE_TARGET);
        unset($targets[$segment]);
        $others = array_sum($targets);
        $bought = $sales[$segment] ?? 0.0;
        $state = [];
        foreach ($others > 0.0 ? $targets : array_diff_key($sales, [$segment => true]) as $key => $target) {
            $state[self::STATE_TARGET . $key] = $others > 0.0 ? $target * (1.0 - $bought) / $others : $target;
        }
        $state[self::STATE_TARGET . $segment] = $bought;

        return $state;
    }

    /**
     * The segment a deal buys into, drawn in proportion to the plant each holds, so deals keep the mix in expectation:
     * only those a deal can buy into (dealSegments()), and one whose new plant did not clear its hurdle at the last
     * report (a glut) only as far as management supports its weak segments. Where every segment is in a glut, the deal
     * is drawn by plant alone, as a company in one market buys into its own. The main segment before the books open.
     *
     * @param float $uniform A draw on [0, 1).
     */
    public static function acquiringSegment(Stock $stock, float $uniform): string
    {
        $plant = self::dealSegments($stock);
        $builds = self::filed($stock->getEarningsMomentumZ() ?? [], self::STATE_BUILDS);
        $support = $stock->getManagementProfile()->weakSegmentSupport();
        $eligible = [];
        foreach ($plant as $segment => $share) {
            $weight = ($builds[$segment] ?? 1.0) > 0.0 ? $share : $share * $support;
            if ($weight > 0.0) {
                $eligible[$segment] = $weight;
            }
        }
        $plant = $eligible === [] ? $plant : $eligible;
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
     * The growth funds each segment's plan gets: all of it while the funds cover every plan; when they do not, the
     * plans that earn most are filled first (Stein 1997), blended with a pro-rata split as far as management supports
     * its weak segments (Scharfstein & Stein 2000). What a plan does not get is left for the treasury.
     *
     * @param array<string, float> $plans   Segment => its growth plan.
     * @param array<string, float> $returns Segment => the return its new plant earns.
     * @param float                $support The share of a strict ranking management spreads pro rata instead.
     * @return array<string, float>
     */
    public static function allotByReturn(array $plans, array $returns, float $funds, float $support): array
    {
        $total = array_sum($plans);
        if ($total <= 0.0 || $funds >= $total) {
            return $plans;
        }

        $order = array_keys($plans);
        usort($order, static fn(string $a, string $b): int => ($returns[$b] ?? 0.0) <=> ($returns[$a] ?? 0.0));
        $left = max(0.0, $funds);
        $allotted = [];
        foreach ($order as $segment) {
            $ranked = min($plans[$segment], $left);
            $left -= $ranked;
            $allotted[$segment] = ((1.0 - $support) * $ranked) + ($support * $plans[$segment] * max(0.0, $funds) / $total);
        }

        return $allotted;
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
     * The state that starts building a segment into a new industry: its plant under construction until the company's
     * queue places it in service (completePending()), so it holds no plant share yet; a market in which the plant it
     * will hold has its share of its industry's (open()); the margin and turnover its company expects it to earn once
     * it has learned the trade, how far short of that margin it starts, and what it will turn out to earn instead; and
     * the share of its market it means to grow to and when it set out (SegmentEntry).
     *
     * @param float $construction The plant it builds, in currency.
     * @param float $plannedShare The share of the company's plant that will be once built.
     * @param float $marketShare  Its share of its industry's market once built, where known.
     * @param array{margin: float, turnover: float, learning: float, outcome: float, target: float, start: float} $venture
     * @return array<string, float> Carried state.
     */
    public static function startVenture(string $segment, float $construction, float $plannedShare, float $firmMarketGrowth, float $segmentGrowth, float $firmShare, float $marketShare, array $venture): array
    {
        if ($construction <= 0.0 || $plannedShare <= 0.0) {
            return [];
        }

        $state = self::open([$segment => $plannedShare], $firmMarketGrowth, [$segment => $segmentGrowth], $firmShare, $marketShare > 0.0 ? [$segment => $marketShare] : []);
        unset($state[self::STATE_PLANT . $segment]);

        return $state + [
            self::STATE_PENDING . $segment => $construction,
            self::STATE_MARGIN . $segment => $venture['margin'],
            self::STATE_TURNOVER . $segment => $venture['turnover'],
            self::STATE_LEARNING . $segment => max(0.0, $venture['learning']),
            self::STATE_LEARNING_START . $segment => max(0.0, $venture['learning']),
            self::STATE_VENTURE_OUTCOME . $segment => $venture['outcome'],
            self::STATE_VENTURE_TARGET . $segment => max(0.0, $venture['target']),
            self::STATE_VENTURE_START . $segment => $venture['start'],
        ];
    }

    /**
     * The path a segment entered in play grows along toward the share of its new market it means to reach
     * (SegmentEntry::pathLevel()), from where it entered; for one its company has given up on, a claim to almost none
     * of it, so its plant withers. Null for any other segment.
     *
     * @param array<string, mixed> $momentum
     * @return array{level: float, growth: float}|null
     */
    public static function venturePath(array $momentum, string $segment, float $totalTime): ?array
    {
        if (self::isAbandoned($momentum, $segment)) {
            return ['level' => log(SegmentEntry::ABANDONED_TREND_SHARE), 'growth' => 0.0];
        }
        if (!isset($momentum[self::STATE_VENTURE_TARGET . $segment])) {
            return null;
        }
        $target = (float) $momentum[self::STATE_VENTURE_TARGET . $segment];
        $years = $totalTime - (float) ($momentum[self::STATE_VENTURE_START . $segment] ?? $totalTime);

        return ['level' => SegmentEntry::pathLevel($target, $years), 'growth' => SegmentEntry::pathGrowth($target, $years)];
    }

    /**
     * The path a segment's plant follows of its own, where it has one: the one its company's model plans
     * (OperatingStrategyInterface::resolveSegmentPath()), or a venture's (venturePath()). Null for any other segment.
     *
     * @return array{level: float, growth: float}|null
     */
    public static function segmentPath(OperatingStrategyInterface $strategy, Stock $stock, string $segment, float $totalTime): ?array
    {
        $planned = $strategy->resolveSegmentPath($stock, $segment);

        return $planned === null ? self::venturePath($stock->getEarningsMomentumZ() ?? [], $segment, $totalTime) : ['level' => $planned['level'], 'growth' => $planned['growth']];
    }

    /**
     * Whether a segment was built into its industry in play (startVenture()), not bought there as a going concern.
     *
     * @param array<string, mixed> $momentum
     */
    public static function isBuilt(array $momentum, string $segment): bool
    {
        return isset($momentum[self::STATE_VENTURE_START . $segment]);
    }

    /**
     * Whether its company has given up on a segment it entered in play.
     *
     * @param array<string, mixed> $momentum
     */
    public static function isAbandoned(array $momentum, string $segment): bool
    {
        return isset($momentum[self::STATE_VENTURE_ABANDONED . $segment]);
    }

    /**
     * The plant each segment entered in play still has under construction, in currency; those with none left out.
     *
     * @param array<string, mixed> $momentum
     * @return array<string, float>
     */
    public static function pending(array $momentum): array
    {
        return array_filter(self::filed($momentum, self::STATE_PENDING), static fn(float $amount): bool => $amount > 0.0);
    }

    /**
     * The momentum with the plant each segment entered in play has under construction scaled, as a sale of part of the
     * company takes that share of its construction.
     *
     * @param array<string, mixed> $momentum
     * @return array<string, mixed>
     */
    public static function scalePending(array $momentum, float $retained): array
    {
        foreach (self::filed($momentum, self::STATE_PENDING) as $segment => $amount) {
            $momentum[self::STATE_PENDING . $segment] = $amount * max(0.0, $retained);
        }

        return $momentum;
    }

    /**
     * The state that places in service part of the plant each segment entered in play has under construction: each
     * completes the share of its construction the company's whole queue completed this quarter, as the queue is one
     * pool, and joins the segment's plant share (acquireInto(); a segment's first plant brings its share in). What is
     * left below what the queue empties whole is placed with it.
     *
     * @param array<string, mixed> $momentum
     * @param float $completedShare The share of the company's construction placed in service this quarter.
     * @param float $plantBase      The plant the shares are of (net PP&E).
     * @return array<string, float> Carried state.
     */
    public static function completePending(array $momentum, float $completedShare, float $plantBase): array
    {
        $pending = self::pending($momentum);
        if ($pending === []) {
            return [];
        }

        $plant = self::plant($momentum) ?? [Segments::MAIN => 1.0];
        $base = max(0.0, $plantBase);
        $state = [];
        foreach ($pending as $segment => $amount) {
            $placed = $amount * max(0.0, min(1.0, $completedShare));
            $placed = $amount - $placed < CapExEngine::CIP_SNAP_AMOUNT ? $amount : $placed;
            $plant = self::acquireInto($plant, $segment, $base, $placed);
            $base += $placed;
            $state[self::STATE_PENDING . $segment] = $amount - $placed;
        }
        foreach ($plant as $segment => $share) {
            $state[self::STATE_PLANT . $segment] = $share;
        }

        return $state;
    }

    /**
     * How far each segment built into a new industry still falls short of its learned margin, as a share of it; a
     * segment that has learned the trade, or was never built, is not listed.
     *
     * @param array<string, mixed> $momentum
     * @return array<string, float>
     */
    public static function learningGaps(array $momentum): array
    {
        return self::filed($momentum, self::STATE_LEARNING);
    }

    /**
     * The state that lets each segment built into a new industry learn its trade for dt years (SegmentEntry), and shows
     * as much of what it will turn out to earn as it has learned.
     *
     * @param array<string, mixed> $momentum
     * @return array<string, float> Carried state.
     */
    public static function learn(array $momentum, float $dt): array
    {
        $margins = self::filed($momentum, self::STATE_MARGIN);
        $starts = self::filed($momentum, self::STATE_LEARNING_START);
        $outcomes = self::filed($momentum, self::STATE_VENTURE_OUTCOME);
        $state = [];
        foreach (self::filed($momentum, self::STATE_LEARNING) as $segment => $gap) {
            $left = SegmentEntry::learningLeft($gap, $dt);
            $state[self::STATE_LEARNING . $segment] = $left;
            // What it learns shows what it will earn: the margin it books moves from what was expected toward what it
            // turns out to earn, as far as its learning has gone.
            if (($starts[$segment] ?? 0.0) > 0.0 && isset($outcomes[$segment], $margins[$segment])) {
                $state[self::STATE_MARGIN . $segment] = $margins[$segment] * exp($outcomes[$segment] * ($gap - $left) / $starts[$segment]);
            }
        }
        // A business bought shows what it earns under its new owner as it is integrated.
        foreach (self::filed($momentum, self::STATE_INTEGRATING) as $segment => $unshown) {
            $left = SegmentEntry::integrationLeft($unshown, $dt);
            $state[self::STATE_INTEGRATING . $segment] = $left;
            if (isset($outcomes[$segment], $margins[$segment])) {
                $state[self::STATE_MARGIN . $segment] = $margins[$segment] * exp($outcomes[$segment] * ($unshown - $left));
            }
        }

        return $state;
    }

    /**
     * The state that starts integrating a business bought into a new industry: what it will turn out to earn under its
     * new owner against what was counted on (SegmentEntry::boughtOutcome()), all still to show, and the goodwill paid
     * for it, which leaves with it if it is sold.
     *
     * @return array<string, float> Carried state.
     */
    public static function buyIn(string $segment, float $outcome, float $goodwill): array
    {
        return [
            self::STATE_VENTURE_OUTCOME . $segment => $outcome,
            self::STATE_INTEGRATING . $segment => 1.0,
            self::STATE_GOODWILL . $segment => max(0.0, $goodwill),
        ];
    }

    /**
     * Whether a business bought into a new industry has shown what it earns under its new owner; one bought before
     * integration was tracked counts as integrated.
     *
     * @param array<string, mixed> $momentum
     */
    public static function isIntegrated(array $momentum, string $segment): bool
    {
        return (float) ($momentum[self::STATE_INTEGRATING . $segment] ?? 0.0) <= 0.0;
    }

    /** The business its company has put up for sale and still runs, if any. */
    public static function forSale(Stock $stock): ?string
    {
        foreach (array_keys(self::filed($stock->getEarningsMomentumZ() ?? [], self::STATE_FOR_SALE)) as $segment) {
            if (self::holdsPlant($stock, (string) $segment)) {
                return (string) $segment;
            }
        }

        return null;
    }

    /**
     * The goodwill a sale takes with it: what was paid for the business, where it was bought into a new industry and
     * that is known, or else its share of the goodwill not tied to one (the company's own past deals), as a slice of
     * the company takes.
     *
     * @param array<string, mixed> $momentum
     * @param string|null          $segment  The segment sold whole; null for a slice of the whole company.
     */
    public static function goodwillSold(array $momentum, ?string $segment, float $fraction, float $goodwill): float
    {
        $tied = self::filed($momentum, self::STATE_GOODWILL);
        if ($segment !== null && isset($tied[$segment])) {
            return min(max(0.0, $goodwill), $tied[$segment]);
        }

        return max(0.0, min(1.0, $fraction)) * ($segment === null ? max(0.0, $goodwill) : max(0.0, $goodwill - array_sum($tied)));
    }

    /**
     * The goodwill tied to each business bought into a new industry after some of the company's goodwill leaves or is
     * written down: none for the business sold whole, and the kept share of each where the whole company shrinks (a
     * slice sold, or an impairment of the firm's goodwill as one reporting unit).
     *
     * @param array<string, mixed> $momentum
     * @param string|null          $segment  The segment sold whole; null when every business keeps the same share.
     * @return array<string, float> Carried state.
     */
    public static function keepGoodwill(array $momentum, ?string $segment, float $kept): array
    {
        $state = [];
        foreach (self::filed($momentum, self::STATE_GOODWILL) as $key => $amount) {
            if ($segment === null || $key === $segment) {
                $state[self::STATE_GOODWILL . $key] = $segment === null ? $amount * max(0.0, $kept) : 0.0;
            }
        }

        return $state;
    }

    /**
     * What the segments still learning their trade cost the company, over its revenue at the balanced price: each
     * one's share of the sales times the margin it will earn once learned times how far it still falls short of it.
     *
     * @param array<string, mixed> $momentum
     * @param array<string, float> $sales Segment => its share of the company's revenue at the balanced price.
     */
    public static function learningDrag(array $momentum, array $sales): float
    {
        $margins = self::filed($momentum, self::STATE_MARGIN);
        $drag = 0.0;
        foreach (self::filed($momentum, self::STATE_LEARNING) as $segment => $gap) {
            $drag += ($sales[$segment] ?? 0.0) * ($margins[$segment] ?? 0.0) * max(0.0, $gap);
        }

        return $drag;
    }

    /**
     * A report's lines with each segment entered in play given its own: the model, which has no line for it, earned the
     * whole quarter, so the segment takes its share of the quarter's revenue (its target) as one line under its key and
     * the model's lines give that up in proportion. The revenue is counted once.
     *
     * @param array<string, mixed>            $momentum
     * @param array<string, float|int|string> $streamRevenue
     * @return array<string, float>
     */
    public static function carveEnteredLines(array $momentum, array $streamRevenue): array
    {
        $lines = array_map(static fn(float|int|string $amount): float => (float) $amount, $streamRevenue);
        $entered = array_filter(
            self::filed($momentum, self::STATE_TARGET),
            static fn(float $target, string $segment): bool => $target > 0.0 && Segments::enteredIndustry($segment) !== null,
            ARRAY_FILTER_USE_BOTH,
        );
        $carved = array_sum($entered);
        if ($carved <= 0.0) {
            return $lines;
        }

        $revenue = array_sum($lines);
        $lines = array_map(static fn(float $amount): float => $amount * (1.0 - $carved), $lines);
        foreach ($entered as $segment => $target) {
            $lines[$segment] = $revenue * $target;
        }

        return $lines;
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
