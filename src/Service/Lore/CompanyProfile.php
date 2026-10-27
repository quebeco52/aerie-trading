<?php

declare(strict_types=1);

namespace App\Service\Lore;

/**
 * One long-form company profile by Tickbird Research, read from its Markdown file (App\Service\Lore\CompanyProfiles).
 */
final readonly class CompanyProfile
{
    /**
     * @param list<string>                                    $related  Every listed company the text names, by ticker,
     *                                                                  which the page links.
     * @param list<array{heading: string, markdown: string}> $sections The titled sections, each body as written.
     * @param list<string>                                    $links    The Almanac entries the text links to, by slug.
     */
    public function __construct(
        public string $ticker,
        public string $headline,
        /** One or two sentences under the headline. */
        public string $standfirst,
        public array $related,
        public array $sections,
        public array $links,
        /** The body as HTML, Almanac links resolved. */
        public string $html,
    ) {}
}
