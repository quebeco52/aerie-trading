<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The economy draws from a generator of its own (config/services.yaml app.macro_draws), so on one MACRO_SEED its path
 * is the same however many draws the firms take from the shared stream between ticks: paired runs meet one cycle.
 */
final class MacroDrawStreamTest extends KernelTestCase
{
    private const TICKS = 60;
    private const DT = 1.0 / 360.0;

    private string|false $savedSeed = false;

    protected function setUp(): void
    {
        $this->savedSeed = getenv('MACRO_SEED');
    }

    protected function tearDown(): void
    {
        self::seed($this->savedSeed === false ? null : $this->savedSeed);
        parent::tearDown();
    }

    public function testTheEconomysPathIgnoresWhatTheFirmsDraw(): void
    {
        $quiet = $this->path('7', 0);
        $busy = $this->path('7', 13);

        $this->assertSame($quiet, $busy);
        $this->assertNotSame($quiet, $this->path('8', 0), 'the macro seed drives the path');
    }

    /**
     * The output gap tick by tick, from the container's engine on a fresh stream, with $firmDraws taken from the
     * shared stream before each tick.
     *
     * @return list<float>
     */
    private function path(string $macroSeed, int $firmDraws): array
    {
        self::seed($macroSeed);
        self::ensureKernelShutdown();
        self::bootKernel();
        $engine = self::getContainer()->get(MacroEngine::class);
        $this->assertInstanceOf(MacroEngine::class, $engine);

        mt_srand(1);
        $state = new MacroState();
        $gaps = [];
        for ($tick = 0; $tick < self::TICKS; $tick++) {
            for ($draw = 0; $draw < $firmDraws; $draw++) {
                mt_rand();
            }
            $engine->step($state, self::DT);
            $gaps[] = $state->outputGap;
        }

        return $gaps;
    }

    private static function seed(?string $value): void
    {
        if ($value === null) {
            putenv('MACRO_SEED');
            unset($_ENV['MACRO_SEED'], $_SERVER['MACRO_SEED']);

            return;
        }
        putenv("MACRO_SEED={$value}");
        $_ENV['MACRO_SEED'] = $_SERVER['MACRO_SEED'] = $value;
    }
}
