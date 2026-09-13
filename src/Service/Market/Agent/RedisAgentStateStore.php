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
 *
 * Inside a batch the whole hash comes over in one HGETALL and the tick's writes go back in one pipeline.
 * Per-name HGET/HSET pairs were two synchronous round trips for every stock on every tick, which at sixty-odd
 * names was more wall time than the entire tick budget before a single price had been computed.
 */
final class RedisAgentStateStore implements AgentStateStoreInterface
{
    private const KEY = 'agent_state';

    /**
     * The market-wide style record lives in the same hash under a field no ticker can collide with, so a
     * batch still costs one HGETALL and one pipeline: a second key would be a third round trip per tick.
     */
    private const STYLE_FIELD = '__style__';

    private bool $batching = false;

    /** @var array<string, string> Raw JSON per ticker, as loaded when the batch opened. */
    private array $loaded = [];

    /** @var array<string, string> Encoded books waiting for commitBatch(), latest write per ticker. */
    private array $pending = [];

    public function __construct(
        private readonly \Redis $redis,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function read(string $ticker): ?array
    {
        if ($this->batching) {
            // A write earlier in the same batch is the current book, not what the hash held when it opened.
            return $this->decode($this->pending[$ticker] ?? $this->loaded[$ticker] ?? null);
        }

        try {
            /** @phpstan-ignore method.notFound (phpredis hash commands are absent from the analysis stub) */
            $raw = $this->redis->hGet(self::KEY, $ticker);
        } catch (\Throwable $e) {
            $this->logger?->warning('Agent state read failed: ' . $e->getMessage());

            return null;
        }

        return $this->decode($raw);
    }

    /**
     * @param array<string, mixed> $state A book, or the style record under its reserved field.
     */
    public function write(string $ticker, array $state): void
    {
        try {
            $encoded = json_encode($state, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->logger?->warning('Agent state write failed: ' . $e->getMessage());

            return;
        }

        if ($this->batching) {
            $this->pending[$ticker] = $encoded;

            return;
        }

        try {
            /** @phpstan-ignore method.notFound (phpredis hash commands are absent from the analysis stub) */
            $this->redis->hSet(self::KEY, $ticker, $encoded);
        } catch (\Throwable $e) {
            $this->logger?->warning('Agent state write failed: ' . $e->getMessage());
        }
    }

    public function beginBatch(): void
    {
        $this->batching = true;
        $this->pending = [];
        $this->loaded = [];

        try {
            /** @phpstan-ignore method.notFound (phpredis hash commands are absent from the analysis stub) */
            $all = $this->redis->hGetAll(self::KEY);
        } catch (\Throwable $e) {
            $this->logger?->warning('Agent state bulk read failed: ' . $e->getMessage());

            return;
        }

        if (!is_array($all)) {
            return;
        }

        foreach ($all as $ticker => $raw) {
            if (is_string($raw)) {
                $this->loaded[(string) $ticker] = $raw;
            }
        }
    }

    public function commitBatch(): void
    {
        $pending = $this->pending;

        $this->batching = false;
        $this->pending = [];
        $this->loaded = [];

        if ($pending === []) {
            return;
        }

        try {
            $pipeline = $this->redis->multi(\Redis::PIPELINE);

            foreach ($pending as $ticker => $encoded) {
                /** @phpstan-ignore method.notFound (phpredis hash commands are absent from the analysis stub) */
                $pipeline->hSet(self::KEY, $ticker, $encoded);
            }

            $pipeline->exec();
        } catch (\Throwable $e) {
            $this->logger?->warning('Agent state bulk write failed: ' . $e->getMessage());
        }
    }

    public function readStyle(): array
    {
        if ($this->batching) {
            return $this->decodeStyle($this->pending[self::STYLE_FIELD] ?? $this->loaded[self::STYLE_FIELD] ?? null);
        }

        try {
            /** @phpstan-ignore method.notFound (phpredis hash commands are absent from the analysis stub) */
            $raw = $this->redis->hGet(self::KEY, self::STYLE_FIELD);
        } catch (\Throwable $e) {
            $this->logger?->warning('Agent style read failed: ' . $e->getMessage());

            return [];
        }

        return $this->decodeStyle($raw);
    }

    public function writeStyle(array $fitness): void
    {
        // Same path as a book: held inside a batch, sent at once outside one.
        $this->write(self::STYLE_FIELD, $fitness);
    }

    /**
     * @return array<string, float>
     */
    private function decodeStyle(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $style = [];
        foreach ($decoded as $identifier => $score) {
            if (is_int($score) || is_float($score)) {
                $style[(string) $identifier] = (float) $score;
            }
        }

        return $style;
    }

    /**
     * @return array{positions: array<string, float>, fitness: array<string, float>}|null
     */
    private function decode(mixed $raw): ?array
    {
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
        ];
    }
}
