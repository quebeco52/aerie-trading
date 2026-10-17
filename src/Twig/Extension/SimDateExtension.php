<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use App\Data\District\DistrictCalendar;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Renders a simulation time as the District's own calendar ("Year 13 Q2"), the form the government
 * page dates elections and budgets in. Pages use it in place of a raw fractional year.
 */
class SimDateExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('sim_date', [DistrictCalendar::class, 'quarter']),
        ];
    }
}
