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
