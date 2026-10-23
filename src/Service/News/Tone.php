<?php

declare(strict_types=1);

namespace App\Service\News;

/**
 * How a story reads: good news, bad news, a warning, a notice or routine. The card's badge and icon take their colour
 * from it, and the district map flashes a building in it (district_controller.js flareColor).
 */
enum Tone: string
{
    case Up = 'up';
    case Down = 'down';
    case Warn = 'warn';
    case Accent = 'accent';
    case Neutral = 'neutral';

    /** The badge class (badge-up, badge-down, ...). */
    public function badgeClass(): string
    {
        return 'badge-' . $this->value;
    }

    /** The icon's tinted background and colour. */
    public function iconClass(): string
    {
        return match ($this) {
            self::Up => 'bg-secondary/15 text-secondary',
            self::Down => 'bg-tertiary/15 text-tertiary',
            self::Warn => 'bg-warning/15 text-warning',
            self::Accent => 'bg-primary/15 text-primary',
            self::Neutral => 'bg-surface-container-high text-on-surface-variant',
        };
    }
}
