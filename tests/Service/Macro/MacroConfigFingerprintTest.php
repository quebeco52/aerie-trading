<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro;

use App\Service\Macro\MacroConfigFingerprint;
use App\Service\Math\MacroConstants;
use PHPUnit\Framework\TestCase;

class MacroConfigFingerprintTest extends TestCase
{
    public function testTheLiveFingerprintIsStableAndShort(): void
    {
        $fingerprint = (new MacroConfigFingerprint())->fingerprint();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $fingerprint);
        $this->assertSame($fingerprint, (new MacroConfigFingerprint())->fingerprint());
    }

    /** A recalibration changes the hash, and only a change of value does: declaration order is not a parameter. */
    public function testOneChangedConstantChangesTheHashAndOrderDoesNot(): void
    {
        $constants = MacroConfigFingerprint::constantsOf([MacroConstants::class]);
        $recalibrated = $constants;
        $recalibrated[MacroConstants::class]['TARGET_INFLATION'] = 0.025;
        $reordered = [MacroConstants::class => array_reverse($constants[MacroConstants::class], true)];

        $this->assertNotSame(MacroConfigFingerprint::of($constants), MacroConfigFingerprint::of($recalibrated));
        $this->assertSame(MacroConfigFingerprint::of($constants), MacroConfigFingerprint::of($reordered));
    }

    /**
     * Classes in the folders whose constants can move the economy, but hold none of its parameters: names, labels,
     * storage keys, solver tolerances and mathematical constants. A change to these is not a recalibration.
     */
    private const NOT_PARAMETERS = [
        \App\Data\Politics\AerieNames::class,
        \App\Data\Politics\AeriePartyProfiles::class,
        \App\Data\Politics\UsNameFrequencies::class,
        MacroConfigFingerprint::class,
        \App\Service\Macro\MacroState::class,
        \App\Service\Macro\Recorder\MacroDiagnosticsProbe::class,
        \App\Service\Macro\Recorder\MacroSnapshotRecorder::class,
        \App\Service\Macro\Recorder\OutputGapProbe::class,
        \App\Service\Math\Distributions::class,
        \App\Service\Math\FixedIncome::class,
        \App\Service\Math\LinearAlgebra::class,
        \App\Service\Math\MathUtility::class,
        \App\Service\Math\OptionPricing::class,
        \App\Service\Politics\PoliticsEngine::class,
        \App\Service\Politics\PoliticsState::class,
        \App\Service\Politics\Recorder\PoliticsHistoryRecorder::class,
    ];

    /**
     * A constant added to a new class in these folders must be either a parameter of the economy or named as none, so
     * a recalibration can never share the fingerprint of the economy it replaced.
     */
    public function testEveryClassWithConstantsInTheEconomysFoldersIsDecided(): void
    {
        $undecided = [];
        foreach (['Service/Macro', 'Service/Politics', 'Service/Math', 'Data/Politics'] as $folder) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../../src/' . $folder, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $relative = substr((string) $file->getRealPath(), strlen((string) realpath(__DIR__ . '/../../../src/')) + 1, -4);
                $class = 'App\\' . str_replace('/', '\\', $relative);
                if (!class_exists($class) && !enum_exists($class)) {
                    continue;
                }
                $own = array_filter((new \ReflectionClass($class))->getReflectionConstants(), static fn(\ReflectionClassConstant $c): bool => $c->getDeclaringClass()->getName() === $class && !$c->isEnumCase());
                if ($own !== [] && !in_array($class, MacroConfigFingerprint::PARAMETER_CLASSES, true) && !in_array($class, self::NOT_PARAMETERS, true)) {
                    $undecided[] = $class;
                }
            }
        }

        $this->assertSame([], $undecided, 'List each class in MacroConfigFingerprint::PARAMETER_CLASSES or in NOT_PARAMETERS here.');
    }
}
