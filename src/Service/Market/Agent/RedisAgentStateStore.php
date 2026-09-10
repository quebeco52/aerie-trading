<?php

declare(strict_types=1);

namespace App\Service\Market\Agent;

use Psr\Log\LoggerInterface;

/**
 * One Redis hash, field per ticker, value the JSON book.
 *
 * Failures degrade to "this name has no agents yet", which rebuilds the book flat rather than aborting the
 * tick. The cost of that is real but bounded — the population restarts even and the positions restart at
 * zero, so the market loses its agent memory and rebuilds it over the following ticks. Holding the tick
 * instead would stop the whole market because an auxiliary cache was unavailable.
 */
final class RedisAgentStateStore implements AgentStateStoreInterface
{
    private const KEY = 'agent_state';

    public function __construct(
        private readonly \Redis $redis,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function read(string $ticker): ?array
    {
        try {
            /** @phpstan-ignore method.notFound (phpredis hash commands are absent from the analysis stub) */
            $raw = $this->redis->hGet(self::KEY, $ticker);
        } catch (\Throwable $e) {
            $this->logger?->warning('Agent state read failed: ' . $e->getMessage());

            return null;
        }

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['positions'], $decoded['fitness'])) {
            return null;
        }

        return [
            'positions' => array_map('floatval', (array) $decoded['positions']),
            'fitness' => array_map('floatval', (array) $decoded['fitness']),
            'last_price' => (float) ($decoded['last_price'] ?? 0.0),
        ];
    }

    public function write(string $ticker, array $state): void
    {
        try {
            /** @phpstan-ignore method.notFound (phpredis hash commands are absent from the analysis stub) */
            $this->redis->hSet(self::KEY, $ticker, json_encode($state, JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            $this->logger?->warning('Agent state write failed: ' . $e->getMessage());
        }
    }
}
