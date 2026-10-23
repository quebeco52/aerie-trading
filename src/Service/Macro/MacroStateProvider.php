<?php

declare(strict_types=1);

namespace App\Service\Macro;

use App\DTO\MacroStateDTO;

/**
 * Reads the live macroeconomic snapshot the ticker publishes to Redis on every tick.
 *
 * The server-rendered pages all need this for their initial paint, before the WebSocket connects and
 * live updates take over.
 *
 * The answer is one for every page: what the payload says, and where it says nothing,
 * MacroStateDTO::fromArray()'s documented openings.
 */
class MacroStateProvider
{
    public function __construct(private readonly MacroStateStore $store) {}

    /**
     * The live snapshot, or the standing openings when the ticker has not published one yet.
     */
    public function liveState(): MacroStateDTO
    {
        return MacroStateDTO::fromArray($this->store->payload());
    }

    /**
     * The snapshot as its raw wire payload, for the admin view that prints it field by field.
     *
     * @return array<string, mixed>
     */
    public function livePayload(): array
    {
        return $this->store->payload();
    }
}
