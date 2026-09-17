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
     * The other half of the same rule: a subsystem's own constant must not be one another file needs, or the
     * reader is reaching across a boundary for a parameter that is supposed to be private to an equation.
     *
     * A subsystem's own test is not another file for this purpose -- a dial the test asserts on is still
     * that subsystem's dial, and naming it there is the point.
     */
    public function testASubsystemsOwnConstantIsNotReadFromOutsideIt(): void
    {
        $leaked = [];
        foreach ($this->subsystemSources() as $subsystem => $_) {
            $ownTest = sprintf('%sTest.php', $subsystem);
            /** @var class-string $class */
            $class = 'App\\Service\\Macro\\Subsystem\\' . $subsystem;
            foreach (array_keys((new \ReflectionClass($class))->getConstants()) as $name) {
                foreach ($this->otherSources() as $path) {
                    if (basename($path) === $ownTest) {
                        continue;
                    }
                    if (preg_match('/' . $subsystem . '::' . $name . '\b/', (string) file_get_contents($path)) === 1) {
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
}
