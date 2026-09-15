<?php

declare(strict_types=1);

namespace App\Service\Market\Gamma;

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

    public function record(string $ticker, float $customerGamma, float $referencePrice): void
    {
        try {
            /** @phpstan-ignore method.notFound (phpredis hash commands are absent from the analysis stub) */
            $this->redis->hSet(self::KEY, $ticker, json_encode([
                'gamma' => $customerGamma,
                'reference_price' => $referencePrice,
            ], JSON_THROW_ON_ERROR));
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

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || !isset($decoded['gamma'], $decoded['reference_price'])) {
            return null;
        }

        return ['gamma' => (float) $decoded['gamma'], 'reference_price' => (float) $decoded['reference_price']];
    }
}
