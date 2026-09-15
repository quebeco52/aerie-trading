<?php

declare(strict_types=1);

namespace App\Service\Market\Index;

use App\Service\Math\FinancialConstants;

/**
 * The indices the exchange publishes. Each is struck on a fund of the same ticker.
 *
 * Four, because they answer different questions, and each answers its own by making three declarations the
 * committee reads: what it is allowed to hold (its UNIVERSE), who of those it takes (its RANKING and seat
 * count), and how it divides itself between them (its WEIGHTING).
 *
 *   - The HEADLINE index is the market's scoreboard: a fixed number of the largest names by float, with a
 *     membership that has to be earned and can be lost, cap-weighted, and the main benchmark the passive
 *     book tracks.
 *   - The COMPOSITE is the whole board, every name that is still listed, weighted the same way — the market
 *     itself rather than a selection from it. A name outside the headline still moves the composite, which
 *     is how a broad rally that misses the largest names becomes visible.
 *   - The LOW-VOLATILITY index selects on quiet rather than size and weights on it too, which is the low
 *     volatility anomaly as an investable thing (Haugen & Baker 1991; Blitz & van Vliet 2007): it should
 *     lag a rising market and lose less in a falling one, and it is the one index here whose membership
 *     turns over because RISK changed rather than because value did.
 *   - The STAPLES index is a single sector, capped so that no one name can run away with a fund of six
 *     companies. It is the defensive leg of the board and the cleanest read on sector rotation.
 *
 * All four go through the same committee. A whole-board composite has no seats to fight over, but it still
 * needs its divisor restated whenever the board changes, or the level jumps on a day nobody made any money.
 */
enum MarketIndex: string
{
    /**
     * Who publishes the Lakebird family, and the listed company that is.
     *
     * An index and the fund tracking it are separate businesses, and naming both after the fund's sponsor is
     * the commonest way a fictional market gives itself away. Here the index carries its PUBLISHER's name,
     * which is the ordinary arrangement — the Dow Jones average was published by Dow Jones, the Nikkei by a
     * newspaper — and the funds licence it from them out of the expense ratio their holders pay.
     *
     * It also creates the conflict that arrangement always creates, and this one is not decorative:
     * Lakebird Bank is the LARGEST CONSTITUENT of the index it publishes. The house writing the rules that
     * decide its own weight is exactly why Morgan Stanley spun MSCI out and Barclays sold its benchmarks to
     * Bloomberg. Whatever governs the publisher's own seat is therefore a rule of the index rather than a
     * footnote to it — see the eligibility screens below.
     */
    public const PROVIDER = 'Lakebird Bank';

    /** The publisher's own listing, so a page can send a reader to the conflict rather than describe it. */
    public const PROVIDER_TICKER = 'LAKE';

    case Headline = 'LBI';
    case Composite = 'LBC';
    case LowVolatility = 'LBV';
    case Staples = 'LBS';

    /** The index the passive book benchmarks against and the pages quote as "the market". */
    public static function benchmark(): self
    {
        return self::Headline;
    }

    /**
     * The index whose membership is the market itself.
     *
     * Read wherever something needs the market's OWN weights rather than an index's view of them — chiefly
     * the passive ownership calculation, which asks how much of a name passive money holds relative to how
     * much of the market that name is.
     */
    public static function market(): self
    {
        return self::Composite;
    }

    /** Seats the index carries, or null for every eligible name. */
    public function constituentCount(): ?int
    {
        return match ($this) {
            self::Headline => FinancialConstants::INDEX_CONSTITUENT_COUNT,
            self::LowVolatility => FinancialConstants::INDEX_LOW_VOLATILITY_COUNT,
            self::Composite, self::Staples => null,
        };
    }

    /**
     * The sector the index is confined to, or null when it may hold the whole board.
     *
     * A sector index does not rank the market and stop at a sector boundary; the boundary IS the universe,
     * and the seat count then applies to what is left. With no seat count, as here, the sector fund holds
     * its whole sector, which is what a sector fund is.
     */
    public function sector(): ?string
    {
        return match ($this) {
            self::Staples => 'Consumer Staples',
            self::Headline, self::Composite, self::LowVolatility => null,
        };
    }

    /** What the index ranks its eligible universe on when it selects members. */
    public function ranking(): IndexRanking
    {
        return match ($this) {
            self::LowVolatility => IndexRanking::TrailingVolatility,
            self::Headline, self::Composite, self::Staples => IndexRanking::FloatCapitalisation,
        };
    }

    /** How the index divides itself between the members it has selected. */
    public function weighting(): IndexWeighting
    {
        return match ($this) {
            self::LowVolatility => IndexWeighting::InverseVolatility,
            self::Headline, self::Composite, self::Staples => IndexWeighting::FloatCapitalisation,
        };
    }

    /**
     * The most any one constituent may weigh, or null where the index lets weights fall where they will.
     *
     * Two indices cap, for two different reasons. The SECTOR fund caps because a six-company fund weighted
     * purely by size cannot satisfy the diversification limits a fund has to satisfy to be sold as one. The
     * HEADLINE index caps because a thirty-name benchmark carrying half its weight in three companies has
     * stopped measuring the market and started measuring those three — which is why national benchmarks cap
     * and why the S&P 500, with five hundred names, does not need to. Its cap is deliberately looser than
     * the sector fund's is tight: the sector fund caps to stay sellable as a diversified fund, while the
     * headline index caps only to stop a handful of names becoming the whole measurement.
     *
     * The headline cap does a second job here that a real one usually does not have to. The publisher of
     * this index is its largest constituent, and a cap is the one rule that bounds how much of its own
     * benchmark it can come to occupy, whatever happens to its share price.
     */
    public function weightCap(): ?float
    {
        return match ($this) {
            self::Headline => FinancialConstants::INDEX_HEADLINE_MAX_CONSTITUENT_WEIGHT,
            self::Staples => FinancialConstants::INDEX_MAX_CONSTITUENT_WEIGHT,
            self::Composite, self::LowVolatility => null,
        };
    }

