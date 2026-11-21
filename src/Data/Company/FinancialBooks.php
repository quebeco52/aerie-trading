<?php

declare(strict_types=1);

namespace App\Data\Company;

use App\Entity\Stock;
use App\Service\Corporate\Segment\SegmentBooks;
use App\Service\Model\ModelParam;
use App\Service\Model\Sector\ClearingHouseBusinessModel;
use App\Service\Model\Sector\RetailInsuranceBusinessModel;

/**
 * Financial companies whose revenue books sell into more than one industry market without keeping plant books.
 *
 * Unlike operating segments (Segments), financials carry no physical plant, CIP, or asset turnover by segment: each
 * book sells its revenue share into its own industry market for whole-market sizing, zero-sum rivalry, merger review,
 * and company/industry disclosures, while capital and saturation remain at the firm.
 *
 * @phpstan-type Book array{
 *     label: string,
 *     industry: string,
 *     streams: list<string>,
 *     opening_param?: ModelParam,
 *     opening_weight?: float,
 * }
 */
final class FinancialBooks
{
    /** Key of the company's primary book in its listed industry. */
    public const MAIN = Segments::MAIN;

    private const SHARE_KEY_PREFIX = 'share:';

    /**
     * @var array<string, array{main: string, books: array<string, Book>}>
     */
    private const BY_TICKER = [
        'DOVE' => [
            'main' => 'Property and casualty',
            'books' => [
                'life' => [
                    'label' => 'Life and annuities',
                    'industry' => 'Insurance - Life',
                    'streams' => ['life_insurance_premiums'],
                    'opening_param' => ModelParam::LifeAndAnnuityWeight,
                    'opening_weight' => RetailInsuranceBusinessModel::LIFE_INSURANCE_WEIGHT,
                ],
            ],
        ],
        'ACC' => [
            'main' => 'Clearing and settlement',
            'books' => [
                'custody' => [
                    'label' => 'Custody and float',
                    'industry' => 'Custody Banks',
                    'streams' => ['custody_float'],
                    'opening_param' => ModelParam::CustodyFloatWeight,
                    'opening_weight' => ClearingHouseBusinessModel::CUSTODY_FLOAT_WEIGHT,
                ],
                'data' => [
                    'label' => 'Data and licensing',
                    'industry' => 'Financial Data & Ratings',
                    'streams' => ['data_licensing'],
                    'opening_param' => ModelParam::DataSubscriptionWeight,
                    'opening_weight' => ClearingHouseBusinessModel::DATA_SUBSCRIPTION_WEIGHT,
                ],
            ],
        ],
    ];

    /**
     * The company's multi-market book plan, or null for a firm booking in one market.
     *
     * @return array{main: string, books: array<string, Book>}|null
     */
    public static function forTicker(string $ticker): ?array
    {
        return self::BY_TICKER[$ticker] ?? null;
    }

    /** Whether the stock is a multi-market financial registered in BY_TICKER. */
    public static function hasBooks(Stock $stock): bool
    {
        return self::forTicker($stock->getTicker()) !== null;
    }

    /** @return list<string> */
    public static function tickers(): array
    {
        return array_keys(self::BY_TICKER);
    }

    /**
     * Each book's industry, main book first; empty when the firm has no multi-market financial books or no industry.
     *
     * @return array<string, string>
     */
    public static function industries(Stock $stock): array
    {
        $ownIndustry = (string) $stock->getIndustry();
        $plan = self::forTicker((string) $stock->getTicker());
        if ($plan === null || $ownIndustry === '') {
            return [];
        }

        $industries = [self::MAIN => $ownIndustry];
        foreach ($plan['books'] as $key => $book) {
            $industries[$key] = $book['industry'];
        }

        return $industries;
    }

    /** Display label of the given book on company notes and industry pages. */
    public static function label(Stock $stock, string $book): string
    {
        $plan = self::forTicker((string) $stock->getTicker());
        if ($book === self::MAIN) {
            return $plan['main'] ?? (string) $stock->getIndustry();
        }

        return $plan['books'][$book]['label'] ?? $book;
    }

