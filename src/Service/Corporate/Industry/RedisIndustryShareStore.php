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
    private const REGIME_KEY_PREFIX = 'industry_regime:';
    /** One hash: a field per industry outlook, and META_FIELD for the run's salt and last quarter stepped. */
    private const OUTLOOK_KEY = 'industry_outlook';
    private const META_FIELD = '_meta';

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
                'anchor_outlook'        => (float) ($decoded['anchor_outlook'] ?? 0.0),
                'addressable_share'     => (float) ($decoded['addressable_share'] ?? 0.0),
                'tick'          => (int) ($decoded['tick'] ?? 0),
                'consumed_tick' => (int) ($decoded['consumed_tick'] ?? 0),
            ] + (isset($decoded['segment_outlook_gap']) ? ['segment_outlook_gap' => (float) $decoded['segment_outlook_gap']] : []);
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

    public function readRegimes(string $industry): array
    {
        try {
            /** @phpstan-ignore method.notFound (phpredis hash commands are absent from the analysis stub) */
            $raw = $this->redis->hGetAll(self::REGIME_KEY_PREFIX . $industry);
        } catch (\Throwable $e) {
            $this->logger?->warning('Industry regime read failed: ' . $e->getMessage());
            return [];
        }

        if (!is_array($raw)) {
            return [];
        }

        $regimes = [];
        foreach ($raw as $key => $json) {
            $decoded = json_decode((string) $json, true);
            if (!is_array($decoded)) {
                continue;
            }
            $regimes[(string) $key] = [
                'start_tick' => (int) ($decoded['start_tick'] ?? 0),
                'end_tick'   => (int) ($decoded['end_tick'] ?? 0),
            ];
        }

        return $regimes;
    }

    public function writeRegime(string $industry, string $key, array $record): void
    {
        try {
            /** @phpstan-ignore method.notFound (phpredis hash commands are absent from the analysis stub) */
            $this->redis->hSet(self::REGIME_KEY_PREFIX . $industry, $key, json_encode($record, JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            $this->logger?->warning('Industry regime write failed: ' . $e->getMessage());
        }
    }

    public function readOutlooks(): ?array
    {
        try {
            /** @phpstan-ignore method.notFound (phpredis hash commands are absent from the analysis stub) */
            $raw = $this->redis->hGetAll(self::OUTLOOK_KEY);
        } catch (\Throwable $e) {
            $this->logger?->warning('Industry outlook read failed: ' . $e->getMessage());
            return null;
        }
        if (!is_array($raw) || !isset($raw[self::META_FIELD])) {
            return null;
        }

        $meta = json_decode((string) $raw[self::META_FIELD], true);
        $industries = [];
        foreach ($raw as $industry => $json) {
            if ($industry === self::META_FIELD) {
                continue;
            }
            $record = self::decodeOutlook((string) $json);
            if ($record !== null) {
                $industries[(string) $industry] = $record;
            }
        }

        return [
            'salt' => (int) (is_array($meta) ? ($meta['salt'] ?? 0) : 0),
            'quarter' => (int) (is_array($meta) ? ($meta['quarter'] ?? -1) : -1),
            'industries' => $industries,
        ];
    }

    public function readOutlook(string $industry): ?array
    {
        try {
            /** @phpstan-ignore method.notFound (phpredis hash commands are absent from the analysis stub) */
            $json = $this->redis->hGet(self::OUTLOOK_KEY, $industry);
        } catch (\Throwable $e) {
            $this->logger?->warning('Industry outlook read failed: ' . $e->getMessage());
            return null;
        }

        return is_string($json) ? self::decodeOutlook($json) : null;
    }

    public function writeOutlooks(array $state): void
    {
        $fields = [self::META_FIELD => json_encode(['salt' => $state['salt'], 'quarter' => $state['quarter']], JSON_THROW_ON_ERROR)];
        foreach ($state['industries'] as $industry => $record) {
            $fields[$industry] = json_encode($record, JSON_THROW_ON_ERROR);
        }
        try {
            /** @phpstan-ignore method.notFound (phpredis hash commands are absent from the analysis stub) */
            $this->redis->hMSet(self::OUTLOOK_KEY, $fields);
        } catch (\Throwable $e) {
            $this->logger?->warning('Industry outlook write failed: ' . $e->getMessage());
        }
    }

    /** @return array{x: float, m: float, v: float, f: float, w: float, prior_belief: float, revised_tick: int}|null */
    private static function decodeOutlook(string $json): ?array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }

        return [
            'x' => (float) ($decoded['x'] ?? 0.0),
            'm' => (float) ($decoded['m'] ?? 0.0),
            'v' => (float) ($decoded['v'] ?? 0.0),
            'f' => (float) ($decoded['f'] ?? 0.0),
            'w' => (float) ($decoded['w'] ?? 0.0),
            'prior_belief' => (float) ($decoded['prior_belief'] ?? 0.0),
            'revised_tick' => (int) ($decoded['revised_tick'] ?? -1),
        ];
    }
}
