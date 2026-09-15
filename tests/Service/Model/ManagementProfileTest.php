<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\ManagementProfile;
use App\Data\ManagementStyle;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * Bertrand & Schoar (2003) estimate a distribution of manager fixed effects. Four enum cases carry where
 * each kind of manager sits in policy space; the intensity carries the spread around them, so two firms
 * filed under the same label are not the same firm.
 */
final class ManagementProfileTest extends TestCase
{
    /**
     * The invariant the whole mechanic rests on. Roughly half the seeded market declares no style, and every
     * one of those firms still carries an intensity draw — if scaling could move a neutral dial, the draw
     * would silently re-tune the entire unassigned market.
     */
    public function testOperatorStaysExactlyNeutralAtEveryIntensity(): void
    {
        foreach ([ManagementProfile::MIN_INTENSITY, 0.5, 1.0, 1.37, ManagementProfile::MAX_INTENSITY] as $intensity) {
            $profile = new ManagementProfile(ManagementStyle::Operator, $intensity);

            $this->assertSame(1.0, $profile->payoutBias());
            $this->assertSame(1.0, $profile->reinvestmentBias());
            $this->assertSame(1.0, $profile->hurdleBias());
            $this->assertSame(1.0, $profile->cashTargetBias());
            $this->assertSame(1.0, $profile->leverageBias());
            $this->assertSame(1.0, $profile->acquisitionBias());
            $this->assertSame(0.0, $profile->hubrisPremium());
            $this->assertSame(0.0825, $profile->appliedHurdle(0.0825));
        }
    }

    /** An intensity of one is the archetype itself, so the enum's published dials stay the typical manager. */
    public function testMedianIntensityReproducesTheArchetypeExactly(): void
    {
        foreach (ManagementStyle::cases() as $style) {
            $profile = new ManagementProfile($style, ManagementProfile::INTENSITY_MEDIAN);

            $this->assertEqualsWithDelta($style->payoutBias(), $profile->payoutBias(), 1e-12, $style->value);
            $this->assertEqualsWithDelta($style->reinvestmentBias(), $profile->reinvestmentBias(), 1e-12, $style->value);
            $this->assertEqualsWithDelta($style->hurdleBias(), $profile->hurdleBias(), 1e-12, $style->value);
            $this->assertEqualsWithDelta($style->cashTargetBias(), $profile->cashTargetBias(), 1e-12, $style->value);
            $this->assertEqualsWithDelta($style->leverageBias(), $profile->leverageBias(), 1e-12, $style->value);
            $this->assertEqualsWithDelta($style->acquisitionBias(), $profile->acquisitionBias(), 1e-12, $style->value);
            $this->assertEqualsWithDelta($style->hubrisPremium(), $profile->hubrisPremium(), 1e-12, $style->value);
        }
    }

    /**
     * Scaling acts on the DISTANCE from neutral, never on the dial, so a half-hearted empire builder sits
     * halfway between the archetype and the market default rather than halfway to zero.
     */
    public function testIntensityScalesTheDistanceFromNeutralAndNotTheDialItself(): void
    {
        $archetype = ManagementStyle::EmpireBuilder;
        $half = new ManagementProfile($archetype, 0.5);

        $this->assertEqualsWithDelta(
            1.0 + 0.5 * ($archetype->hurdleBias() - 1.0),
            $half->hurdleBias(),
            1e-12
        );
        $this->assertEqualsWithDelta(0.875, $half->hurdleBias(), 1e-12, 'Half of the way from 1.00 down to 0.75.');
        $this->assertEqualsWithDelta(0.5 * $archetype->hubrisPremium(), $half->hubrisPremium(), 1e-12, 'An additive premium scales directly.');
    }

