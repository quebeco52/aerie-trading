<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\DTO\SovereignCurveDTO;
use App\Entity\Bond;
use App\Entity\Stock;
use App\Service\Market\BondPricingEngine;
use App\Service\Market\CorporateBondDesk;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Tests\Support\StockBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The corporate issuance desk.
 *
 * The property that matters most is the one that cannot be seen from a single deal: a listed issue is a
 * TRANCHE of debt the firm already owes, never additional borrowing. If issuing ever moved the balance
 * sheet, every deal would lever the firm up for free and the leverage covenant, the Altman score and the
 * cost of capital would all be reading a number the market had invented.
 */
class CorporateBondDeskTest extends TestCase
{
    private function desk(): CorporateBondDesk
    {
        $math = new MathUtility();

        return new CorporateBondDesk(
            $this->createStub(EntityManagerInterface::class),
            new BondPricingEngine($math),
        );
    }

    private function curve(): SovereignCurveDTO
    {
        return new SovereignCurveDTO(
            level: 0.0425,
            slope: -0.0175,
            curvature1: 0.0,
            curvature2: 0.0,
            baseTermPremium: 0.0125,
            longEndPremium: 0.0125,
            balanceSheetIntensity: 0.0,
        );
    }

    private function issuer(float $debt = 4.0e9, string $rating = 'BBB', float $spread = 0.015): Stock
    {
        $stock = StockBuilder::create('VANE')->withPrice(100.0)->build();
        $stock->setWholesaleDebt((string) $debt);
        $stock->setCreditRating($rating);
        $stock->setDynamicCreditSpread((string) $spread);

        return $stock;
    }

    // --- The Tranche Invariant ---

    public function testIssuingCreatesNoNewDebtOnTheBalanceSheet(): void
    {
        $stock = $this->issuer();
        $before = (float) $stock->getWholesaleDebt();

        $this->desk()->issue($stock, $this->curve(), 1.0, 5.0, 5.0e8);

        $this->assertEqualsWithDelta($before, (float) $stock->getWholesaleDebt(), 1e-6);
    }

    public function testTheTargetListedFaceIsAShareOfWhatTheFirmAlreadyOwes(): void
    {
        $stock = $this->issuer(4.0e9);

        $this->assertEqualsWithDelta(
            4.0e9 * FinancialConstants::CORPORATE_PUBLIC_DEBT_SHARE,
            $this->desk()->targetPublicFace($stock),
            1e-6
        );
    }

    public function testAFirmWithNoDebtHasNothingToList(): void
    {
        $stock = $this->issuer(0.0);

        $this->assertSame(0.0, $this->desk()->targetPublicFace($stock));
    }

    // --- Who The Market Is Open To ---

    public function testASolidIssuerWithRealDebtCanComeToMarket(): void
    {
        $this->assertTrue($this->desk()->canIssue($this->issuer()));
    }

    public function testAFirmBelowTheRatingFloorIsShutOut(): void
    {
        // The same floor DebtEngine applies when it asks whether a maturity can be rolled: a firm nobody
        // will refinance is not a firm that can sell fresh paper.
        $this->assertFalse($this->desk()->canIssue($this->issuer(4.0e9, 'D')));
    }

    public function testAFirmAtTheRatingFloorIsStillLetIn(): void
    {
        $this->assertTrue($this->desk()->canIssue($this->issuer(4.0e9, 'CCC')));
    }

    public function testAFirmWithTooLittleDebtDoesNotBotherWithThePublicMarket(): void
    {
        $tooSmall = FinancialConstants::CORPORATE_MIN_PUBLIC_DEBT - 1.0;

        $this->assertFalse($this->desk()->canIssue($this->issuer($tooSmall)));
    }

    public function testABankruptShellCannotIssue(): void
    {
        $stock = $this->issuer();
        $stock->setIsBankrupt(true);

        $this->assertFalse($this->desk()->canIssue($stock));
    }

    // --- Pricing The Deal ---

    public function testAFreshIssueOpensAtPar(): void
    {
        // Struck on the curve PLUS the issuer's own spread, so the bond is worth what it was sold for
        // rather than immediately at a discount to its own issue price.
        $bond = $this->desk()->issue($this->issuer(), $this->curve(), 1.0, 5.0, 5.0e8);

        $this->assertEqualsWithDelta(
            FinancialConstants::BOND_FACE_VALUE,
            (float) $bond->getCleanPrice(),
            FinancialConstants::BOND_FACE_VALUE * 0.01
        );
    }

