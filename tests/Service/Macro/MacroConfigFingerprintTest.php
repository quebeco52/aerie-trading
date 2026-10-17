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
}
