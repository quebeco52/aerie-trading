<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\DTO\PoliticsStateDTO;

/**
 * Reads the live politics the ticker keeps in Redis, for the pages that render it before the WebSocket connects; the
 * founding Diet when the ticker has kept none yet.
 */
class PoliticsStateProvider
{
    public function __construct(private readonly \Redis $redis) {}

    public function liveState(): PoliticsStateDTO
    {
        $raw = $this->redis->get(PoliticsEngine::REDIS_POLITICS_STATE);
        if (!is_string($raw) || $raw === '') {
            return new PoliticsStateDTO();
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? PoliticsStateDTO::fromArray($decoded) : new PoliticsStateDTO();
    }
}
