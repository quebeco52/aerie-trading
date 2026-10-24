<?php

declare(strict_types=1);

namespace App\Tests\Meta;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Suite membership in phpunit.dist.xml is explicit, so a test in an unlisted directory never runs. This resolves each
 * suite the way PHPUnit does (directories, files, excludes) and holds the rules that drift: every test belongs to a
 * suite, a Realism file runs nowhere else, and the unit group (make test-unit) holds the collaborator-free directories
 * and no test that boots the kernel.
 */
#[Group('unit')]
final class PhpunitSuiteMembershipTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** Directories whose tests are collaborator-free by construction. */
    private const UNIT_DIRECTORIES = ['tests/DTO', 'tests/Data', 'tests/Twig', 'tests/Service/Model', 'tests/Service/Math', 'tests/Meta'];

    public function testEveryTestFileBelongsToASuite(): void
    {
        $listed = array_merge(...array_values($this->suites()));
        $orphans = array_diff($this->testFilesUnder('tests'), $listed);

        $this->assertSame([], array_values($orphans), 'Add these to a <testsuite> in phpunit.dist.xml or they never run.');
    }

    public function testARealismFileRunsInNoOtherSuite(): void
    {
        $suites = $this->suites();
        $elsewhere = array_merge($suites['Fast'], $suites['Integration'], $suites['Financial']);

        $this->assertSame([], array_values(array_intersect($suites['Realism'], $elsewhere)), 'Exclude these from the other suites.');
    }

    public function testTheCollaboratorFreeDirectoriesAreInTheUnitGroup(): void
    {
        $untagged = [];
        foreach (self::UNIT_DIRECTORIES as $directory) {
            foreach ($this->testFilesUnder($directory) as $file) {
                if (!$this->inUnitGroup($file)) {
                    $untagged[] = $file;
                }
            }
        }

        $this->assertSame([], $untagged, "Add #[Group('unit')] to these.");
    }

    public function testNoUnitTestBootsTheKernel(): void
    {
        $suites = $this->suites();
        $booting = array_filter(
            array_unique(array_merge($suites['Fast'], $suites['Integration'])),
            fn(string $file): bool => $this->inUnitGroup($file) && is_subclass_of(self::className($file), KernelTestCase::class),
        );

        $this->assertSame([], array_values($booting), 'A test that boots the kernel is not a unit test.');
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
                $files = array_filter($files, static fn(string $f): bool => $f !== $prefix && !str_starts_with($f, $prefix . '/'));
            }
            $suites[(string) $suite['name']] = array_values(array_unique($files));
        }

        return $suites;
    }

    private function inUnitGroup(string $file): bool
    {
        foreach ((new \ReflectionClass(self::className($file)))->getAttributes(Group::class) as $attribute) {
            if ($attribute->newInstance()->name() === 'unit') {
                return true;
            }
        }

        return false;
    }

    /** @return class-string */
    private static function className(string $file): string
    {
        /** @var class-string */
        return 'App\\Tests\\' . str_replace('/', '\\', substr($file, strlen('tests/'), -strlen('.php')));
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
