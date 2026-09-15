<?php

declare(strict_types=1);

namespace App\Service\Market\Index;

use Psr\Log\LoggerInterface;

/**
 * Membership in Redis, so the pages that list constituents read the same roster the index is struck from.
 *
 * Cleared by the market reset's flush, which is what makes a fresh market take its first membership on the
 * first tick rather than inheriting one from a timeline that no longer exists.
 */
final class RedisIndexMembershipStore implements IndexMembershipStoreInterface
{
    public const REDIS_KEY = 'index_membership';

    public function __construct(
        private readonly \Redis $redis,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function current(): ?array
    {
        try {
            $raw = $this->redis->get(self::REDIS_KEY);
        } catch (\Throwable $e) {
            $this->logger?->warning('Index membership read failed: ' . $e->getMessage());

            return null;
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($decoded) || !isset($decoded['tickers']) || !is_array($decoded['tickers'])) {
            return null;
        }

        return [
            'tick' => (int) ($decoded['tick'] ?? 0),
            'tickers' => array_values(array_map('strval', $decoded['tickers'])),
        ];
    }

    public function store(int $tick, array $tickers): void
    {
        try {
            $this->redis->set(self::REDIS_KEY, json_encode([
                'tick' => $tick,
                'tickers' => array_values($tickers),
            ], JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            $this->logger?->warning('Index membership write failed: ' . $e->getMessage());
        }
    }
}
