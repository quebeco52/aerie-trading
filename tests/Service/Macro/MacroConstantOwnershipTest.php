<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro;

use App\Service\Macro\MacroEngine;
use PHPUnit\Framework\TestCase;

/**
 * Where a macro parameter lives is a statement about who it belongs to.
 *
 * MacroEngine keeps the shared vocabulary -- the inflation target, the natural rate, the baselines that
 * sector models, DTOs and tests all read off it. A parameter only ONE subsystem has ever read is not shared
 * vocabulary; it is that subsystem's own dial, and leaving it on the engine both hides it from the equation
 * it belongs to and grows a file that was 1,221 lines of which 856 were constants.
 *
 * The split is only worth having if it holds, and it decays the moment someone adds a constant to the engine
 * out of habit. So it is checked rather than documented.
 */
class MacroConstantOwnershipTest extends TestCase
{
    private const SUBSYSTEM_DIR = __DIR__ . '/../../../src/Service/Macro/Subsystem';

    private const ENGINE_FILE = __DIR__ . '/../../../src/Service/Macro/MacroEngine.php';

    /**
     * @return array<string, string> Subsystem short class name => file contents.
     */
    private function subsystemSources(): array
    {
        $sources = [];
        foreach (glob(self::SUBSYSTEM_DIR . '/*.php') ?: [] as $path) {
            $sources[basename($path, '.php')] = (string) file_get_contents($path);
        }

        return $sources;
    }

    /**
     * Every file that could name a macro constant, outside the subsystems themselves.
     *
     * @return list<string>
     */
    private function otherSources(): array
    {
        $roots = [__DIR__ . '/../../../src', __DIR__ . '/../../../tests'];
        $out = [];
        foreach ($roots as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $path = $file->getPathname();
                if (str_contains($path, '/Macro/Subsystem/')) {
                    continue;
                }
                $out[] = $path;
            }
        }

        return $out;
    }

    public function testAConstantOnlyOneSubsystemReadsBelongsToThatSubsystem(): void
    {
        $engineSource = (string) file_get_contents(self::ENGINE_FILE);
        $subsystems = $this->subsystemSources();

        // What the engine reads of itself is shared by definition: the orchestrator is a caller too.
        $ownUse = [];
        preg_match_all('/\bself::([A-Z0-9_]+)\b/', $engineSource, $matches);
        foreach ($matches[1] as $name) {
            $ownUse[$name] = true;
        }

        $elsewhere = [];
        foreach ($this->otherSources() as $path) {
            preg_match_all('/MacroEngine::([A-Z0-9_]+)\b/', (string) file_get_contents($path), $matches);
            foreach ($matches[1] as $name) {
                $elsewhere[$name] = true;
            }
        }

        $misplaced = [];
        foreach (array_keys((new \ReflectionClass(MacroEngine::class))->getConstants()) as $name) {
            if (isset($ownUse[$name]) || isset($elsewhere[$name])) {
                continue;
            }

            $readers = [];
            foreach ($subsystems as $subsystem => $source) {
                if (preg_match('/MacroEngine::' . $name . '\b/', $source) === 1) {
                    $readers[] = $subsystem;
                }
            }

            if (count($readers) === 1) {
                $misplaced[] = sprintf('%s is read only by %s', $name, $readers[0]);
            }
        }

        $this->assertSame(
            [],
            $misplaced,
            "These belong to the subsystem that reads them, not to MacroEngine:\n  " . implode("\n  ", $misplaced)
        );
    }

    /**
     * The other half of the same rule: a subsystem's own constant must not be one other model code needs, or the
     * reader is reaching across a boundary for a parameter that is supposed to be private to an equation. Model
     * code is the engine itself, the business models, the corporate, market and maths services: anything that
     * computes the economy. A parameter two of them share belongs on MacroEngine, or with the formula in Math.
     *
     * The layers that only describe the economy may read what a subsystem publishes: the pages explaining a rule
     * to players, the DTOs opening a field at its founding value, the government handing laws over, the news, and
     * the tests pinning a cited figure. Reading the subsystem that owns a number is the honest dependency for them.
     * A name in a comment is a cross-reference, not a read.
     */
    public function testASubsystemsOwnConstantIsNotReadFromOutsideIt(): void
    {
        $subsystemNames = array_keys($this->subsystemSources());

        $leaked = [];
        foreach ($subsystemNames as $subsystem) {
            /** @var class-string $class */
            $class = 'App\\Service\\Macro\\Subsystem\\' . $subsystem;
            foreach (array_keys((new \ReflectionClass($class))->getConstants()) as $name) {
                foreach ($this->modelSources() as $path => $source) {
                    if (preg_match('/' . $subsystem . '::' . $name . '\b/', $source) === 1) {
                        $leaked[] = sprintf('%s::%s is read by %s', $subsystem, $name, basename($path));
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $leaked,
            "Shared parameters belong on MacroEngine:\n  " . implode("\n  ", $leaked)
        );
    }

    /**
     * The model code outside the subsystems, comments stripped: every file under src/ but the layers that only
     * describe the economy.
     *
     * @return array<string, string> Path => source without comments.
     */
    private function modelSources(): array
    {
        $describing = ['/src/Controller/', '/src/DTO/', '/src/Data/', '/src/Entity/', '/src/Repository/', '/src/Twig/', '/src/Command/', '/src/Service/View/', '/src/Service/Politics/', '/src/Service/News/'];
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../../src'));
        foreach ($it as $file) {
            $path = $file->getPathname();
            if ($file->getExtension() !== 'php' || str_contains($path, '/Macro/Subsystem/')) {
                continue;
            }
            foreach ($describing as $layer) {
                if (str_contains($path, $layer)) {
                    continue 2;
                }
            }
            $code = '';
            foreach (token_get_all((string) file_get_contents($path)) as $token) {
                if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $code .= is_array($token) ? $token[1] : $token;
                }
            }
            $out[$path] = $code;
        }

        return $out;
    }
}
