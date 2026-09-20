<?php

declare(strict_types=1);

namespace App\Service\Market;

/**
 * Coalesces ticks into the frames the websocket wire carries.
 *
 * The ticker used to publish every tick: at a 10 ms tick that is a hundred messages a second to every
 * browser tab, each carrying the full quote for every instrument and every macro field, when the page
 * writes the DOM from a 250 ms frame anyway. A frame holds the LATEST quote per instrument, which is all
 * the page reads, and beside it the per-tick POINTS the live chart needs: the chart extends its bar tick
 * by tick so the bar's high and low accumulate the way the server's do, and it advances its clock one
 * tick per point, so thinning the ticks would thin the chart. Events are appended, never replaced.
 */
final class WireFrame
{
    // --- Cadence ---
    /** Frames published per real second. The page coalesces DOM writes to 4 Hz; 10 Hz keeps the live chart tail moving smoothly ahead of that. */
    public const FRAMES_PER_SECOND = 10;

    /** @var array<string, array<string, mixed>> Latest quote per ticker. */
    private array $quotes = [];

    /** @var array<string, list<array{0: int, 1: float, 2: float|null}>> Per-tick [tick, price, volume] per ticker, in tick order. */
    private array $points = [];

    /** @var list<mixed> Every event since the last frame. */
    private array $events = [];

    /** @var array<string, mixed>|null Latest district reconstitution, if one happened in the frame. */
    private ?array $district = null;

    /** @var array<string, mixed> Latest value of every scalar the frame carries beside the quotes. */
    private array $scalars = [];

    private ?int $firstTick = null;

    private int $ticks = 0;

    /** Ticks between frames at a given tick interval; never less than one. */
    public static function intervalTicks(int $tickIntervalUs): int
    {
        return max(1, (int) round(1_000_000 / ($tickIntervalUs * self::FRAMES_PER_SECOND)));
    }

    /** Whether this tick closes a frame. */
    public static function isFrameTick(int $tick, int $tickIntervalUs): bool
    {
        return $tick % self::intervalTicks($tickIntervalUs) === 0;
    }

    /**
     * Folds one tick in.
     *
     * @param list<array<string, mixed>> $updates  The tick's published quotes (stocks, funds, bonds).
     * @param list<mixed>                $events   The tick's events.
     * @param array<string, mixed>|null  $district The tick's district reconstitution, if any.
     * @param array<string, mixed>       $scalars  Frame-level values: market_vol, macro, bond_curve and the like.
     */
    public function absorb(int $tick, array $updates, array $events, ?array $district, array $scalars): void
    {
        $this->firstTick ??= $tick;
        $this->ticks++;

        foreach ($updates as $update) {
            $ticker = (string) $update['ticker'];
            $this->quotes[$ticker] = $update;

            // The chart price is the one the Redis chart buffer keeps: a bond's clean price, so the live
            // tail joins the buffered series without an accrual step at the seam.
            $price = (float) ($update['clean_price'] ?? $update['price']);
            $volume = isset($update['volume']) ? (float) $update['volume'] : null;
            $this->points[$ticker][] = [$tick, $price, $volume];
        }

        foreach ($events as $event) {
            $this->events[] = $event;
        }

        if ($district !== null) {
            $this->district = $district;
        }

        foreach ($scalars as $key => $value) {
            $this->scalars[$key] = $value;
        }
    }

    /** Whether anything has been absorbed since the last flush. */
    public function isEmpty(): bool
    {
        return $this->ticks === 0;
    }

    /**
     * The frame as the wire carries it, and a fresh buffer behind it.
     *
     * @return array<string, mixed>
     */
    public function flush(int $tick, int $timestamp): array
    {
        $stocks = [];
        foreach ($this->quotes as $ticker => $quote) {
            $quote['points'] = $this->points[$ticker];
            $stocks[] = $quote;
        }

        $frame = [
            'timestamp' => $timestamp,
            'tick' => $tick,
            'first_tick' => $this->firstTick ?? $tick,
            'ticks' => $this->ticks,
            'stocks' => $stocks,
            'events' => $this->events,
            'district' => $this->district,
        ] + $this->scalars;

        $this->quotes = [];
        $this->points = [];
        $this->events = [];
        $this->district = null;
        $this->scalars = [];
        $this->firstTick = null;
        $this->ticks = 0;

        return $frame;
    }
}
