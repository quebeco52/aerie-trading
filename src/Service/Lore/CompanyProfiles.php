<?php

declare(strict_types=1);

namespace App\Service\Lore;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Long-form company profiles, written in-world by Tickbird Research for terminal subscribers, in Markdown under
 * content/profiles/, one file per company named by its ticker; content/profiles/README.md says how to write one. Files
 * whose names start with an underscore are drafts and templates, and are not published.
 *
 * StockInfo::DESCRIPTIONS is the exchange's short business summary; these carry the history, the people and the
 * relationships between firms. Lore sets facts, never the model: every figure an article quotes is pinned to the
 * seed data or StockModelTuning by CompanyProfilesTest. Current executives go unnamed, since the board replaces
 * them during play; founders and past figures may be named.
 */
final class CompanyProfiles
{
    // --- Articles ---
    /** Where the articles are kept, from the project root. */
    public const DIRECTORY = 'content/profiles';
    /** A section opens on a level-two heading; the body opens with one. */
    private const SECTION_PATTERN = '/^## +(.+)$/m';

    /** @var array<string, CompanyProfile>|null */
    private ?array $profiles = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly Almanac $almanac,
    ) {}

    /**
     * Every published profile by ticker.
     *
     * @return array<string, CompanyProfile>
     */
    public function all(): array
    {
        if ($this->profiles !== null) {
            return $this->profiles;
        }

        $profiles = [];
        foreach (glob($this->projectDir . '/' . self::DIRECTORY . '/*.md') ?: [] as $path) {
            $ticker = basename($path, '.md');
            if ($ticker !== 'README' && !str_starts_with($ticker, '_')) {
                $profiles[$ticker] = $this->parse($ticker, (string) file_get_contents($path));
            }
        }
        ksort($profiles);

        return $this->profiles = $profiles;
    }

    public function for(string $ticker): ?CompanyProfile
    {
        return $this->all()[$ticker] ?? null;
    }

    private function parse(string $ticker, string $source): CompanyProfile
    {
        $name = "Profile '{$ticker}'";
        [$front, $body] = MarkdownSource::split($name, $source);
        $parts = preg_split(self::SECTION_PATTERN, $body, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        if (trim(array_shift($parts) ?? '') !== '') {
            throw new \InvalidArgumentException("{$name}: the body opens with a '## ' section heading.");
        }
        $sections = [];
        foreach (array_chunk($parts, 2) as [$heading, $markdown]) {
            $sections[] = ['heading' => trim($heading), 'markdown' => trim($markdown)];
        }
        $rendered = MarkdownSource::render($body, $this->almanac->titles());

        return new CompanyProfile(
            ticker: $ticker,
            headline: MarkdownSource::text($front, 'headline', $name),
            standfirst: MarkdownSource::text($front, 'standfirst', $name),
            related: MarkdownSource::names($front, 'related', $name),
            sections: $sections,
            links: $rendered['links'],
            html: $rendered['html'],
        );
    }
}