    /** More conviction means the dial sits further from neutral, in whichever direction the style points. */
    public function testConvictionOrdersTheDialsMonotonically(): void
    {
        $mild = new ManagementProfile(ManagementStyle::Fortress, 0.5);
        $typical = new ManagementProfile(ManagementStyle::Fortress, 1.0);
        $severe = new ManagementProfile(ManagementStyle::Fortress, 1.5);

        // Fortress points its cash target up and its leverage appetite down; both must spread outward.
        $this->assertLessThan($typical->cashTargetBias(), $mild->cashTargetBias());
        $this->assertLessThan($severe->cashTargetBias(), $typical->cashTargetBias());
        $this->assertGreaterThan($typical->leverageBias(), $mild->leverageBias());
        $this->assertGreaterThan($severe->leverageBias(), $typical->leverageBias());
    }

    /** The tails of a log-normal are not a place to let a hurdle rate live. */
    public function testIntensityIsClampedAtBothEnds(): void
    {
        $this->assertSame(ManagementProfile::MAX_INTENSITY, ManagementProfile::forStyle(ManagementStyle::Steward, 99.0)->intensity);
        $this->assertSame(ManagementProfile::MIN_INTENSITY, ManagementProfile::forStyle(ManagementStyle::Steward, -5.0)->intensity);
        $this->assertSame(ManagementProfile::INTENSITY_MEDIAN, ManagementProfile::forStyle(ManagementStyle::Steward, null)->intensity);

        // Even at the ceiling no dial may cross through neutral and invert the style's meaning.
        $extreme = new ManagementProfile(ManagementStyle::EmpireBuilder, ManagementProfile::MAX_INTENSITY);
        $this->assertGreaterThan(0.0, $extreme->hurdleBias(), 'A hurdle multiplier must stay positive.');
        $this->assertLessThan(1.0, $extreme->hurdleBias());
        $this->assertGreaterThan(0.0, $extreme->payoutBias());
    }

    /** The draw is centred on the archetype and stays inside its rails. */
    public function testTheDrawIsCentredOnTheArchetype(): void
    {
        mt_srand(20260915);
        $mathUtility = new MathUtility();

        $draws = [];
        for ($i = 0; $i < 4000; $i++) {
            $draws[] = ManagementProfile::drawIntensity($mathUtility);
        }
        sort($draws);

        $this->assertGreaterThanOrEqual(ManagementProfile::MIN_INTENSITY, $draws[0]);
        $this->assertLessThanOrEqual(ManagementProfile::MAX_INTENSITY, $draws[count($draws) - 1]);
        $this->assertEqualsWithDelta(
            ManagementProfile::INTENSITY_MEDIAN,
            $draws[intdiv(count($draws), 2)],
            0.03,
            'A log-normal with zero log-mean has its median at one, which is where the archetype must sit.'
        );

        // And it genuinely disperses: two managers of the same kind should not be the same manager.
        $spread = $draws[(int) (0.9 * count($draws))] - $draws[(int) (0.1 * count($draws))];
        $this->assertGreaterThan(0.4, $spread, 'A distribution flat enough to be four points is the thing this fixes.');
    }

    /** The entity hands the engines one object, so a style and its strength cannot be picked up separately. */
    public function testEntityResolvesTheStyleAndItsStrengthTogether(): void
    {
        $stock = new Stock();
        $stock->setManagementStyle(ManagementStyle::Steward);
        $stock->setManagementIntensity(1.5);

        $profile = $stock->getManagementProfile();
        $this->assertSame(ManagementStyle::Steward, $profile->style);
        $this->assertSame(1.5, $profile->intensity);
        $this->assertGreaterThan(ManagementStyle::Steward->payoutBias(), $profile->payoutBias());

        // An unseeded firm falls back to the archetype's own strength rather than to zero conviction.
        $unseeded = new Stock();
        $unseeded->setManagementStyle(ManagementStyle::Steward);
        $this->assertSame(ManagementProfile::INTENSITY_MEDIAN, $unseeded->getManagementProfile()->intensity);
    }
}
