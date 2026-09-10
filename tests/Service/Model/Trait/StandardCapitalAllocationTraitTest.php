<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Trait;

use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use App\Tests\Support\Model\BareStandardModel;
use PHPUnit\Framework\TestCase;

/**
 * The payout and reinvestment policy every non-financial sector inherits.
 *
 * Nothing here is gated on a constant, which is precisely why it is easy to miss: these are the numbers
 * a sector model uses unless it writes its own, and no sector test addresses them directly.
 */
final class StandardCapitalAllocationTraitTest extends TestCase
{
    private BareStandardModel $model;

    protected function setUp(): void
    {
        $this->model = new BareStandardModel();
    }

    /**
     * Buyback spend is a share of excess cash only, and a mega hoarder returns a larger share of it.
     */
    public function testBuybackSpendScalesWithExcessCashAndAcceleratesForMegaHoarders(): void
    {
        $excess = 500_000_000.0;

        $normal = $this->model->calculateMaxBuybackSpend($excess, 50_000_000.0, false);
        $mega = $this->model->calculateMaxBuybackSpend($excess, 50_000_000.0, true);

        $this->assertSame($excess * FinancialConstants::BUYBACK_SPEND_NORMAL_RATIO, $normal);
        $this->assertSame($excess * FinancialConstants::BUYBACK_SPEND_MEGA_HOARDER_RATIO, $mega);
        $this->assertGreaterThan($normal, $mega, 'A mega hoarder must return cash faster than a normal firm.');

        // Retained earnings are not a second source: the standard policy buys back out of the cash pile alone.
        $this->assertSame(
            $normal,
            $this->model->calculateMaxBuybackSpend($excess, 900_000_000.0, false),
            'A strong quarter does not itself enlarge the buyback; only the cash pile does.'
        );

        $this->assertSame(0.0, $this->model->calculateMaxBuybackSpend(0.0, 100_000_000.0, true), 'No excess cash means no buyback, however profitable the quarter.');
    }

    /**
     * Debt raised for growth must actually be spent on growth: capex takes the larger of the intended spend
     * and a fixed share of what was borrowed, so a firm cannot issue for capex and sit on the proceeds.
     */
    public function testOrganicCapexTakesTheLargerOfIntendedSpendAndTheDebtFundedFloor(): void
    {
        $debtIssued = 400_000_000.0;
        $floor = $debtIssued * FinancialConstants::ORGANIC_CAPEX_DEBT_RATIO;

        $this->assertSame($floor, $this->model->calculateOrganicCapexSpend(10_000_000.0, $debtIssued), 'Borrowing for growth forces the spend up to the debt-funded floor.');
        $this->assertSame(900_000_000.0, $this->model->calculateOrganicCapexSpend(900_000_000.0, $debtIssued), 'A larger intended spend is not cut back to the floor.');
        $this->assertSame(50_000_000.0, $this->model->calculateOrganicCapexSpend(50_000_000.0, 0.0), 'With no debt raised the intended spend stands alone.');
    }

    /**
     * A hoarder is allowed to grow faster because it is funding growth from a cash pile it already holds.
     */
    public function testGrowthSpeedIsHigherForHoarders(): void
    {
        $this->assertSame(0.08, $this->model->getMaxOrganicGrowthSpeed(false, false));
        $this->assertSame(0.15, $this->model->getMaxOrganicGrowthSpeed(true, false));
        $this->assertGreaterThan(
            $this->model->getMaxOrganicGrowthSpeed(false, false),
            $this->model->getMaxOrganicGrowthSpeed(true, true),
            'A cash-rich firm must be able to reinvest faster than a lean one.'
        );
    }

    /**
     * The dividend base is this quarter's earnings: a non-financial does not smooth against invested capital.
     */
    public function testSustainableDividendBaseIsQuarterlyEarnings(): void
    {
        $stock = (new Stock())->setTicker('PAY');
        $this->assertSame(2.40, $this->model->getSustainableDividendBase($stock, 2.40, 1_000_000_000.0, 0.05));
        $this->assertSame(-0.50, $this->model->getSustainableDividendBase($stock, -0.50, 1_000_000_000.0, 0.05), 'A loss passes through; the payout engine, not the model, decides what to do with it.');
    }

    /**
     * Only regulated sectors have a supervisor standing between them and their shareholders. A null here is
     * the signal that no cap applies, which is distinct from a cap that happens to be zero.
     */
    public function testUnregulatedSectorsDeclareNoDistributionRestrictions(): void
    {
        $stock = (new Stock())->setTicker('FREE');
        $this->assertNull($this->model->getRegulatoryDividendCap($stock, 500_000_000.0), 'An unregulated firm has no dividend cap, which is not the same as a cap of zero.');
        $this->assertNull($this->model->checkBuybackRegulatoryLockout($stock, 500_000_000.0), 'Nor a buyback lockout to check.');
    }
}
