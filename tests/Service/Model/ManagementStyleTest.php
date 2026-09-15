<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\InitialMarket;
use App\Data\ManagementStyle;
use App\Entity\Stock;
use PHPUnit\Framework\TestCase;

/**
 * Management style as a persistent firm policy bias (Bertrand & Schoar 2003), with the investment
 * dimension expressed as Jensen's (1986) agency cost: an empire builder is not investing harder, it is
 * applying a lower hurdle and funding projects a disciplined board would refuse.
 */
final class ManagementStyleTest extends TestCase
{
    /** An unassigned firm behaves exactly as before: the default must be a no-op on every dial. */
    public function testDefaultStyleIsNeutralOnEveryDial(): void
    {
        $stock = new Stock();
        $stock->setTicker('ZZZZ');

        $this->assertSame(ManagementStyle::Operator, $stock->getManagementStyle());
        $this->assertSame(1.0, ManagementStyle::Operator->payoutBias());
        $this->assertSame(1.0, ManagementStyle::Operator->reinvestmentBias());
        $this->assertSame(1.0, ManagementStyle::Operator->hurdleBias());
        $this->assertSame(1.0, ManagementStyle::Operator->cashTargetBias());
        $this->assertSame(1.0, ManagementStyle::Operator->leverageBias());
        $this->assertSame(1.0, ManagementStyle::Operator->acquisitionBias());
        $this->assertSame(0.0, ManagementStyle::Operator->hubrisPremium());

        // The helpers have to be exact identities on the default, since most of the market is unassigned and
        // a rounding wobble here would move every firm that never opted into the mechanic.
        $this->assertSame(0.0825, ManagementStyle::Operator->appliedHurdle(0.0825));
        $this->assertSame(1_234.5, ManagementStyle::Operator->appliedTargetCash(1_234.5));
    }

    /** The manager's hurdle is a preference laid over the true cost of capital, in the stated direction. */
    public function testAppliedHurdleBendsTheTrueCostOfCapital(): void
    {
        $trueHurdle = 0.10;

        $this->assertEqualsWithDelta(0.075, ManagementStyle::EmpireBuilder->appliedHurdle($trueHurdle), 1e-12);
        $this->assertEqualsWithDelta(0.120, ManagementStyle::Steward->appliedHurdle($trueHurdle), 1e-12);
        $this->assertLessThan(
            $trueHurdle,
            ManagementStyle::EmpireBuilder->appliedHurdle($trueHurdle),
            'The wedge below the true cost of capital IS the agency cost; without it the style prices nothing.'
        );
    }

    /**
     * The fortress must be able to HOLD what it retains. Its discretionary cash target has to sit above the
     * model's, or the hoarding tests read the reserves that define the archetype as a defect and the buyback
     * engine corrects them away.
     */
    public function testFortressRunsAHigherDiscretionaryCashTarget(): void
    {
        $modelTarget = 1_000_000_000.0;

        $this->assertGreaterThan($modelTarget, ManagementStyle::Fortress->appliedTargetCash($modelTarget));
        $this->assertLessThan(
            $modelTarget,
            ManagementStyle::EmpireBuilder->appliedTargetCash($modelTarget),
            'Cash is a project waiting to happen for this manager, not a buffer.'
        );
        $this->assertGreaterThan(
            ManagementStyle::Steward->cashTargetBias(),
            ManagementStyle::Fortress->cashTargetBias(),
            'A steward distributes what it will not reinvest; a fortress keeps it.'
        );
    }

    /** Bertrand & Schoar find the manager effect in financial policy too, and an empire is a levered thing. */
    public function testLeverageAppetiteOrdersFortressBelowEmpireBuilder(): void
    {
        $this->assertGreaterThan(1.0, ManagementStyle::EmpireBuilder->leverageBias());
        $this->assertLessThan(1.0, ManagementStyle::Fortress->leverageBias());
        $this->assertGreaterThan(
            ManagementStyle::Steward->leverageBias(),
            ManagementStyle::EmpireBuilder->leverageBias()
        );
    }

    /**
     * Roll (1986): the overpayment is the empire builder's alone, and it is an ADDITIVE premium, so every
     * disciplined style must sit at exactly zero rather than merely low.
     */
    public function testOnlyTheEmpireBuilderPaysAHubrisPremium(): void
    {
        $this->assertGreaterThan(0.0, ManagementStyle::EmpireBuilder->hubrisPremium());
        $this->assertGreaterThan(1.0, ManagementStyle::EmpireBuilder->acquisitionBias());

        foreach ([ManagementStyle::Operator, ManagementStyle::Steward, ManagementStyle::Fortress] as $disciplined) {
            $this->assertSame(0.0, $disciplined->hubrisPremium(), $disciplined->value . ' must not overpay.');
        }

        $this->assertLessThan(1.0, ManagementStyle::Steward->acquisitionBias());
        $this->assertLessThan(1.0, ManagementStyle::Fortress->acquisitionBias());
    }

