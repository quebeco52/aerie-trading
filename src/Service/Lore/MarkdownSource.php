<?php

declare(strict_types=1);

namespace App\Service\Lore;

use League\CommonMark\CommonMarkConverter;
use Symfony\Component\Yaml\Yaml;

/**
 * A lore file written in Markdown (an Almanac entry, a company profile): a front-matter block between --- lines, then
 * the body, which links Almanac entries with [[slug]] or [[slug|text]].
 */
final class MarkdownSource
{
    /** A wiki link in a body: [[slug]] or [[slug|text]]. */
    private const LINK_PATTERN = '/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/';

    /**
     * A file's front matter and its body.
     *
     * @param string $name How an error names the file ("Almanac entry 'security-bureau'").
     * @return array{0: array<mixed>, 1: string}
     */
    public static function split(string $name, string $source): array
    {
        if (!preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $source, $parts)) {
            throw new \InvalidArgumentException("{$name} must open with a front-matter block between --- lines.");
        }
        $front = Yaml::parse($parts[1]);
        if (!is_array($front)) {
            throw new \InvalidArgumentException("{$name}: the front matter is not a map.");
        }

        return [$front, trim($parts[2])];
    }

    /**
     * A front-matter field that must be written out.
     *
     * @param array<mixed> $front
     */
    public static function text(array $front, string $key, string $name): string
    {
        $value = $front[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("{$name} needs a '{$key}'.");
        }

        return trim($value);
    }

    /**
     * A front-matter list of names (tickers, institution ids).
     *
     * @param array<mixed> $front
     * @return list<string>
     */
    public static function names(array $front, string $key, string $name): array
    {
        $names = $front[$key] ?? [];
        if (!is_array($names)) {
            throw new \InvalidArgumentException("{$name}: '{$key}' must be a list.");
        }

        return array_values(array_map(static fn(mixed $item): string => is_scalar($item) ? (string) $item : '', $names));
    }

    /**
     * A body as HTML. A [[slug]] link to an Almanac entry prints its title unless given its own words; one to an entry
     * that does not exist prints as plain text, and the tests catch it.
     *
     * @param array<string, string> $titles Almanac entry titles by slug.
     * @return array{html: string, links: list<string>} The HTML and the slugs it links to.
     */
    public static function render(string $markdown, array $titles): array
    {
        $links = [];
        $markdown = (string) preg_replace_callback(self::LINK_PATTERN, static function (array $match) use ($titles, &$links): string {
            $target = trim($match[1]);
            $text = trim($match[2] ?? '') !== '' ? trim($match[2]) : ($titles[$target] ?? $target);
            $links[] = $target;

            return isset($titles[$target]) ? "[{$text}](/almanac/{$target})" : $text;
        }, $markdown);
        $converter = new CommonMarkConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);

        return ['html' => $converter->convert($markdown)->getContent(), 'links' => array_values(array_unique($links))];
    }
}
