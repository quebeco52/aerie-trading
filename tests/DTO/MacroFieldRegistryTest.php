<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\Data\MacroFieldRegistry;
use App\DTO\MacroStateDTO;
use App\Entity\MacroReport;
use App\Service\Macro\MacroState;
use PHPUnit\Framework\TestCase;

/**
 * Guards the macro state vector against the drift that used to be possible between the six places
 * its field list appeared. Each test here fails on one specific way a newly added observable can go
 * missing without any other test noticing.
 */
class MacroFieldRegistryTest extends TestCase
{
    /** Columns on macro_report that the macro vector does not own and must not try to fill. */
    private const RECORDER_OWNED_COLUMNS = ['id', 'recordedAt'];

    /**
     * The engine's mutable state and the immutable snapshot must describe the same vector, because
     * MacroStateDTO::fromMacroState() carries every field across by name.
     */
    public function testMacroStateDeclaresEveryFieldTheDtoDoes(): void
    {
        $fields = array_keys(MacroFieldRegistry::wireKeys());

        $stateProperties = [];
        foreach ((new \ReflectionClass(MacroState::class))->getProperties() as $property) {
            $stateProperties[] = $property->getName();
        }

        $missing = array_diff($fields, $stateProperties);
        $this->assertSame(
            [],
            array_values($missing),
            'MacroStateDTO declares fields MacroState has no property for: ' . implode(', ', $missing)
        );

        $orphaned = array_diff($stateProperties, $fields);
        $this->assertSame(
            [],
            array_values($orphaned),
            'MacroState carries fields the DTO never snapshots: ' . implode(', ', $orphaned)
        );
    }

    /**
     * Every field must survive the Redis wire. A key MacroStateDTO::fromArray() forgets hydrates its
     * default instead of the recorded value, which is silent — the reader still gets a plausible
     * number. Writing a distinct value into every field and reading it back is what catches that.
     */
    public function testEveryFieldSurvivesTheRedisRoundTrip(): void
    {
        $state = new MacroState();
        $sentinels = [];

        $offset = 0;
        foreach ((new \ReflectionClass($state))->getProperties() as $property) {
            ++$offset;
            if ((string) $property->getType() !== 'float') {
                continue;
            }
            // Distinct, and far from every default, so a dropped field cannot coincidentally match.
            $value = 1000.0 + $offset;
            $property->setValue($state, $value);
            $sentinels[$property->getName()] = $value;
        }

        $this->assertNotEmpty($sentinels);

        $restored = MacroStateDTO::fromArray($state->toArray());

        $lost = [];
        foreach ($sentinels as $field => $value) {
            if (abs($restored->$field - $value) > 1e-9) {
                $lost[] = $field;
            }
        }

        $this->assertSame(
            [],
            $lost,
            'Fields did not survive MacroState::toArray() -> MacroStateDTO::fromArray(): ' . implode(', ', $lost)
        );
    }

    /**
     * fromMacroState() carries the vector across by name; a field it skips reaches every consumer as
     * a default.
     */
    public function testFromMacroStateCarriesEveryField(): void
    {
        $state = new MacroState();
        $sentinels = [];

        $offset = 0;
        foreach ((new \ReflectionClass($state))->getProperties() as $property) {
            ++$offset;
            if ((string) $property->getType() !== 'float') {
                continue;
            }
            $value = 500.0 + $offset;
            $property->setValue($state, $value);
            $sentinels[$property->getName()] = $value;
        }

        $dto = MacroStateDTO::fromMacroState($state);

        foreach ($sentinels as $field => $value) {
            $this->assertSame($value, $dto->$field, "fromMacroState() dropped {$field}");
        }
    }

    /**
     * A macro_report column with no matching DTO field can never be filled, so the recorder would
     * write NULL into it on every snapshot for the life of the table.
     */
    public function testEveryPersistedColumnHasAFieldToFillIt(): void
    {
        $fields = MacroFieldRegistry::wireKeys();

        $orphans = [];
        foreach (array_keys(MacroFieldRegistry::entityColumns()) as $property) {
            if (in_array($property, self::RECORDER_OWNED_COLUMNS, true)) {
                continue;
            }
            if (!isset($fields[$property])) {
                $orphans[] = $property;
            }
        }

        $this->assertSame(
            [],
            $orphans,
            'MacroReport columns with no MacroStateDTO field to fill them: ' . implode(', ', $orphans)
        );
    }

    /**
     * The recorder aligns columns to values by position, so the two lists must be the same length and
     * come from the same mapping.
     */
    public function testPersistedColumnsAreCompleteAndUnique(): void
    {
        $columns = MacroFieldRegistry::persistedColumns();

        $this->assertCount(
            count($columns),
            array_unique(array_values($columns)),
            'Two macro fields map to the same macro_report column.'
        );

        $entityColumns = MacroFieldRegistry::entityColumns();
        $this->assertCount(
            count($entityColumns) - count(self::RECORDER_OWNED_COLUMNS),
            $columns,
            'The persisted field list and the mapped entity columns disagree on how many columns the macro vector fills.'
        );
    }

    /**
     * Wire keys reach Redis, the district readouts and the driver catalog by name, so a collision or
     * a stray capital letter would quietly point two fields at one key.
     */
    public function testWireKeysAreUniqueAndSnakeCase(): void
    {
        $keys = MacroFieldRegistry::wireKeys();

        $this->assertCount(count($keys), array_unique(array_values($keys)), 'Two macro fields share a wire key.');

        foreach ($keys as $field => $key) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9]*(_[a-z0-9]+)*$/',
                $key,
                "Wire key for {$field} is not snake_case: {$key}"
            );
        }
    }

    /**
     * Fields the catalog names as printable driver readings must actually exist on the wire.
     */
    public function testDriverCatalogOnlyNamesRealFields(): void
    {
        $keys = array_flip(MacroFieldRegistry::wireKeys());

        foreach (array_keys(\App\Data\MacroFieldCatalog::FIELDS) as $field) {
            $this->assertArrayHasKey($field, $keys, "MacroFieldCatalog names '{$field}', which no macro field publishes.");
        }
    }
}
