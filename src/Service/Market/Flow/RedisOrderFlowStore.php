<?php

declare(strict_types=1);

namespace App\Service\Market\Flow;

use Psr\Log\LoggerInterface;

/**
 * One Redis hash, field per ticker, value the running signed share total.
 *
 * Failures degrade to "no flow was seen": an order-flow outage must never abort a tick, and the honest
 * fallback is a price that moves on its diffusion alone. The alternative — retrying, or holding the tick —
 * would stop the whole market because one auxiliary counter was unavailable.
 *
 * The drain is a MULTI so that a fill landing between the read and the delete cannot be silently dropped;
 * it lands in the next window instead.
 */
final class RedisOrderFlowStore implements OrderFlowStoreInterface
{
    private const KEY = 'order_flow';

    public function __construct(
        private readonly \Redis $redis,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function record(string $ticker, float $signedQuantity): void
    {
        if ($signedQuantity === 0.0) {
            return;
        }

        try {
            /** @phpstan-ignore method.notFound (phpredis hash commands are absent from the analysis stub) */
            $this->redis->hIncrByFloat(self::KEY, $ticker, $signedQuantity);
        } catch (\Throwable $e) {
            $this->logger?->warning('Order flow record failed: ' . $e->getMessage());
        }
    }

    public function drain(): array
    {
        try {
            $pipeline = $this->redis->multi(\Redis::MULTI);
            /** @phpstan-ignore method.notFound (phpredis hash commands are absent from the analysis stub) */
            $pipeline->hGetAll(self::KEY);
            $pipeline->del(self::KEY);
            $result = $pipeline->exec();
        } catch (\Throwable $e) {
            $this->logger?->warning('Order flow drain failed: ' . $e->getMessage());

            return [];
        }

        $raw = is_array($result) ? ($result[0] ?? null) : null;
        if (!is_array($raw)) {
            return [];
        }

        $flow = [];
        foreach ($raw as $ticker => $quantity) {
            $signed = (float) $quantity;
            if ($signed !== 0.0) {
                $flow[(string) $ticker] = $signed;
            }
        }

        return $flow;
    }
}
