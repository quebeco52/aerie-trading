<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Service\Math\Distributions;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\PrivateEquityBusinessModel;
use PHPUnit\Framework\TestCase;

/**
 * The hurdle cliff zeroes carry in the ~16% of trend quarters that fall below it. Uncompensated, a trend sponsor
 * booked ~0.88 of its target carry for ever; grossed up by the cliff's expectation it books the target on average.
 */
class PrivateEquityCarryCompensationTest extends TestCase
{
    private const DRAWS = 200_000;

    public function testTrendCarryAveragesItsTarget(): void
    {
        $math = new MathUtility();
        foreach ([0.05, 0.20] as $shockScale) {
            $expectation = PrivateEquityBusinessModel::expectedHurdleClearedCarry($shockScale);
            $sum = 0.0;
            for ($i = 0; $i < self::DRAWS; ++$i) {
                $z = $math->generateStandardNormal();
                if ($z >= PrivateEquityBusinessModel::HURDLE_RATE_Z_CLIFF) {
                    $sum += max(0.0, 1.0 + ($z * $shockScale)) / $expectation;
                }
            }

            // SE of the mean ≈ 0.45 / sqrt(200k) ≈ 0.001.
            $this->assertEqualsWithDelta(1.0, $sum / self::DRAWS, 0.006, sprintf('shock scale %.2f', $shockScale));
        }
    }

    public function testTheGrossUpIsTheShareOfTrendQuartersThatClearTheHurdle(): void
    {
        // With no shock scale the expectation is just P(Z ≥ −1) = Φ(1).
        $this->assertEqualsWithDelta(0.841345, PrivateEquityBusinessModel::expectedHurdleClearedCarry(0.0), 1e-5);
        $this->assertEqualsWithDelta(0.241971, Distributions::standardNormalPdf(-1.0), 1e-6);
    }
}
