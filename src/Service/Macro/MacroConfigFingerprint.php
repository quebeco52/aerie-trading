<?php

declare(strict_types=1);

namespace App\Service\Macro;

use App\Data\AerieDiet;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\DistrictPoliticsSubsystem;
use App\Service\Macro\Subsystem\SovereignFundSubsystem;
use App\Service\Macro\Subsystem\LaborMarketSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;

/**
 * A short hash of every constant that parameterises the macro economy, stamped on each recorded quarter.
 *
 * A dump that spans a ticker restart can span a recalibration, and nothing in a row said which constants produced
 * it: two economies read as one run. The hash changes when any constant of the engine or its subsystems changes and
 * with nothing else, so the dump can split a run where the economy did.
 */
final class MacroConfigFingerprint
{
    // --- Parameter Classes ---

    /** The classes whose constants are the macro economy's parameters. */
    private const PARAMETER_CLASSES = [
        MacroEngine::class,
        MonetaryPolicySubsystem::class,
        LaborMarketSubsystem::class,
        MacroAggregateSubsystem::class,
        CommodityLogisticsSubsystem::class,
        AssetMarketSubsystem::class,
        CreditFiscalSubsystem::class,
        SovereignFundSubsystem::class,
        DistrictPoliticsSubsystem::class,
        AerieDiet::class,
    ];

    // --- Format ---

    /** Hex characters kept: 48 bits, ample to tell a handful of calibrations apart in one table. */
    private const LENGTH = 12;

    private ?string $fingerprint = null;

    /** The live economy's fingerprint, computed once per process. */
    public function fingerprint(): string
    {
        return $this->fingerprint ??= self::of(self::constantsOf(self::PARAMETER_CLASSES));
    }

    /**
     * Every constant each class declares, keyed by class.
     *
     * @param  list<class-string>                  $classes Classes to read the constants of.
     * @return array<string, array<string, mixed>>
     */
    public static function constantsOf(array $classes): array
    {
        $constants = [];
        foreach ($classes as $class) {
            $constants[$class] = (new \ReflectionClass($class))->getConstants();
        }

        return $constants;
    }

    /**
     * Fingerprint of a constants map, independent of the order the constants are declared in.
     *
     * @param array<string, array<string, mixed>> $constants Class => constant name => value.
     */
    public static function of(array $constants): string
    {
        ksort($constants);
        foreach ($constants as &$declared) {
            ksort($declared);
        }
        unset($declared);

        return substr(sha1(serialize($constants)), 0, self::LENGTH);
    }
}
