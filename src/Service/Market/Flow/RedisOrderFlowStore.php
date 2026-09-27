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
 *
 * Inside a batch, records are netted per ticker in process and sent as one pipeline at the commit. The
 * ticker's own flow — every agent-traded name every tick, every hedged name every bar — was one synchronous
 * HINCRBYFLOAT each, ~130 round trips a tick; a profile put them at ~13 µs apiece, more than any single
 * pricing engine. The web process never opens a batch, so a player's fill still writes through.
 */
final class RedisOrderFlowStore implements OrderFlowStoreInterface
{
    private const KEY = 'order_flow';

    private bool $batching = false;

    /** @var array<string, float> Net shares per ticker recorded inside the open batch and not yet sent. */
    private array $pending = [];

    public function __construct(
        private readonly \Redis $redis,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function record(string $ticker, float $signedQuantity): void
    {
        if ($signedQuantity === 0.0) {
            return;
        }

        if ($this->batching) {
            $this->pending[$ticker] = ($this->pending[$ticker] ?? 0.0) + $signedQuantity;

            return;
        }

        try {
            /** @phpstan-ignore method.notFound (phpredis hash commands are absent from the analysis stub) */
            $this->redis->hIncrByFloat(self::KEY, $ticker, $signedQuantity);
        } catch (\Throwable $e) {
            $this->logger?->warning('Order flow record failed: ' . $e->getMessage());
        }
    }

    public function beginBatch(): void
    {
        // Deliberately does not clear $pending: a batch left open by a tick that threw still holds flow
        // that write-through would already have sent, and the next commit or drain delivers it.
        $this->batching = true;
    }

    public function commitBatch(): void
    {
        $pending = $this->pending;

        $this->batching = false;
        $this->pending = [];

        if ($pending === []) {
            return;
        }

        try {
            $pipeline = $this->redis->multi(\Redis::PIPELINE);

            foreach ($pending as $ticker => $quantity) {
                // Netted to nothing inside the batch; the drain skips a zero field anyway.
                if ($quantity === 0.0) {
                    continue;
                }

                /** @phpstan-ignore method.notFound (phpredis hash commands are absent from the analysis stub) */
                $pipeline->hIncrByFloat(self::KEY, (string) $ticker, $quantity);
            }

            $pipeline->exec();
        } catch (\Throwable $e) {
            $this->logger?->warning('Order flow record failed: ' . $e->getMessage());
        }
    }

    public function drain(): array
    {
        // Unsent batch flow is taken with the hash: batching changes when flow is written, never which tick
        // consumes it. Empty unless a tick threw between beginBatch() and commitBatch().
        $flow = [];
        foreach ($this->pending as $ticker => $quantity) {
            if ($quantity !== 0.0) {
                $flow[(string) $ticker] = $quantity;
            }
        }
        $this->pending = [];

        try {
            $pipeline = $this->redis->multi(\Redis::MULTI);
            /** @phpstan-ignore method.notFound (phpredis hash commands are absent from the analysis stub) */
            $pipeline->hGetAll(self::KEY);
            $pipeline->del(self::KEY);
            $result = $pipeline->exec();
        } catch (\Throwable $e) {
            $this->logger?->warning('Order flow drain failed: ' . $e->getMessage());

            return $flow;
        }

        $raw = is_array($result) ? ($result[0] ?? null) : null;
        if (!is_array($raw)) {
            return $flow;
        }

        foreach ($raw as $ticker => $quantity) {
            $signed = (float) $quantity;
            if ($signed === 0.0) {
                continue;
            }

            $key = (string) $ticker;
            $flow[$key] = ($flow[$key] ?? 0.0) + $signed;
        }

        return array_filter($flow, static fn (float $signed): bool => $signed !== 0.0);
    }
}
