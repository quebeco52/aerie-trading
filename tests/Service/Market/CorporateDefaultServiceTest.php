<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Entity\Bond;
use App\Service\Market\CorporateDefaultService;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * What a bondholder gets when the company fails.
 *
 * The point of the whole credit layer is that a creditor is not a shareholder: equity goes to zero and the
 * claim does not. So the properties worth pinning are that something always comes back, that where the claim
 * sits in the queue decides how much, and that a firm failing in the middle of a credit cycle settles worse
 * than the same firm failing alone.
 */
class CorporateDefaultServiceTest extends TestCase
{
    private MathUtility $math;

    protected function setUp(): void
    {
        $this->math = new MathUtility();
    }

    /** Recovery for a claim, as the service computes it. */
    private function recovery(?string $seniority, float $defaultRate): float
    {
        return $this->math->calculateRecoveryGivenDefault(
            CorporateDefaultService::baseRecoveryFor($seniority),
            $defaultRate
        );
    }

    // --- The Queue ---

    public function testSeniorityDecidesHowMuchComesBack(): void
    {
        $secured = $this->recovery(Bond::SENIORITY_SENIOR_SECURED, 0.018);
        $unsecured = $this->recovery(Bond::SENIORITY_SENIOR_UNSECURED, 0.018);
        $subordinated = $this->recovery(Bond::SENIORITY_SUBORDINATED, 0.018);

        $this->assertGreaterThan($unsecured, $secured);
        $this->assertGreaterThan($subordinated, $unsecured);
    }

    public function testAnIssueThatRecordedNoSeniorityIsTreatedAsTheOrdinaryCorporateBond(): void
    {
        // Senior unsecured is what a public corporate bond IS, so it is the right thing to assume about one
        // that did not say — and assuming anything safer would quietly flatter every such claim.
        $this->assertSame(
            FinancialConstants::RECOVERY_SENIOR_UNSECURED,
            CorporateDefaultService::baseRecoveryFor(null)
        );
        $this->assertSame(
            FinancialConstants::RECOVERY_SENIOR_UNSECURED,
            CorporateDefaultService::baseRecoveryFor('SOMETHING_UNKNOWN')
        );
    }

    public function testASovereignSeniorityMapsToNothingExotic(): void
    {
        $this->assertSame(
            FinancialConstants::RECOVERY_SENIOR_SECURED,
            CorporateDefaultService::baseRecoveryFor(Bond::SENIORITY_SENIOR_SECURED)
        );
        $this->assertSame(
            FinancialConstants::RECOVERY_SUBORDINATED,
            CorporateDefaultService::baseRecoveryFor(Bond::SENIORITY_SUBORDINATED)
        );
    }

    // --- The Cycle ---

    public function testFailingDuringACreditCycleSettlesWorseThanFailingAlone(): void
    {
        // The correlation that makes a diversified credit book less safe than it looks: the losses arrive
        // together AND each one is worse than average.
        $alone = $this->recovery(Bond::SENIORITY_SENIOR_UNSECURED, 0.008);
        $inACycle = $this->recovery(Bond::SENIORITY_SENIOR_UNSECURED, 0.090);

        $this->assertGreaterThan($inACycle, $alone);
    }

    public function testTheOrderOfTheQueueSurvivesTheCycle(): void
    {
        // Seniority must not be overtaken by the cycle: a secured claim in a crisis still beats a
        // subordinated one in a boom would be too strong a claim, but within any one year the order holds.
        foreach ([0.005, 0.018, 0.05, 0.12] as $rate) {
            $this->assertGreaterThan(
                $this->recovery(Bond::SENIORITY_SUBORDINATED, $rate),
                $this->recovery(Bond::SENIORITY_SENIOR_SECURED, $rate),
                "at a default rate of {$rate}"
            );
        }
    }

    // --- Something Always Comes Back ---

    public function testACreditorNeverRecoversNothing(): void
    {
        // This is the line between a bond and the equity beneath it. Even in the worst year modelled, the
        // claim settles at something; the shares settle at zero.
        foreach ([Bond::SENIORITY_SENIOR_SECURED, Bond::SENIORITY_SENIOR_UNSECURED, Bond::SENIORITY_SUBORDINATED] as $seniority) {
            $this->assertGreaterThanOrEqual(
                FinancialConstants::MIN_RECOVERY_RATE,
                $this->recovery($seniority, 0.99),
                $seniority
            );
        }
    }

    public function testACreditorNeverRecoversMoreThanTheClaim(): void
    {
        foreach ([1.0e-9, 0.001, 0.018] as $rate) {
            $this->assertLessThanOrEqual(1.0, $this->recovery(Bond::SENIORITY_SENIOR_SECURED, $rate));
        }
    }

    public function testRecoveryIsAShareOfFaceSoTheCashIsFaceTimesIt(): void
    {
        $recovery = $this->recovery(Bond::SENIORITY_SENIOR_UNSECURED, 0.018);
        $face = FinancialConstants::BOND_FACE_VALUE;

        $this->assertEqualsWithDelta($face * FinancialConstants::RECOVERY_SENIOR_UNSECURED, $face * $recovery, 1e-6);
        $this->assertLessThan($face, $face * $recovery);
        $this->assertGreaterThan(0.0, $face * $recovery);
    }
}