    /**
     * Guards the contract the whole mechanic rests on: an unassigned firm is untouched. Half the seeded
     * market declares no style at all, so any dial added later that is not neutral on Operator silently
     * re-tunes every one of those firms.
     */
    public function testEveryMultiplierIsExactlyNeutralOnTheDefaultStyle(): void
    {
        $multipliers = ['payoutBias', 'reinvestmentBias', 'hurdleBias', 'cashTargetBias', 'leverageBias', 'acquisitionBias'];

        foreach ($multipliers as $dial) {
            $this->assertSame(
                1.0,
                ManagementStyle::Operator->{$dial}(),
                sprintf('Operator::%s() must be exactly 1.0 — it is the no-op baseline every unassigned firm runs on.', $dial)
            );
        }
    }

    /** The agency case: growth is funded past the point where it creates value. */
    public function testEmpireBuilderAcceptsProjectsAStewardWouldRefuse(): void
    {
        $this->assertLessThan(
            1.0,
            ManagementStyle::EmpireBuilder->hurdleBias(),
            'Jensen (1986): the agency cost is a hurdle below the true cost of capital, not merely a bigger budget.'
        );
        $this->assertGreaterThan(
            ManagementStyle::EmpireBuilder->hurdleBias(),
            ManagementStyle::Steward->hurdleBias(),
            'A disciplined manager holds a higher internal hurdle than an empire builder.'
        );
        $this->assertGreaterThan(1.0, ManagementStyle::EmpireBuilder->reinvestmentBias());
    }

    /** Retention and distribution are the mirror image of one another across the styles. */
    public function testPayoutAndReinvestmentBiasesPointInOppositeDirections(): void
    {
        foreach ([ManagementStyle::EmpireBuilder, ManagementStyle::Steward] as $style) {
            $this->assertNotSame(1.0, $style->payoutBias());
            $this->assertSame(
                $style->payoutBias() > 1.0,
                $style->reinvestmentBias() < 1.0,
                sprintf('%s must not both distribute more and reinvest more from the same cash.', $style->value)
            );
        }
    }

    /** A conservative balance sheet retains without distributing, which is a distinct posture. */
    public function testFortressRetainsWithoutDistributing(): void
    {
        $this->assertLessThan(1.0, ManagementStyle::Fortress->payoutBias(), 'Cash is held against the cycle, not paid out.');
        $this->assertLessThan(1.0, ManagementStyle::Fortress->reinvestmentBias(), 'And it is not spent on growth either.');
        $this->assertGreaterThan(1.0, ManagementStyle::Fortress->hurdleBias());
    }

    /** An unknown or malformed stored value degrades to the neutral default rather than throwing. */
    public function testUnknownStoredStyleFallsBackToTheDefault(): void
    {
        $this->assertSame(ManagementStyle::Operator, ManagementStyle::tryFromNullable('chief_vibes_officer'));
        $this->assertSame(ManagementStyle::Operator, ManagementStyle::tryFromNullable(null));
    }

    /** Round-trips through the entity, since it is persisted as a plain string column. */
    public function testStylePersistsThroughTheEntity(): void
    {
        $stock = new Stock();
        $stock->setManagementStyle(ManagementStyle::Fortress);
        $this->assertSame(ManagementStyle::Fortress, $stock->getManagementStyle());

        $stock->setManagementStyle(null);
        $this->assertSame(ManagementStyle::Operator, $stock->getManagementStyle());
    }

    /** Every style declared in the seed data must be one the enum actually knows. */
    public function testSeededStylesAreAllValid(): void
    {
        $assigned = 0;

        foreach (InitialMarket::STOCKS as $stockData) {
            if (!isset($stockData['management_style'])) {
                continue;
            }

            $assigned++;
            $this->assertNotNull(
                ManagementStyle::tryFrom($stockData['management_style']),
                sprintf('%s declares management style "%s", which is not a ManagementStyle case.', $stockData['ticker'], $stockData['management_style'])
            );
        }

        $this->assertGreaterThan(0, $assigned, 'The seed data should exercise the mechanic on at least one firm.');
    }
}
