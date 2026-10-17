<?php

declare(strict_types=1);

namespace App\Service\Market\Option;

use Psr\Log\LoggerInterface;

/**
 * Dealer exposure in Redis, so the pages that display it read the same figure the ticker hedges on.
 *
 * Every call is guarded. This store is read and written on the hedging pass, which runs OUTSIDE the tick's
 * transaction, so an uncaught phpredis exception here would take the ticker daemon down rather than skip a
 * hedge — the same reasoning that made RedisOrderFlowStore swallow its own failures. A missed hedge is one
 * tick of stale delta on a desk that re-measures its whole book on the next sweep; a dead ticker is the
 * market stopping.
 */
final class RedisDealerGammaStore implements DealerGammaStoreInterface
{
    private const KEY = 'dealer_gamma';

    public function __construct(
        private readonly \Redis $redis,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function record(string $ticker, array $entry): void
    {
        try {
            /** @phpstan-ignore method.notFound (phpredis hash commands are absent from the analysis stub) */
            $this->redis->hSet(self::KEY, $ticker, json_encode(self::fields($entry), JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            $this->logger?->warning('Dealer gamma record failed: ' . $e->getMessage());
        }
    }

    public function read(string $ticker): ?array
    {
        try {
            /** @phpstan-ignore method.notFound (phpredis hash commands are absent from the analysis stub) */
            $raw = $this->redis->hGet(self::KEY, $ticker);
        } catch (\Throwable $e) {
            $this->logger?->warning('Dealer gamma read failed: ' . $e->getMessage());

            return null;
        }

        return $this->decode($raw);
    }

    public function readAll(): array
    {
        try {
            /** @phpstan-ignore method.notFound (phpredis hash commands are absent from the analysis stub) */
            $rows = $this->redis->hGetAll(self::KEY);
        } catch (\Throwable $e) {
            $this->logger?->warning('Dealer gamma bulk read failed: ' . $e->getMessage());

            return [];
        }

        if (!is_array($rows)) {
            return [];
        }

        $state = [];

        foreach ($rows as $ticker => $raw) {
            $entry = $this->decode($raw);

            if ($entry !== null) {
                $state[(string) $ticker] = $entry;
            }
        }

        return $state;
    }

    public function recordAll(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $payload = [];

        foreach ($entries as $ticker => $entry) {
            $payload[$ticker] = json_encode(self::fields($entry), JSON_THROW_ON_ERROR);
        }

        try {
            /** @phpstan-ignore method.notFound (phpredis hash commands are absent from the analysis stub) */
            $this->redis->hMSet(self::KEY, $payload);
        } catch (\Throwable $e) {
            $this->logger?->warning('Dealer gamma bulk record failed: ' . $e->getMessage());
        }
    }

    /**
     * @param array{gamma: float, delta: float, reference_price: float, held: float} $entry
     * @return array{gamma: float, delta: float, reference_price: float, held: float}
     */
    private static function fields(array $entry): array
    {
        return ['gamma' => $entry['gamma'], 'delta' => $entry['delta'], 'reference_price' => $entry['reference_price'], 'held' => $entry['held']];
    }

    /**
     * An entry written before the desk kept its inventory carries no delta or holding; it reads as none, and the
     * chain's next walk takes the book as hedged.
     *
     * @return array{gamma: float, delta: float, reference_price: float, held: float}|null
     */
    private function decode(mixed $raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || !isset($decoded['gamma'], $decoded['delta'], $decoded['reference_price'], $decoded['held'])) {
            return null;
        }

        return [
            'gamma' => (float) $decoded['gamma'],
            'delta' => (float) $decoded['delta'],
            'reference_price' => (float) $decoded['reference_price'],
            'held' => (float) $decoded['held'],
        ];
    }
}
