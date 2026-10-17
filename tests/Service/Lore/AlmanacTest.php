<?php

declare(strict_types=1);

namespace App\Tests\Service\Lore;

use App\Data\Company\StockInfo;
use App\Data\District\Institutions;
use App\Service\Lore\Almanac;
use App\Service\Lore\AlmanacEntry;
use App\Tests\Data\Company\StockInfoTest;
use App\Twig\Extension\AlmanacExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Error\RuntimeError;

class AlmanacTest extends TestCase
{
    /** Real bodies and places the fiction never names: the US is "the mainland" (.agents/FRONTEND.md). */
    private const REAL_WORLD = '/\b(FBI|CIA|NSA|SEC|Fed|Federal Reserve|Treasury|NYSE|Nasdaq|S&P|United States|America|American|U\.S\.|USA|Washington|Wall Street|London|Singapore|Switzerland|Hong Kong)\b/';

    private static function almanac(): Almanac
    {
        return new Almanac(dirname(__DIR__, 3));
    }

    /** @return iterable<string, array{AlmanacEntry}> */
    public static function entries(): iterable
    {
        foreach (self::almanac()->all() as $slug => $entry) {
            yield $slug => [$entry];
        }
    }

    /**
     * Every file in content/almanac/ is published, the README and the underscored drafts aside, under a slug its
     * address can carry.
     */
    public function testEveryFileIsAnEntry(): void
    {
        $files = array_map(static fn(string $path): string => basename($path, '.md'), glob(dirname(__DIR__, 3) . '/' . Almanac::DIRECTORY . '/*.md') ?: []);
        $published = array_values(array_filter($files, static fn(string $slug): bool => $slug !== 'README' && !str_starts_with($slug, '_')));

        $this->assertNotEmpty($published);
        $this->assertEqualsCanonicalizing($published, array_keys(self::almanac()->all()));
        foreach ($published as $slug) {
            $this->assertMatchesRegularExpression(Almanac::SLUG_PATTERN, $slug, "content/almanac/{$slug}.md: name the file with lower-case words and hyphens.");
        }
    }

    #[DataProvider('entries')]
    public function testLinksAndSubjectsResolve(AlmanacEntry $entry): void
    {
        $all = self::almanac()->all();
        foreach ($entry->links as $target) {
            $this->assertArrayHasKey($target, $all, "{$entry->slug}: [[{$target}]] links to no entry.");
        }
        foreach ($entry->about as $subject) {
            $this->assertTrue(isset(Institutions::PUBLISHERS[$subject]) || isset(StockInfo::DESCRIPTIONS[$subject]), "{$entry->slug}: '{$subject}' in 'about' is neither an Institutions::PUBLISHERS id nor a ticker.");
        }
        $this->assertStringNotContainsString('[[', $entry->html, "{$entry->slug}: a wiki link was left unrendered.");
    }

    #[DataProvider('entries')]
    public function testEntryReadsAsTheAlmanac(AlmanacEntry $entry): void
    {
        $text = $entry->title . ' ' . $entry->summary . ' ' . $entry->markdown;

        $this->assertDoesNotMatchRegularExpression(StockInfoTest::AI_TELLS, $text, "{$entry->slug}: marketing words and stock constructions.");
        $this->assertStringNotContainsString('—', $text, "{$entry->slug}: no em dashes.");
        $this->assertDoesNotMatchRegularExpression(self::REAL_WORLD, $text, "{$entry->slug}: the fiction names no real agency, country or market.");
        $this->assertDoesNotMatchRegularExpression('/\bYear\s+-?\d+\b/i', $text, "{$entry->slug}: the Almanac dates nothing against the game's calendar.");
    }

    /**
     * A link renders as the target's title, or the words given; a page links an entry through almanac_url(), which
     * refuses one that does not exist.
     */
    public function testLinksRenderAndPagesCannotLinkNowhere(): void
    {
        $affair = self::almanac()->get('drainrock-affair');
        $this->assertNotNull($affair);
        $this->assertStringContainsString('<a href="/almanac/security-bureau">Security Bureau</a>', $affair->html);
        $this->assertSame([], array_values(array_filter(self::almanac()->about('security-bureau'), static fn(AlmanacEntry $entry): bool => !in_array('security-bureau', $entry->about, true))));

        $extension = new AlmanacExtension(self::almanac());
        $this->assertSame('/almanac/security-bureau', $extension->url('security-bureau'));
        $this->expectException(RuntimeError::class);
        $extension->url('no-such-entry');
    }

    /** An entry needs a sequence to be ordered by. */
    public function testAnEntryWithoutASequenceIsRefused(): void
    {
        $dir = sys_get_temp_dir() . '/almanac-' . bin2hex(random_bytes(4));
        mkdir($dir . '/' . Almanac::DIRECTORY, 0777, true);
        file_put_contents($dir . '/' . Almanac::DIRECTORY . '/late.md', "---\ntitle: Late\nsummary: Too late.\ncategory: History\n---\nBody.\n");

        try {
            $this->expectException(\InvalidArgumentException::class);
            (new Almanac($dir))->all();
        } finally {
            unlink($dir . '/' . Almanac::DIRECTORY . '/late.md');
        }
    }
}
