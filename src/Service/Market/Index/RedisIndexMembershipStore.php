<?php

declare(strict_types=1);

namespace App\Service\Market\Index;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Membership in Redis, so the pages that list constituents read the same roster the index is struck from.
 *
 * Cleared by the market reset's flush, which is what makes a fresh market take its first membership on the
 * first tick rather than inheriting one from a timeline that no longer exists.
 *
 * A roster is read once and held until this process writes a new one or the service is reset: the ticker is the
 * only writer and stops for a market reset, and a web worker is reset between requests.
 */
final class RedisIndexMembershipStore implements IndexMembershipStoreInterface, ResetInterface
{
    /**
     * @var array<string, array{tick: int, tickers: list<string>, added: list<string>, deleted: list<string>, weights: array<string, float>, factors: array<string, float>}|null>
     */
    private array $held = [];

    public function __construct(
        private readonly \Redis $redis,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function reset(): void
    {
        $this->held = [];
    }

    public function current(MarketIndex $index): ?array
    {
        if (array_key_exists($index->value, $this->held)) {
            return $this->held[$index->value];
        }

        try {
            $raw = $this->redis->get($index->membershipKey());
        } catch (\Throwable $e) {
            $this->logger?->warning('Index membership read failed: ' . $e->getMessage());

            return null;
        }

        return $this->held[$index->value] = self::decode($raw);
    }

    /**
     * @return array{tick: int, tickers: list<string>, added: list<string>, deleted: list<string>, weights: array<string, float>, factors: array<string, float>}|null
     */
    private static function decode(mixed $raw): ?array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($decoded) || !isset($decoded['tickers']) || !is_array($decoded['tickers'])) {
            return null;
        }

        $list = static fn(mixed $value): array => is_array($value) ? array_values(array_map('strval', $value)) : [];

        // A weight map written by an older build is simply absent; every reader reads a missing factor as
        // one, which is the cap-weighted index this store used to assume every index was.
        $map = static function (mixed $value): array {
            if (!is_array($value)) {
                return [];
            }

            $out = [];
            foreach ($value as $ticker => $weight) {
                if (is_numeric($weight)) {
                    $out[(string) $ticker] = (float) $weight;
                }
            }

            return $out;
        };

        return [
            'tick' => (int) ($decoded['tick'] ?? 0),
            'tickers' => $list($decoded['tickers']),
            'added' => $list($decoded['added'] ?? []),
            'deleted' => $list($decoded['deleted'] ?? []),
            'weights' => $map($decoded['weights'] ?? []),
            'factors' => $map($decoded['factors'] ?? []),
        ];
    }

    public function store(
        MarketIndex $index,
        int $tick,
        array $tickers,
        array $added = [],
        array $deleted = [],
        array $weights = [],
        array $factors = [],
    ): void {
        // Read back on the next call, so the roster held is the one stored, decoded as any reader decodes it.
        unset($this->held[$index->value]);

        try {
            $this->redis->set($index->membershipKey(), json_encode([
                'tick' => $tick,
                'tickers' => array_values($tickers),
                'added' => array_values($added),
                'deleted' => array_values($deleted),
                'weights' => $weights,
                'factors' => $factors,
            ], JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            $this->logger?->warning('Index membership write failed: ' . $e->getMessage());
        }
    }
}