    /**
     * Whether the index also applies the regulated-investment-company CONCENTRATION budget on top of its
     * single-name cap: the constituents above one threshold limited in combination.
     *
     * Only the sector fund. It is the rule that exists because a fund holding one industry can satisfy a
     * per-name cap and still be a bet on one industry, and it is meaningless for a broad index that already
     * spans the board.
     */
    public function appliesConcentrationBudget(): bool
    {
        return $this === self::Staples;
    }

    /**
     * Whether a company must be profitable to be ADMITTED to this index.
     *
     * The headline index is the market's scoreboard and admission to it is a statement that a company has
     * arrived, so it is not made on size alone: trailing twelve-month earnings and the most recent quarter
     * both have to be positive. This is the S&P 500's viability screen, and the asymmetry is the point — it
     * governs joining, never staying. A member that falls into losses keeps its seat until the ranking bands
     * take it, because an index that ejected every company having a bad year would be a momentum strategy
     * rather than a benchmark.
     */
    public function requiresEarningsViability(): bool
    {
        return $this === self::Headline;
    }

    /**
     * Whether the index is a SELECTION from a larger universe. A selected membership is banded and its
     * boundary is worth watching; an unselected one changes only when a name is born, dies, or changes
     * sector.
     */
    public function isSelective(): bool
    {
        return $this->constituentCount() !== null;
    }

    /**
     * The share of the market's indexed money that tracks this index.
     *
     * Passive assets are not one pool behind one benchmark. They are split across the vehicles that exist,
     * and that split is what decides how much passive ownership any individual name carries: a quiet staple
     * is held by four funds at once and a volatile mid-cap outside the headline by one. The shares sum to
     * one across the published indices, so the indexed book as a whole holds the market as a whole.
     */
    public function passiveShare(): float
    {
        return match ($this) {
            self::Headline => FinancialConstants::INDEX_PASSIVE_SHARE_HEADLINE,
            self::Composite => FinancialConstants::INDEX_PASSIVE_SHARE_COMPOSITE,
            self::Staples => FinancialConstants::INDEX_PASSIVE_SHARE_STAPLES,
            self::LowVolatility => FinancialConstants::INDEX_PASSIVE_SHARE_LOW_VOLATILITY,
        };
    }

    /** Whether passive money holds this index rather than merely quoting it. */
    public function carriesPassiveBook(): bool
    {
        return $this->passiveShare() > 0.0;
    }

    /**
     * The published name of the INDEX, which is not the name of the fund that tracks it.
     *
     * The headline index carries its seat count, the way a fixed-membership index conventionally does, and
     * the count is read from the constant rather than written into the string — an index called "Lakebird
     * 30" that had thirty-two members would be a worse name than no name at all.
     */
    public function indexName(): string
    {
        return match ($this) {
            self::Headline => sprintf('Lakebird %d', FinancialConstants::INDEX_CONSTITUENT_COUNT),
            self::Composite => 'Lakebird Composite',
            self::LowVolatility => 'Lakebird Low Volatility',
            self::Staples => 'Lakebird Consumer Staples',
        };
    }

    /** What the index is FOR, as the pages label it. The name says which index; this says which job. */
    public function label(): string
    {
        return match ($this) {
            self::Headline => 'Headline index',
            self::Composite => 'Composite index',
            self::LowVolatility => 'Low volatility index',
            self::Staples => 'Consumer staples index',
        };
    }

    /** What the index is meant to measure, for the page's methodology card. */
    public function mandate(): string
    {
        return match ($this) {
            self::Headline => sprintf(
                'The %d largest listed companies by float-adjusted capitalisation, weighted by float and capped so that no constituent exceeds %s%%. Admission additionally requires profitability: trailing twelve-month earnings and the most recent quarter must both be positive, a test that governs joining rather than staying. Membership is reviewed quarterly and banded, so a name is admitted only once it has clearly risen into the index and dropped only once it has clearly fallen out of it.',
                FinancialConstants::INDEX_CONSTITUENT_COUNT,
                number_format(FinancialConstants::INDEX_HEADLINE_MAX_CONSTITUENT_WEIGHT * 100, 0)
            ),
            self::Composite => 'Every listed company, weighted by float-adjusted capitalisation. The market as a whole rather than a selection from it: nothing is admitted or dropped except by listing or delisting.',
            self::LowVolatility => sprintf(
                'The %d listed companies with the lowest trailing volatility, each weighted by the reciprocal of that volatility so the quietest names carry the most. Selected and weighted on risk alone, with no view on what anything is worth.',
                FinancialConstants::INDEX_LOW_VOLATILITY_COUNT
            ),
            self::Staples => sprintf(
                'Every listed consumer staples company, weighted by float-adjusted capitalisation and capped so that no constituent exceeds %s%% and the constituents above %s%% do not exceed %s%% in combination.',
                number_format(FinancialConstants::INDEX_MAX_CONSTITUENT_WEIGHT * 100, 1),
                number_format(FinancialConstants::INDEX_CONCENTRATION_THRESHOLD * 100, 1),
                number_format(FinancialConstants::INDEX_CONCENTRATION_BUDGET * 100, 0)
            ),
        };
    }

    /** Redis key holding this index's membership. */
    public function membershipKey(): string
    {
        return 'index_membership:' . $this->value;
    }
}
