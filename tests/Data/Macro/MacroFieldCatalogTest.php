<?php

declare(strict_types=1);

namespace App\Tests\Data\Macro;

use App\Data\Macro\MacroFieldCatalog;
use App\DTO\MacroStateDTO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pins the catalogue that turns a driver's macro provenance into a printable reading.
 *
 * The point of the catalogue is that a variable reads identically wherever it appears — in a
 * revenue-stream driver, on a district institution's readout, on the macro dashboard — so the two
 * things that must hold are that every catalogued field actually exists on MacroStateDTO, and
 * that a reading is only ever produced for a field the snapshot really carries.
 */
#[Group('unit')]
class MacroFieldCatalogTest extends TestCase
{
    public function testEveryCataloguedFieldExistsOnTheMacroSnapshot(): void
    {
        $snapshot = (new MacroStateDTO())->toArray();

        foreach (array_keys(MacroFieldCatalog::FIELDS) as $field) {
            $this->assertArrayHasKey(
                $field,
                $snapshot,
                "MacroFieldCatalog names '{$field}', which MacroStateDTO::toArray() does not publish.",
            );
        }
    }

    public function testEveryCataloguedFieldDeclaresALabelAndAKnownUnit(): void
    {
        $units = [MacroFieldCatalog::UNIT_PERCENT, MacroFieldCatalog::UNIT_BPS, MacroFieldCatalog::UNIT_INDEX, MacroFieldCatalog::UNIT_LEVEL];

        foreach (MacroFieldCatalog::FIELDS as $field => $meta) {
            $this->assertNotSame('', $meta['label'], "Field '{$field}' has no label.");
            $this->assertContains($meta['unit'], $units, "Field '{$field}' declares an unknown unit.");
        }
    }

    /**
     * A stream driver is named for the input it was measured on, so every input a model declares must be catalogued.
     */
    public function testEveryInputAModelReadsIsCatalogued(): void
    {
        foreach (array_keys(\App\Data\Company\Sectors::BUSINESS_MODELS) as $identifier) {
            foreach (\App\Data\Company\Sectors::getBusinessModelStrategy($identifier)->getOperatingMacroFields() as $field) {
                if (in_array($field, \App\Service\Corporate\MacroDriverAttribution::TREND_FIELDS, true)) {
                    continue;
                }
                $this->assertArrayHasKey($field, MacroFieldCatalog::FIELDS, "{$identifier} reads '{$field}', which the catalog cannot name.");
            }
        }
    }

    public function testResolvesAReadingFromASnapshot(): void
    {
        $reading = MacroFieldCatalog::readingFor('output_gap_ema', ['output_gap_ema' => -0.0081234]);

        $this->assertNotNull($reading);
        $this->assertSame('output_gap_ema', $reading['field']);
        $this->assertSame('Output gap', $reading['label']);
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

    /**
     * Labels are sentence case, as every player page is (.agents/FRONTEND.md): past the first word only acronyms
     * (CPI, GDP, 10Y) and the Reserve Fund's own name keep a capital.
     */
    public function testLabelsAreSentenceCase(): void
    {
        foreach (MacroFieldCatalog::FIELDS as $field => $meta) {
            $words = preg_split('/[\s\-\/(]+/', $meta['label'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach (array_slice($words, 1) as $word) {
                $core = (string) preg_replace('/[^A-Za-z0-9]/', '', $word);
                $isAcronym = strlen($core) > 1 && (strtoupper($core) === $core);
                $this->assertTrue(
                    $core === '' || !ctype_upper($core[0]) || $isAcronym || in_array($core, ['Reserve', 'Fund'], true),
                    "'{$meta['label']}' ({$field}) is not sentence case at '{$word}'.",
                );
            }
        }
    }

    /** A filing keeps the label it was struck with; read back, a catalogued driver takes today's name. */
    public function testStoredDriversAreReadBackWithTodaysNames(): void
    {
        $drivers = MacroFieldCatalog::relabelDrivers([
            ['label' => 'Output Gap (15M Lag)', 'type' => 'macro', 'impact' => -0.003, 'readings' => [['field' => 'output_gap_lag_15m', 'label' => 'Output Gap (15M Lag)', 'unit' => 'pct', 'value' => -0.005]]],
            ['label' => 'Strong Operational Execution', 'type' => 'momentum', 'z' => 2.1, 'readings' => []],
        ]);

        $this->assertSame('Output gap (15-month lag)', $drivers[0]['label']);
        $this->assertSame('Output gap (15-month lag)', $drivers[0]['readings'][0]['label']);
        $this->assertSame(-0.005, $drivers[0]['readings'][0]['value']);
        $this->assertSame('Strong operational execution', $drivers[1]['label']);
    }

    /** A net balance below zero reads by its other side, without a double negative; other fields keep the sign. */
    public function testANegativeNetBalanceReadsAsItsOtherSide(): void
    {
        $this->assertSame(['Lending standards (net easing)', 0.144], MacroFieldCatalog::signedReading('sloos_tightening_index_ema', -0.144));
        $this->assertSame(['Lending standards (net tightening)', 0.05], MacroFieldCatalog::signedReading('sloos_tightening_index_ema', 0.05));
        $this->assertSame(['Output gap', -0.012], MacroFieldCatalog::signedReading('output_gap_ema', -0.012));
        foreach (array_keys(MacroFieldCatalog::NEGATIVE_LABELS) as $field) {
            $this->assertArrayHasKey($field, MacroFieldCatalog::FIELDS);
        }
    }
}
