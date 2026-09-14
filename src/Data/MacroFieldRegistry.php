<?php

declare(strict_types=1);

namespace App\Data;

use App\DTO\MacroStateDTO;
use App\Entity\MacroReport;
use Doctrine\ORM\Mapping as ORM;

/**
 * Single source of truth for the macro state vector's field names.
 *
 * The macro vector used to be spelled out by hand in six places — App\Service\Macro\MacroState's
 * properties and its fromArray/toArray, App\DTO\MacroStateDTO's constructor and its
 * fromArray/fromMacroState/toArray, App\Entity\MacroReport's columns, and the positional INSERT in
 * App\Service\Macro\Recorder\MacroSnapshotRecorder. Adding one observable meant ten hand edits, and
 * two of them failed silently: a key missing from a fromArray hydrates a default instead of the
 * recorded value, and a misordered argument in a 107-placeholder positional INSERT writes every
 * later column into the wrong field.
 *
 * This registry derives all of it from the two declarations that are already authoritative:
 *
 *  - App\DTO\MacroStateDTO's promoted constructor parameters are the FIELD LIST. They carry the
 *    types and the defaults, so they stay the place a new observable is declared.
 *  - App\Entity\MacroReport's mapped columns are the PERSISTED SUBSET. Doctrine builds the
 *    migration from that entity, so the table it describes is by definition the table that exists.
 *
 * Both are matched on the PHP property name, which is identical on all three classes. A field that
 * appears in one and not the other is a drift, and App\Tests\DTO\MacroFieldRegistryTest fails on it
 * rather than letting it reach the database.
 */
final class MacroFieldRegistry
{
    // --- Wire Key Convention ---

    /**
     * Field list as PHP property name => snake_case wire key, in constructor order.
     *
     * @var array<string, string>|null
     */
    private static ?array $wireKeys = null;

    /**
     * Persisted subset as PHP property name => macro_report column, in entity order.
     *
     * @var array<string, string>|null
     */
    private static ?array $columns = null;

    /**
     * Opening values as PHP property name => the DTO constructor's declared default.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $defaults = null;

    /**
     * The wire key for every macro field, in the order MacroStateDTO declares them.
     *
     * These are the keys the Redis state payload uses, the keys MacroStateDTO::toArray() publishes,
     * and the names App\Data\MacroFieldCatalog and App\Data\DistrictMap refer to observables by.
     *
     * @return array<string, string> PHP property name => wire key.
     */
    public static function wireKeys(): array
    {
        if (self::$wireKeys !== null) {
            return self::$wireKeys;
        }

        $keys = [];
        $constructor = (new \ReflectionClass(MacroStateDTO::class))->getConstructor();
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            $keys[$name] = self::toWireKey($name);
        }

        return self::$wireKeys = $keys;
    }

    /**
     * The macro_report column for every persisted macro field, in the order the entity declares them.
     *
     * Doctrine's naming strategy drops the underscore before an embedded digit run, so the curve
     * fields land in columns (`yield2y`, `term_premium10y`) that do not match their wire keys
     * (`yield_2y`, `term_premium_10y`). Reading the column off the entity keeps that discrepancy in
     * the one place it is real instead of re-encoding it as a rule.
     *
     * @return array<string, string> PHP property name => macro_report column name.
     */
    public static function persistedColumns(): array
    {
        if (self::$columns !== null) {
            return self::$columns;
        }

        $fields = self::wireKeys();
        $columns = [];
        foreach ((new \ReflectionClass(MacroReport::class))->getProperties() as $property) {
            $name = $property->getName();
            if (!isset($fields[$name])) {
                // The surrogate key and the timestamp are the recorder's own business, and an
                // orphan column is reported by the registry test rather than silently recorded.
                continue;
            }

            $attributes = $property->getAttributes(ORM\Column::class);
            if ($attributes === []) {
                continue;
            }

            $column = $attributes[0]->newInstance();
            $columns[$name] = $column->name ?? self::toColumnName($name);
        }

        return self::$columns = $columns;
    }

    /**
     * Every mapped column on App\Entity\MacroReport, including the ones the macro vector does not own.
     *
     * The registry test uses this to prove no macro_report column is left without a field to fill it.
     *
     * @return array<string, string> PHP property name => macro_report column name.
     */
    public static function entityColumns(): array
    {
        $columns = [];
        foreach ((new \ReflectionClass(MacroReport::class))->getProperties() as $property) {
            $attributes = $property->getAttributes(ORM\Column::class);
            if ($attributes === []) {
                continue;
            }

            $column = $attributes[0]->newInstance();
            $columns[$property->getName()] = $column->name ?? self::toColumnName($property->getName());
        }

        return $columns;
    }

    /**
     * The opening value MacroStateDTO gives each field when the payload carries none.
     *
     * @return array<string, mixed> Field name => the constructor's declared default.
     */
    public static function defaults(): array
    {
        if (self::$defaults !== null) {
            return self::$defaults;
        }

        $defaults = [];
        $constructor = (new \ReflectionClass(MacroStateDTO::class))->getConstructor();
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $defaults[$parameter->getName()] = $parameter->isDefaultValueAvailable()
                ? $parameter->getDefaultValue()
                : null;
        }

        return self::$defaults = $defaults;
    }

    /**
     * Fields seeded from another field when the payload carries no value of their own, for the pairs
     * the `*Ema` naming convention does not already describe.
     *
     * A smoothed series with no history of its own has to start level with the series it smooths, or
     * its first reading is a jump away from a value nothing ever observed.
     *
     * @var array<string, string>
     */
    private const SEED_OVERRIDES = [
        'energyBasePrice' => 'energyPriceIndex',
        'supercoreInflation' => 'inflation',
        'coreGoodsInflation' => 'inflation',
    ];

    /**
     * Fields that fall back to another field's value when their own wire key is absent.
     *
     * Every `xEma` seeds from `x` where `x` is itself a field, which covers the smoothed series; the
     * pairs that do not follow from the names are declared in SEED_OVERRIDES. Returned in
     * constructor order, so seeding a field from one that is itself seeded resolves in one pass.
     *
     * @return array<string, string> Field name => the field it takes its opening value from.
     */
    public static function seeds(): array
    {
        $fields = self::wireKeys();
        $seeds = [];
        foreach (array_keys($fields) as $field) {
            if (isset(self::SEED_OVERRIDES[$field])) {
                $seeds[$field] = self::SEED_OVERRIDES[$field];
                continue;
            }

            if (str_ends_with($field, 'Ema')) {
                $base = substr($field, 0, -3);
                if (isset($fields[$base])) {
                    $seeds[$field] = $base;
                }
            }
        }

        return $seeds;
    }

    /**
     * Converts a camelCase field name to its snake_case wire key.
     *
     * An embedded digit run opens a new word (`yield2y` => `yield_2y`, `termPremium10y` =>
     * `term_premium_10y`) but a trailing one does not (`nsBeta1` => `ns_beta1`), which is the
     * convention the existing Redis payload and the district readouts already use.
     */
    private static function toWireKey(string $name): string
    {
        $key = preg_replace('/(?<!^)[A-Z]/', '_$0', $name) ?? $name;
        $key = preg_replace('/(?<![_0-9])(\d+)(?=[^\W_])/', '_$1', $key) ?? $key;

        return strtolower($key);
    }

    /**
     * Doctrine's default underscore naming strategy, used when a column declares no explicit name.
     */
    private static function toColumnName(string $name): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name) ?? $name);
    }
}
