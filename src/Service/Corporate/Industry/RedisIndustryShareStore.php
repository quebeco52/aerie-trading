<?php

declare(strict_types=1);

namespace App\Service\Corporate\Industry;

use Psr\Log\LoggerInterface;

/**
 * Redis hash per industry (field = ticker, value = JSON record). Failures degrade to "no rivals seen":
 * a share ledger outage must never abort an earnings report.
 */
final class RedisIndustryShareStore implements IndustryShareStoreInterface
{
    private const KEY_PREFIX = 'industry_share:';

    public function __construct(
        private readonly \Redis $redis,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function readIndustry(string $industry): array
    {
        try {
            /** @phpstan-ignore method.notFound (phpredis hash commands are absent from the analysis stub) */
            $raw = $this->redis->hGetAll(self::KEY_PREFIX . $industry);
        } catch (\Throwable $e) {
            $this->logger?->warning('Industry share ledger read failed: ' . $e->getMessage());
            return [];
        }

        if (!is_array($raw)) {
            return [];
        }

        $records = [];
        foreach ($raw as $ticker => $json) {
            $decoded = json_decode((string) $json, true);
            if (!is_array($decoded)) {
                continue;
            }
            $records[(string) $ticker] = [
                'revenue'       => (float) ($decoded['revenue'] ?? 0.0),
                'gain'          => (float) ($decoded['gain'] ?? 0.0),
                'capacity'      => (float) ($decoded['capacity'] ?? 0.0),
                'anchor_capacity_share' => (float) ($decoded['anchor_capacity_share'] ?? 0.0),
                'anchor_demand_share'   => (float) ($decoded['anchor_demand_share'] ?? 0.0),
                'anchor_time'           => (float) ($decoded['anchor_time'] ?? 0.0),
                'addressable_share'     => (float) ($decoded['addressable_share'] ?? 0.0),
                'tick'          => (int) ($decoded['tick'] ?? 0),
                'consumed_tick' => (int) ($decoded['consumed_tick'] ?? 0),
            ];
        }

        return $records;
    }

    public function writeRecord(string $industry, string $ticker, array $record): void
    {
        try {
            /** @phpstan-ignore method.notFound (phpredis hash commands are absent from the analysis stub) */
            $this->redis->hSet(self::KEY_PREFIX . $industry, $ticker, json_encode($record, JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            $this->logger?->warning('Industry share ledger write failed: ' . $e->getMessage());
        }
    }
}
