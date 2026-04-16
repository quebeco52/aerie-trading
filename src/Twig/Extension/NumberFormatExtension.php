<?php

namespace App\Twig\Extension;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class NumberFormatExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('format_large', [$this, 'formatLargeNumber']),
        ];
    }

    public function formatLargeNumber(float|string $number): string
    {
        $num = (float) $number;
        
        if ($num >= 1_000_000_000_000) {
            return number_format($num / 1_000_000_000_000, 2) . 'T';
        }
        if ($num >= 1_000_000_000) {
            return number_format($num / 1_000_000_000, 2) . 'B';
        }
        if ($num >= 1_000_000) {
            return number_format($num / 1_000_000, 2) . 'M';
        }
        
        return number_format($num, 2);
    }
}