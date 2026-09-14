<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\DTO\MacroStateDTO;
use PHPUnit\Framework\TestCase;

/**
 * The economy's phase, defined once.
 *
 * Two places name it and they have to agree: the ticker publishes it on every live update and the stock
 * page renders it on load. The page used to read a Redis key (`economy_state`) that nothing has ever
 * written, so it fell back to a hardcoded "Expansion" — a word the live feed does not even use — until
 * the first WebSocket tick replaced it with "Boom", "Bust" or "Neutral".
 */
final class MacroStateCycleLabelTest extends TestCase
{
    private function labelAt(float $outputGap): string
    {
        return (new MacroStateDTO(outputGap: $outputGap))->economicCycleLabel();
    }

    public function testExpansionReadsAsABoom(): void
    {
        $this->assertSame('Boom', $this->labelAt(0.05));
    }

    public function testContractionReadsAsABust(): void
    {
        $this->assertSame('Bust', $this->labelAt(-0.05));
    }

    public function testAGapInsideTheBandIsNeutral(): void
    {
        $this->assertSame('Neutral', $this->labelAt(0.0));
        $this->assertSame('Neutral', $this->labelAt(MacroStateDTO::CYCLE_BOOM_GAP));
        $this->assertSame('Neutral', $this->labelAt(MacroStateDTO::CYCLE_BUST_GAP));
    }

    /** Only the three the interface knows how to render. */
    public function testLabelIsAlwaysOneOfTheKnownStates(): void
    {
        foreach ([-0.5, -0.02, -0.01, 0.0, 0.01, 0.02, 0.5] as $gap) {
            $this->assertContains($this->labelAt($gap), ['Boom', 'Bust', 'Neutral']);
        }
    }

    /** Neither producer keeps a private copy of the thresholds. */
    public function testBothProducersUseTheSharedDefinition(): void
    {
        $root = \dirname(__DIR__, 2);

        // The ticker publishes the label on the wire; the page builder renders it server-side.
        foreach (['/src/Command/MarketTickerCommand.php', '/src/Service/View/StockPageBuilder.php'] as $path) {
            $source = file_get_contents($root . $path);
            $this->assertIsString($source);
            $this->assertStringContainsString(
                'economicCycleLabel()',
                $source,
                "{$path} must name the cycle through the shared definition."
            );
        }

        foreach (['/src/Controller/StockController.php', '/src/Service/View/StockPageBuilder.php'] as $path) {
            $this->assertStringNotContainsString(
                "economy_state",
                (string) file_get_contents($root . $path),
                'The dead Redis key must not come back.'
            );
        }
    }
}
