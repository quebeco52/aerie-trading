<?php

declare(strict_types=1);

namespace App\Service\Lore;

/** One entry of the District Almanac, read from its Markdown file (App\Service\Lore\Almanac). */
final readonly class AlmanacEntry
{
    /**
     * @param list<string> $about The institutions (Institutions::PUBLISHERS ids) and listed companies (tickers) the
     *                            entry is about.
     * @param list<string> $links The entries its body links to, by slug.
     */
    public function __construct(
        public string $slug,
        public string $title,
        public string $summary,
        public string $category,
        /**
         * Where the entry's events fall in the District's history, for ordering only: smaller is earlier. Never shown.
         */
        public int $sequence,
        public array $about,
        public array $links,
        /** The body as Markdown, wiki links and all, as written. */
        public string $markdown,
        /** The body as HTML, wiki links resolved. */
        public string $html,
    ) {}
}
