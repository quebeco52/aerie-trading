<?php

declare(strict_types=1);

namespace App\Tests\Service\Math;

use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * Corporate credit primitives. Both of these exist to stop the same mistake — pricing a risky claim as if
 * the bad year never comes — so the properties asserted are the ones that only show up in a bad year:
 * recovery falling exactly when defaults cluster, and a spread that is not zero on a safe name.
 */
class MathUtilityCreditTest extends TestCase
{
    private MathUtility $math;

    protected function setUp(): void
    {
        $this->math = new MathUtility();
    }

    // --- Recovery ---

    public function testAnAverageDefaultYearRecoversTheBaseRate(): void
    {
        $recovery = $this->math->calculateRecoveryGivenDefault(
            FinancialConstants::RECOVERY_SENIOR_UNSECURED,
            FinancialConstants::RECOVERY_BASELINE_DEFAULT_RATE
        );

        $this->assertEqualsWithDelta(FinancialConstants::RECOVERY_SENIOR_UNSECURED, $recovery, 1e-12);
    }

    public function testRecoveryFallsAsDefaultsCluster(): void
    {
        // The whole point of the model: the claim is worth least in the year most of them are being settled.
        $quiet = $this->math->calculateRecoveryGivenDefault(0.48, 0.009);
        $average = $this->math->calculateRecoveryGivenDefault(0.48, 0.018);
        $crisis = $this->math->calculateRecoveryGivenDefault(0.48, 0.072);

        $this->assertGreaterThan($average, $quiet);
        $this->assertLessThan($average, $crisis);
    }

    public function testTheFallIsLogLinearInTheDefaultRate(): void
    {
        // Each doubling of the default rate costs the same amount of recovery.
        $first = $this->math->calculateRecoveryGivenDefault(0.60, 0.018) - $this->math->calculateRecoveryGivenDefault(0.60, 0.036);
        $second = $this->math->calculateRecoveryGivenDefault(0.60, 0.036) - $this->math->calculateRecoveryGivenDefault(0.60, 0.072);

        $this->assertEqualsWithDelta($first, $second, 1e-12);
        $this->assertEqualsWithDelta(FinancialConstants::RECOVERY_DEFAULT_RATE_ELASTICITY * log(2.0), $first, 1e-12);
    }

    public function testSeniorityOrdersRecoveryAtAnyPointInTheCycle(): void
    {
        foreach ([0.005, 0.018, 0.10] as $defaultRate) {
            $secured = $this->math->calculateRecoveryGivenDefault(FinancialConstants::RECOVERY_SENIOR_SECURED, $defaultRate);
            $unsecured = $this->math->calculateRecoveryGivenDefault(FinancialConstants::RECOVERY_SENIOR_UNSECURED, $defaultRate);
            $subordinated = $this->math->calculateRecoveryGivenDefault(FinancialConstants::RECOVERY_SUBORDINATED, $defaultRate);

            $this->assertGreaterThan($unsecured, $secured, "at a default rate of {$defaultRate}");
            $this->assertGreaterThan($subordinated, $unsecured, "at a default rate of {$defaultRate}");
        }
    }

    public function testRecoveryStaysInsideItsBounds(): void
    {
        // A catastrophic default year, and an implausibly quiet one.
        $this->assertGreaterThanOrEqual(
            FinancialConstants::MIN_RECOVERY_RATE,
            $this->math->calculateRecoveryGivenDefault(0.28, 0.99)
        );
        $this->assertLessThanOrEqual(
            FinancialConstants::MAX_RECOVERY_RATE,
            $this->math->calculateRecoveryGivenDefault(0.62, 1.0e-9)
        );
    }

    public function testANonsenseDefaultRateReturnsTheBaseRatherThanDividingByIt(): void
    {
        $this->assertEqualsWithDelta(0.48, $this->math->calculateRecoveryGivenDefault(0.48, 0.0), 1e-12);
        $this->assertEqualsWithDelta(0.48, $this->math->calculateRecoveryGivenDefault(0.48, 0.02, 0.0), 1e-12);
    }

    // --- Spread ---

    public function testASafeIssuerStillPaysTheNonDefaultComponent(): void
    {
        // A bond priced on default risk alone quotes through the market, and worst exactly here: at the safe
        // end the default component is almost nothing and the residual is almost all of the spread.
        $spread = $this->math->calculateCorporateSpread(8.0, 0.52, 5.0);

        $this->assertGreaterThanOrEqual(FinancialConstants::CORPORATE_ILLIQUIDITY_SPREAD, $spread);
        $this->assertLessThan(0.01, $spread);
    }

    public function testAWeakerIssuerPaysMore(): void
    {
        $strong = $this->math->calculateCorporateSpread(5.0, 0.52, 5.0);
        $weak = $this->math->calculateCorporateSpread(1.0, 0.52, 5.0);

        $this->assertGreaterThan($strong, $weak);
    }

    public function testALowerRecoveryClaimPaysMoreOnTheSameIssuer(): void
    {
        $senior = $this->math->calculateCorporateSpread(2.0, 1.0 - FinancialConstants::RECOVERY_SENIOR_SECURED, 5.0);
        $subordinated = $this->math->calculateCorporateSpread(2.0, 1.0 - FinancialConstants::RECOVERY_SUBORDINATED, 5.0);

        // Same probability of default, different amount lost when it happens.
        $this->assertGreaterThan($senior, $subordinated);
    }

    public function testTheSpreadCarriesATermStructureRatherThanBeingFlat(): void
    {
        // A distressed name is riskier over one year than over ten: it either survives the year or it does
        // not, and the annualized cost of that is highest at the front.
        $oneYear = $this->math->calculateCorporateSpread(0.5, 0.52, 1.0);
        $tenYear = $this->math->calculateCorporateSpread(0.5, 0.52, 10.0);

        $this->assertGreaterThan($tenYear, $oneYear);
    }

    public function testTheSpreadIsBoundedAndNeverNegative(): void
    {
        $this->assertLessThanOrEqual(FinancialConstants::MAX_CORPORATE_SPREAD, $this->math->calculateCorporateSpread(-10.0, 0.95, 0.1));
        $this->assertGreaterThanOrEqual(0.0, $this->math->calculateCorporateSpread(50.0, 0.0, 30.0, 0.0));
    }

    public function testAZeroMaturityDoesNotDivideByItself(): void
    {
        $spread = $this->math->calculateCorporateSpread(2.0, 0.52, 0.0);

        $this->assertTrue(is_finite($spread));
        $this->assertGreaterThanOrEqual(0.0, $spread);
    }
}
