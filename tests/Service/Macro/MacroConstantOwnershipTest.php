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
     * that subsystem's dial, and naming it there is the point. That covers the test named after the class
     * and any other test written against that one subsystem: a calibration invariant belongs in the suite
     * that runs calibration invariants, not in the class-named unit test, and MacroVolatilityAnchorTest
     * asserting on AssetMarketSubsystem's variance dials is the rule working rather than failing.
     *
     * The exemption is only ever extended to a file under tests/ that names exactly ONE subsystem. A test
     * reaching into a second subsystem is crossing the boundary this guards, and anything in src/ is
     * checked strictly whatever it names.
     */
    public function testASubsystemsOwnConstantIsNotReadFromOutsideIt(): void
    {
        $subsystemNames = array_keys($this->subsystemSources());

        $leaked = [];
        foreach ($subsystemNames as $subsystem) {
            /** @var class-string $class */
            $class = 'App\\Service\\Macro\\Subsystem\\' . $subsystem;
            foreach (array_keys((new \ReflectionClass($class))->getConstants()) as $name) {
                foreach ($this->otherSources() as $path) {
                    $source = (string) file_get_contents($path);

                    if ($this->isDedicatedTestFor($path, $source, $subsystem, $subsystemNames)) {
                        continue;
                    }
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
     * Whether this file is a test written against this one subsystem, and so allowed to name its dials.
     *
     * @param  list<string> $subsystemNames Every subsystem short class name.
     */
    private function isDedicatedTestFor(string $path, string $source, string $subsystem, array $subsystemNames): bool
    {
        if (basename($path) === sprintf('%sTest.php', $subsystem)) {
            return true;
        }

        if (!str_contains($path, '/tests/')) {
            return false;
        }

        // Dedicated means it reaches into this subsystem and no other.
        foreach ($subsystemNames as $other) {
            if ($other === $subsystem) {
                continue;
            }
            if (preg_match('/\b' . $other . '::/', $source) === 1) {
                return false;
            }
        }

        return preg_match('/\b' . $subsystem . '::/', $source) === 1;
    }
}