    public function testAWeakerIssuerPaysAHigherCouponForTheSameMoney(): void
    {
        $strong = $this->desk()->issue($this->issuer(4.0e9, 'A', 0.005), $this->curve(), 1.0, 5.0, 5.0e8);
        $weak = $this->desk()->issue($this->issuer(4.0e9, 'BB', 0.060), $this->curve(), 1.0, 5.0, 5.0e8);

        $this->assertGreaterThan((float) $strong->getCouponRate(), (float) $weak->getCouponRate());
    }

    public function testACorporateCouponSitsAboveTheSovereignOfTheSameTenor(): void
    {
        $math = new MathUtility();
        $engine = new BondPricingEngine($math);

        $sovereign = $engine->parCouponRate($this->curve(), 5.0, FinancialConstants::BOND_FACE_VALUE);
        $corporate = (float) $this->desk()->issue($this->issuer(), $this->curve(), 1.0, 5.0, 5.0e8)->getCouponRate();

        $this->assertGreaterThan($sovereign, $corporate);
    }

    public function testTheIssueRecordsWhoOwesTheMoneyAndWhereTheClaimSits(): void
    {
        $stock = $this->issuer();
        $bond = $this->desk()->issue($stock, $this->curve(), 1.0, 7.0, 5.0e8);

        $this->assertSame($stock, $bond->getIssuer());
        $this->assertFalse($bond->isSovereign());
        $this->assertSame(Bond::SENIORITY_SENIOR_UNSECURED, $bond->getSeniority());
        $this->assertEqualsWithDelta(0.015, (float) $bond->getCreditSpread(), 1e-9);
    }

    public function testTheIssueMaturesAtItsTenorFromToday(): void
    {
        $bond = $this->desk()->issue($this->issuer(), $this->curve(), 4.25, 10.0, 5.0e8);

        $this->assertEqualsWithDelta(14.25, $bond->getMaturesAtTime(), 1e-9);
        $this->assertEqualsWithDelta(4.25, $bond->getIssuedAtTime(), 1e-9);
    }

    public function testASovereignIssueIsStillRecognisedAsOne(): void
    {
        $this->assertTrue((new Bond())->isSovereign());
    }

    // --- Cadence ---

    public function testTheDeskComesToMarketOnItsOwnSlowInterval(): void
    {
        $interval = CorporateBondDesk::issuanceIntervalTicks(14400);

        $this->assertSame((int) (14400 / FinancialConstants::CORPORATE_ISSUANCE_PER_YEAR), $interval);
        $this->assertGreaterThan(1, $interval);
    }

    public function testTheIntervalStaysAtLeastOneTickAtAnyTickRate(): void
    {
        foreach ([1, 4, 52, 14400, 54000] as $ticksPerYear) {
            $this->assertGreaterThanOrEqual(1, CorporateBondDesk::issuanceIntervalTicks($ticksPerYear));
        }
    }

    // --- The Ladder Stays Bounded ---

    public function testTheDebtGateAdmitsOnlyFirmsThatCanActuallyBringADeal(): void
    {
        // The gate and the issue sizing have to agree. Chosen independently they do not: a firm passes the
        // debt test, every slice of its ladder then prices below the minimum issue size, and it silently
        // never comes to market at all. Deriving the gate from the sizing is what closes that.
        $smallestAdmitted = FinancialConstants::CORPORATE_MIN_PUBLIC_DEBT;
        $trancheSlice = ($smallestAdmitted * FinancialConstants::CORPORATE_PUBLIC_DEBT_SHARE)
            / FinancialConstants::CORPORATE_LADDER_ISSUES;

        $this->assertGreaterThanOrEqual(
            FinancialConstants::CORPORATE_MIN_ISSUE_FACE,
            $trancheSlice,
            'the smallest admitted firm cannot bring an issue large enough to be worth bringing'
        );
    }

    public function testTheSteadyStateLadderIsTheConfiguredNumberOfIssues(): void
    {
        // This is what keeps the corporate ladder from growing without bound and taking the bond marking
        // loop with it: the count, not the tenors, sets how many issues a firm carries.
        $this->assertGreaterThan(0, FinancialConstants::CORPORATE_LADDER_ISSUES);
        $this->assertLessThanOrEqual(6, FinancialConstants::CORPORATE_LADDER_ISSUES);
    }
}
