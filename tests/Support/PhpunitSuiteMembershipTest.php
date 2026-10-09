<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Suite membership in phpunit.dist.xml is explicit, so a test in an unlisted directory never runs, and the Unit file
 * list has to be mirrored as Integration excludes by hand. This resolves each suite the way PHPUnit does (directories,
 * files, excludes) and holds the two rules that drift: every test belongs to a suite, and no test runs in both Unit
 * and Integration.
 */
final class PhpunitSuiteMembershipTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testEveryTestFileBelongsToASuite(): void
    {
        $listed = array_merge(...array_values($this->suites()));
        $orphans = array_diff($this->testFilesUnder('tests'), $listed);

        $this->assertSame([], array_values($orphans), 'Add these to a <testsuite> in phpunit.dist.xml or they never run.');
    }

    public function testUnitAndIntegrationDoNotOverlap(): void
    {
        $suites = $this->suites();
        $both = array_intersect($suites['Unit'], $suites['Integration']);

        $this->assertSame([], array_values($both), 'A Unit <file> needs a matching Integration <exclude>.');
    }

    /** @return array<string, list<string>> suite name => test files relative to the project root */
    private function suites(): array
    {
        $xml = simplexml_load_file(self::ROOT . '/phpunit.dist.xml');
        $this->assertNotFalse($xml);

        $suites = [];
        foreach ($xml->testsuites->testsuite as $suite) {
            $files = [];
            foreach ($suite->directory as $directory) {
                $files = array_merge($files, $this->testFilesUnder((string) $directory));
            }
            foreach ($suite->file as $file) {
                $files[] = (string) $file;
            }
            foreach ($suite->exclude as $exclude) {
                $prefix = (string) $exclude;
                $files = array_filter($files, static fn (string $f): bool => $f !== $prefix && !str_starts_with($f, $prefix . '/'));
            }
            $suites[(string) $suite['name']] = array_values(array_unique($files));
        }

        return $suites;
    }

    /** @return list<string> */
    private function testFilesUnder(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), 'Test.php')) {
                $files[] = $directory . substr($file->getPathname(), strlen(self::ROOT . '/' . $directory));
            }
        }
        sort($files);

        return $files;
    }
}
