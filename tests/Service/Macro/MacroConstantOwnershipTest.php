<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro;

use App\Service\Macro\MacroEngine;
use App\Service\Math\MacroConstants;
use PHPUnit\Framework\TestCase;

/**
 * Where a macro parameter lives is a statement about who it belongs to.
 *
 * MacroConstants keeps the shared vocabulary: the inflation target, the natural rate, the baselines that the engine,
 * the sector models, the formulas in Math, the DTOs and the pages all read. A parameter only one model class reads is
 * not shared vocabulary; it is that class's own dial, and leaving it in the shared file hides it from the equation it
 * belongs to. MacroEngine keeps only the dials it alone reads, so nothing has to depend on the engine service for a
 * number.
 *
 * The split is only worth having if it holds, and it decays the moment someone adds a constant out of habit. So it is
 * checked rather than documented.
 */
class MacroConstantOwnershipTest extends TestCase
{
    private const SRC_DIR = __DIR__ . '/../../../src';

    private const TEMPLATE_DIR = __DIR__ . '/../../../templates';

    /** The layers that only describe the economy: reading a parameter there is no reason to own it. */
    private const DESCRIBING_LAYERS = ['/src/Controller/', '/src/DTO/', '/src/Data/', '/src/Entity/', '/src/Repository/', '/src/Twig/', '/src/Command/', '/src/Service/View/', '/src/Service/Politics/', '/src/Service/News/'];

    /**
     * A shared constant one model class reads alone belongs to that class: a subsystem's dial goes on the subsystem, a
     * formula's on its class in Math. Readers are counted in code and templates, not comments or tests, and a constant
     * another shared one is derived from counts as shared.
     */
    public function testASharedConstantHasMoreThanOneModelReaderOrADescribingOne(): void
    {
        $misplaced = [];
        foreach (array_keys((new \ReflectionClass(MacroConstants::class))->getConstants()) as $name) {
            $readers = [];
            foreach ($this->sources() as $path => $code) {
                // A constant another shared one is derived from is shared through it.
                $pattern = str_ends_with($path, '/MacroConstants.php') ? '/self::' . $name . '\b/' : '/MacroConstants::' . $name . '\b/';
                if (preg_match($pattern, $code) === 1) {
                    $readers[] = $path;
                }
            }
            if (count($readers) === 1 && !$this->isDescribing($readers[0])) {
                $misplaced[] = sprintf('%s is read only by %s', $name, basename($readers[0]));
            }
        }

        $this->assertSame(
            [],
            $misplaced,
            "These belong to the class that reads them, not to MacroConstants:\n  " . implode("\n  ", $misplaced),
        );
    }

    /** The engine's own constants are its own: no code outside the macro engine's namespace reads one. */
    public function testNothingOutsideTheEngineReadsItsConstants(): void
    {
        $leaked = [];
        foreach ($this->sources() as $path => $code) {
            if (str_contains($path, '/src/Service/Macro/')) {
                continue;
            }
            preg_match_all('/MacroEngine::([A-Z0-9_]+)\b/', $code, $matches);
            foreach (array_unique($matches[1]) as $name) {
                $leaked[] = sprintf('MacroEngine::%s is read by %s', $name, basename($path));
            }
        }

        $this->assertSame(
            [],
            $leaked,
            "Shared parameters belong on MacroConstants:\n  " . implode("\n  ", $leaked),
        );
    }

    /** Every constant the engine keeps is one it reads itself. */
    public function testTheEngineKeepsOnlyWhatItReads(): void
    {
        $engine = $this->sources()[realpath(self::SRC_DIR . '/Service/Macro/MacroEngine.php')] ?? '';

        $unread = [];
        foreach (array_keys((new \ReflectionClass(MacroEngine::class))->getConstants()) as $name) {
            if (preg_match('/self::' . $name . '\b/', $engine) !== 1) {
                $unread[] = $name;
            }
        }

        $this->assertSame([], $unread, 'MacroEngine declares constants it never reads.');
    }

    /**
     * The other half of the same rule: a subsystem's own constant must not be one other model code needs, or the
     * reader is reaching across a boundary for a parameter that is supposed to be private to an equation. Model code
     * is the engine itself, the business models, the corporate, market and maths services: anything that computes the
     * economy. A parameter two of them share belongs on MacroConstants, or with the formula in Math.
     *
     * The layers that only describe the economy may read what a subsystem publishes: the pages explaining a rule to
     * players, the DTOs opening a field at its founding value, the government handing laws over, the news, and the
     * tests pinning a cited figure. Reading the subsystem that owns a number is the honest dependency for them.
     */
    public function testASubsystemsOwnConstantIsNotReadFromOutsideIt(): void
    {
        $leaked = [];
        foreach (glob(self::SRC_DIR . '/Service/Macro/Subsystem/*.php') ?: [] as $file) {
            $subsystem = basename($file, '.php');
            /** @var class-string $class */
            $class = 'App\\Service\\Macro\\Subsystem\\' . $subsystem;
            foreach (array_keys((new \ReflectionClass($class))->getConstants()) as $name) {
                foreach ($this->sources() as $path => $code) {
                    if (str_contains($path, '/Macro/Subsystem/') || str_ends_with($path, '.twig') || $this->isDescribing($path)) {
                        continue;
                    }
                    if (preg_match('/' . $subsystem . '::' . $name . '\b/', $code) === 1) {
                        $leaked[] = sprintf('%s::%s is read by %s', $subsystem, $name, basename($path));
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $leaked,
            "Shared parameters belong on MacroConstants:\n  " . implode("\n  ", $leaked),
        );
    }

    private function isDescribing(string $path): bool
    {
        if (str_ends_with($path, '.twig')) {
            return true;
        }
        foreach (self::DESCRIBING_LAYERS as $layer) {
            if (str_contains($path, $layer)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every source file under src/ with its comments stripped, and every template: path => code.
     *
     * @return array<string, string>
     */
    private function sources(): array
    {
        static $sources = null;
        if ($sources !== null) {
            return $sources;
        }

        $sources = [];
        foreach ([self::SRC_DIR => 'php', self::TEMPLATE_DIR => 'twig'] as $root => $extension) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator((string) realpath($root), \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== $extension) {
                    continue;
                }
                $contents = (string) file_get_contents($file->getPathname());
                $sources[$file->getPathname()] = $extension === 'php' ? self::withoutComments($contents) : $contents;
            }
        }

        return $sources;
    }

    private static function withoutComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= is_array($token) ? $token[1] : $token;
            }
        }

        return $code;
    }
}
