<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Company logos, served from public/icons/<TICKER>.svg. Not every listed company has one, so the page asks here and
 * prints the generic mark for the rest, instead of requesting a missing file and swapping it in the browser.
 */
final class LogoExtension extends AbstractExtension
{
    // --- Logo files ---
    /** The logos' folder under public/, which is also their path on the site. */
    private const ICON_DIR = 'icons';
    /** The generic mark shown for a company without a logo of its own. */
    private const DEFAULT_ICON = 'default.svg';

    public function __construct(
        private readonly Packages $packages,
        #[Autowire('%kernel.project_dir%/public')]
        private readonly string $publicDir,
    ) {}

    public function getFunctions(): array
    {
        return [new TwigFunction('logo_url', $this->url(...))];
    }

    /** The address of a company's logo, or of the generic mark when it has none. */
    public function url(string $ticker): string
    {
        $own = self::ICON_DIR . '/' . $ticker . '.svg';
        // Letters, digits, dots and dashes only: a ticker with a slash could name a file outside the folder.
        $exists = preg_match('/^[A-Za-z0-9._-]+$/', $ticker) === 1 && is_file($this->publicDir . '/' . $own);

        return $this->packages->getUrl($exists ? $own : self::ICON_DIR . '/' . self::DEFAULT_ICON);
    }
}