    /** Ledger record key for the company's book in its target industry (ticker alone for the main book). */
    public static function recordKey(string $ticker, string $book): string
    {
        return SegmentBooks::recordKey($ticker, $book);
    }

    /** The book a revenue stream belongs to, or the main book when not assigned elsewhere. */
    public static function bookOf(string $ticker, string $stream): string
    {
        foreach (self::BY_TICKER[$ticker]['books'] ?? [] as $key => $book) {
            if (in_array($stream, $book['streams'], true)) {
                return $key;
            }
        }

        return self::MAIN;
    }

    /**
     * Secondary financial books whose revenue sells into the given industry.
     *
     * @return list<array{ticker: string, book: string}>
     */
    public static function sellersForIndustry(string $industry): array
    {
        $sellers = [];
        foreach (self::BY_TICKER as $ticker => $plan) {
            foreach ($plan['books'] as $book => $spec) {
                if ($spec['industry'] === $industry) {
                    $sellers[] = ['ticker' => $ticker, 'book' => $book];
                }
            }
        }

        return $sellers;
    }

    /**
     * Each book's share of total revenue (main book first, summing to 1.0).
     *
     * Reads realized or reported stream revenues first, then filed stream shares in momentum state, and falls back
     * before first filing to the firm's opening weight in StockModelTuning.
     *
     * @param array<string, float>|null $streamRevenue
     * @return array<string, float>
     */
    public static function shares(Stock $stock, ?array $streamRevenue = null): array
    {
        $ticker = (string) $stock->getTicker();
        $plan = self::forTicker($ticker);
        if ($plan === null) {
            return [self::MAIN => 1.0];
        }

        $streamShares = self::normalizeStreams($streamRevenue, $stock->getEarningsMomentumZ() ?? []);
        $secondary = [];
        $secondarySum = 0.0;
        foreach ($plan['books'] as $key => $book) {
            if ($streamShares !== [] && $book['streams'] !== []) {
                $weight = 0.0;
                foreach ($book['streams'] as $stream) {
                    $weight += $streamShares[$stream] ?? 0.0;
                }
            } else {
                $defaultWeight = (float) ($book['opening_weight'] ?? 0.0);
                $weight = isset($book['opening_param'])
                    ? StockModelTuning::get($ticker, $book['opening_param'], $defaultWeight)
                    : $defaultWeight;
            }
            $weight = max(0.0, $weight);
            $secondary[$key] = $weight;
            $secondarySum += $weight;
        }

        if ($secondarySum > 1.0) {
            $shares = [self::MAIN => 0.0];
            foreach ($secondary as $key => $weight) {
                $shares[$key] = $weight / $secondarySum;
            }

            return $shares;
        }

        return [self::MAIN => max(0.0, 1.0 - $secondarySum)] + $secondary;
    }

    /**
     * @param array<string, float>|null $streamRevenue
     * @param array<string, mixed>      $momentum
     * @return array<string, float>
     */
    private static function normalizeStreams(?array $streamRevenue, array $momentum): array
    {
        $raw = [];
        if ($streamRevenue !== null) {
            foreach ($streamRevenue as $stream => $value) {
                $positive = max(0.0, (float) $value);
                if ($positive > 0.0) {
                    $raw[(string) $stream] = $positive;
                }
            }
        }
        if ($raw === []) {
            foreach ($momentum as $key => $share) {
                $key = (string) $key;
                if (str_starts_with($key, self::SHARE_KEY_PREFIX) && !str_starts_with($key, self::SHARE_KEY_PREFIX . Segments::ENTERED_PREFIX)) {
                    $positive = max(0.0, (float) $share);
                    if ($positive > 0.0) {
                        $raw[substr($key, strlen(self::SHARE_KEY_PREFIX))] = $positive;
                    }
                }
            }
        }

        $total = array_sum($raw);
        if ($total <= 0.0) {
            return [];
        }

        return array_map(static fn(float $value): float => $value / $total, $raw);
    }
}
