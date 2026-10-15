<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use App\Service\Lore\Almanac;
use Twig\Error\RuntimeError;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Links from a page into the District Almanac, which refuse an entry the Almanac does not have. */
final class AlmanacExtension extends AbstractExtension
{
    public function __construct(private readonly Almanac $almanac) {}

    public function getFunctions(): array
    {
        return [new TwigFunction('almanac_url', $this->url(...))];
    }

    /** The address of an Almanac entry by its slug. */
    public function url(string $slug): string
    {
        if ($this->almanac->get($slug) === null) {
            throw new RuntimeError(sprintf('No Almanac entry "%s"; add content/almanac/%s.md.', $slug, $slug));
        }

        return '/almanac/' . $slug;
    }
}
