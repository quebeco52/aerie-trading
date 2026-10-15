<?php

declare(strict_types=1);

namespace App\Service\Lore;

use League\CommonMark\CommonMarkConverter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * The District Almanac: the District's reference work, its entries written in Markdown under content/almanac/, one file
 * per entry named by its slug. A file opens with a front-matter block and links other entries with [[slug]] or
 * [[slug|text]]; content/almanac/README.md says how to write one. Files whose names start with an underscore are drafts
 * and templates, and are not published.
 *
 * Every entry is set before the District's records begin, so nothing in the Almanac can contradict what the game has
 * since played. Entries carry no dates: a sequence number orders them, and the text places events against each other.
 */
final class Almanac
{
    // --- Entries ---
    /** Where the entries are kept, from the project root. */
    public const DIRECTORY = 'content/almanac';
    /** The kinds of entry, in the order the Almanac's index lists them. */
    public const CATEGORIES = ['Institution', 'History', 'Incident', 'Place', 'Person'];
    /** A slug: lower-case words joined by hyphens, as the file is named and the address reads. */
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    /** A wiki link in a body: [[slug]] or [[slug|text]]. */
    private const LINK_PATTERN = '/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/';

    /** @var array<string, AlmanacEntry>|null */
    private ?array $entries = null;

    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir) {}

    /**
     * Every published entry by slug, ordered by category and then title.
     *
     * @return array<string, AlmanacEntry>
     */
    public function all(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $sources = [];
        foreach (glob($this->projectDir . '/' . self::DIRECTORY . '/*.md') ?: [] as $path) {
            $slug = basename($path, '.md');
            if ($slug !== 'README' && !str_starts_with($slug, '_')) {
                $sources[$slug] = self::parse($slug, (string) file_get_contents($path));
            }
        }

        // Titles are known before any body is rendered, so a [[slug]] link can print its target's title.
        $titles = array_map(static fn(array $source): string => $source['title'], $sources);
        $converter = new CommonMarkConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $entries = [];
        foreach ($sources as $slug => $source) {
            $links = [];
            $markdown = (string) preg_replace_callback(self::LINK_PATTERN, static function (array $match) use ($titles, &$links): string {
                $target = trim($match[1]);
                $text = trim($match[2] ?? '') !== '' ? trim($match[2]) : ($titles[$target] ?? $target);
                $links[] = $target;

                return isset($titles[$target]) ? "[{$text}](/almanac/{$target})" : $text;
            }, $source['body']);

            $entries[$slug] = new AlmanacEntry(
                slug: $slug,
                title: $source['title'],
                summary: $source['summary'],
                category: $source['category'],
                sequence: $source['sequence'],
                about: $source['about'],
                links: array_values(array_unique($links)),
                markdown: $source['body'],
                html: $converter->convert($markdown)->getContent(),
            );
        }

        uasort($entries, static fn(AlmanacEntry $a, AlmanacEntry $b): int => [array_search($a->category, self::CATEGORIES, true), $a->title] <=> [array_search($b->category, self::CATEGORIES, true), $b->title]);

        return $this->entries = $entries;
    }

    public function get(string $slug): ?AlmanacEntry
    {
        return $this->all()[$slug] ?? null;
    }

    /**
     * The entries about an institution or a listed company, the most recent events first.
     *
     * @param string $subject An Institutions::PUBLISHERS id or a ticker.
     * @return list<AlmanacEntry>
     */
    public function about(string $subject): array
    {
        $entries = array_values(array_filter($this->all(), static fn(AlmanacEntry $entry): bool => in_array($subject, $entry->about, true)));
        usort($entries, static fn(AlmanacEntry $a, AlmanacEntry $b): int => [$b->sequence, $a->title] <=> [$a->sequence, $b->title]);

        return $entries;
    }

    /**
     * An entry's front matter and body off its file.
     *
     * @return array{title: string, summary: string, category: string, sequence: int, about: list<string>, body: string}
     */
    private static function parse(string $slug, string $source): array
    {
        if (!preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $source, $parts)) {
            throw new \InvalidArgumentException("Almanac entry '{$slug}' must open with a front-matter block between --- lines.");
        }
        $front = Yaml::parse($parts[1]);
        if (!is_array($front)) {
            throw new \InvalidArgumentException("Almanac entry '{$slug}': the front matter is not a map.");
        }

        $text = static function (string $key) use ($front, $slug): string {
            $value = $front[$key] ?? null;
            if (!is_string($value) || trim($value) === '') {
                throw new \InvalidArgumentException("Almanac entry '{$slug}' needs a '{$key}'.");
            }

            return trim($value);
        };
        $sequence = $front['sequence'] ?? null;
        if (!is_int($sequence)) {
            throw new \InvalidArgumentException("Almanac entry '{$slug}': 'sequence' must be a whole number, smaller for earlier events.");
        }
        $category = $text('category');
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException("Almanac entry '{$slug}': category '{$category}' is not one of " . implode(', ', self::CATEGORIES) . '.');
        }
        $about = $front['about'] ?? [];
        if (!is_array($about)) {
            throw new \InvalidArgumentException("Almanac entry '{$slug}': 'about' must be a list.");
        }

        return [
            'title' => $text('title'),
            'summary' => $text('summary'),
            'category' => $category,
            'sequence' => $sequence,
            'about' => array_values(array_map(static fn(mixed $subject): string => is_scalar($subject) ? (string) $subject : '', $about)),
            'body' => trim($parts[2]),
        ];
    }
}
