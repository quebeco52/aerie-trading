<?php

declare(strict_types=1);

namespace App\Service\Macro;

use App\DTO\MacroStateDTO;

/**
 * Reads the live macroeconomic snapshot the ticker publishes to Redis on every tick.
 *
 * The server-rendered pages all need this for their initial paint, before the WebSocket connects and
 * live updates take over. Each of them used to decode it themselves, and each answered the
 * cold-Redis case differently — one supplied a five-key literal that put the output gap at zero,
 * one an empty array, one a fresh engine state whose openings are different again — so the same
 * ticker could be priced against three different economies depending on which page was open.
 *
 * There is one answer here: what the payload says, and where it says nothing,
 * MacroStateDTO::fromArray()'s documented openings.
 */
class MacroStateProvider
{
    public function __construct(private readonly \Redis $redis) {}

    /**
     * The live snapshot, or the standing openings when the ticker has not published one yet.
     */
    public function liveState(): MacroStateDTO
    {
        return MacroStateDTO::fromArray($this->livePayload());
    }

    /**
     * The snapshot as its raw wire payload, for the admin view that prints it field by field.
     *
     * @return array<string, mixed>
     */
    public function livePayload(): array
    {
        $raw = $this->redis->get(MacroEngine::REDIS_MACRO_STATE);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
