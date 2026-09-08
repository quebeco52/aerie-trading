<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\MacroFieldCatalog;
use App\DTO\MacroStateDTO;
use PHPUnit\Framework\TestCase;

/**
 * Pins the catalogue that turns a driver's macro provenance into a printable reading.
 *
 * The point of the catalogue is that a variable reads identically wherever it appears — in a
 * revenue-stream driver, on a district institution's readout, on the macro dashboard — so the two
 * things that must hold are that every catalogued field actually exists on MacroStateDTO, and
 * that a reading is only ever produced for a field the snapshot really carries.
 */
class MacroFieldCatalogTest extends TestCase
{
    public function testEveryCataloguedFieldExistsOnTheMacroSnapshot(): void
    {
        $snapshot = (new MacroStateDTO())->toArray();

        foreach (array_keys(MacroFieldCatalog::FIELDS) as $field) {
            $this->assertArrayHasKey(
                $field,
                $snapshot,
                "MacroFieldCatalog names '{$field}', which MacroStateDTO::toArray() does not publish."
            );
        }
    }

    public function testEveryCataloguedFieldDeclaresALabelAndAKnownUnit(): void
    {
        $units = [MacroFieldCatalog::UNIT_PERCENT, MacroFieldCatalog::UNIT_BPS, MacroFieldCatalog::UNIT_INDEX];

        foreach (MacroFieldCatalog::FIELDS as $field => $meta) {
            $this->assertNotSame('', $meta['label'], "Field '{$field}' has no label.");
            $this->assertContains($meta['unit'], $units, "Field '{$field}' declares an unknown unit.");
        }
    }

    public function testResolvesAReadingFromASnapshot(): void
    {
        $reading = MacroFieldCatalog::readingFor('output_gap_ema', ['output_gap_ema' => -0.0081234]);

        $this->assertSame('output_gap_ema', $reading['field']);
        $this->assertSame('Output Gap', $reading['label']);
        $this->assertSame(MacroFieldCatalog::UNIT_PERCENT, $reading['unit']);
        $this->assertSame(-0.008123, $reading['value']);
    }

    public function testSpreadsAreCataloguedInBasisPointsAndIndicesAsLevels(): void
    {
        $this->assertSame(MacroFieldCatalog::UNIT_BPS, MacroFieldCatalog::FIELDS['macro_credit_spread_ema']['unit']);
        $this->assertSame(MacroFieldCatalog::UNIT_BPS, MacroFieldCatalog::FIELDS['interbank_liquidity_spread_ema']['unit']);
        $this->assertSame(MacroFieldCatalog::UNIT_INDEX, MacroFieldCatalog::FIELDS['consumer_sentiment_index_ema']['unit']);
    }

    public function testYieldsNothingForAnUncataloguedOrAbsentOrNonNumericField(): void
    {
        $this->assertNull(MacroFieldCatalog::readingFor('not_a_macro_field', ['not_a_macro_field' => 1.0]));
        $this->assertNull(MacroFieldCatalog::readingFor('output_gap_ema', []));
        $this->assertNull(MacroFieldCatalog::readingFor('output_gap_ema', ['output_gap_ema' => null]));
    }
}
