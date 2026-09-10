<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Trait;

use App\Entity\Stock;
use App\Tests\Support\Model\BareStandardModel;
use PHPUnit\Framework\TestCase;

/**
 * The acquisition and divestiture arithmetic every non-financial sector inherits.
 */
final class StandardMaTraitTest extends TestCase
{
    private BareStandardModel $model;

    protected function setUp(): void
    {
        $this->model = new BareStandardModel();
    }

    public function testAcquisitionTypeAndSpendPassThroughUnmodified(): void
    {
        $this->assertSame('horizontal', $this->model->getAcquisitionType('horizontal'), 'A standard corporate does not reinterpret the deal type it is handed.');
        $this->assertSame(500.0, $this->model->applyMaSpendCap(500.0, 1000.0, false, false), 'No sector-specific spend cap applies.');
        $this->assertSame(
            5_000_000_000.0,
            $this->model->applyMaSpendCap(5_000_000_000.0, 100_000_000.0, true, true),
            'Even an empire builder buying far beyond its equity is capped by the engine, not by this model.'
        );
    }

    /**
     * An acquisition blends the target's returns into the acquirer weighted by capital, so the post-deal
     * baseline must land between the two and move toward whichever side carries more capital.
     */
    public function testAcquisitionBlendsBaselineReturnsWeightedByCapital(): void
    {
        $acquirer = (new Stock())->setTicker('BUY');
        $acquirer->setBaselineRoic('0.10');

        // Equal capital on both sides lands exactly halfway.
        $this->model->blendAcquisitionDNA($acquirer, 1_000_000_000.0, 1_000_000_000.0, 0.20, 2_000_000_000.0);
        $this->assertEqualsWithDelta(0.15, (float) $acquirer->getBaselineRoic(), 0.0000001, 'Equal capital blends to the midpoint.');

        // A small bolt-on barely moves a large acquirer.
        $large = (new Stock())->setTicker('BIG');
        $large->setBaselineRoic('0.10');
        $this->model->blendAcquisitionDNA($large, 9_000_000_000.0, 1_000_000_000.0, 0.30, 10_000_000_000.0);
        $this->assertEqualsWithDelta(0.12, (float) $large->getBaselineRoic(), 0.0000001, 'A tenth of the capital moves the blend a tenth of the way.');
        $this->assertGreaterThan(0.10, (float) $large->getBaselineRoic(), 'Buying a better business must improve the blend.');
        $this->assertLessThan(0.30, (float) $large->getBaselineRoic(), 'But it cannot lift the acquirer to the target it bought.');

        // Buying a worse business dilutes returns.
        $diluted = (new Stock())->setTicker('DIL');
        $diluted->setBaselineRoic('0.20');
        $this->model->blendAcquisitionDNA($diluted, 1_000_000_000.0, 1_000_000_000.0, 0.04, 2_000_000_000.0);
        $this->assertLessThan(0.20, (float) $diluted->getBaselineRoic(), 'A dilutive deal must lower the blended baseline.');

        // The blend floors: a catastrophic deal cannot drive the baseline to zero or below.
        $wiped = (new Stock())->setTicker('BAD');
        $wiped->setBaselineRoic('0.02');
        $this->model->blendAcquisitionDNA($wiped, 1_000.0, 1_000_000_000.0, -5.0, 1_000_000_000.0);
        $this->assertSame(0.01, (float) $wiped->getBaselineRoic(), 'The blended baseline floors rather than going negative.');
    }

    /**
     * The capital-proxy divestiture: the seller loses its pro-rata invested capital net of the debt that
     * goes with the division, so selling a debt-laden unit releases less equity than a clean one.
     */
    public function testDivestedEquityIsProRataCapitalNetOfTransferredDebt(): void
    {
        $seller = (new Stock())->setTicker('SELL');

        $this->assertSame(
            200_000_000.0,
            $this->model->calculateDivestedEquity($seller, 0.25, 800_000_000.0, 400_000_000.0, 50_000_000.0, 800_000_000.0, 0.0),
            'With no debt transferred the whole pro-rata capital slice leaves as equity.'
        );
        $this->assertSame(
            50_000_000.0,
            $this->model->calculateDivestedEquity($seller, 0.25, 800_000_000.0, 400_000_000.0, 50_000_000.0, 800_000_000.0, 150_000_000.0),
            'Debt that travels with the division reduces the equity released.'
        );
        $this->assertSame(
            0.0,
            $this->model->calculateDivestedEquity($seller, 0.0, 800_000_000.0, 400_000_000.0, 50_000_000.0, 800_000_000.0, 0.0),
            'Divesting nothing releases nothing.'
        );

        // Shedding liabilities is a balance-sheet operation the standard model leaves to the engine.
        $before = $seller->getWholesaleDebt();
        $this->model->shedDivestedLiabilities($seller, 0.25, 50_000_000.0);
        $this->assertSame($before, $seller->getWholesaleDebt(), 'The standard model does not move liabilities itself.');
    }

    /**
     * Selling the weakest division lifts the returns of what is left, proportionally to how much was sold.
     */
    public function testDivestitureBoostsStructuralEfficiencyInProportionToWhatWasSold(): void
    {
        $seller = (new Stock())->setTicker('TRIM');
        $seller->setBaselineRoic('0.10');
        $this->model->boostStructuralEfficiency($seller, 0.20, 0.50);
        $this->assertEqualsWithDelta(0.10 * (1.0 + 0.10), (float) $seller->getBaselineRoic(), 0.0000001, 'The bump is the baseline scaled by fraction times multiplier.');

        $bigger = (new Stock())->setTicker('TRIM2');
        $bigger->setBaselineRoic('0.10');
        $this->model->boostStructuralEfficiency($bigger, 0.40, 0.50);
        $this->assertGreaterThan((float) $seller->getBaselineRoic(), (float) $bigger->getBaselineRoic(), 'Selling more of the business lifts returns further.');

        $untouched = (new Stock())->setTicker('TRIM3');
        $untouched->setBaselineRoic('0.10');
        $this->model->boostStructuralEfficiency($untouched, 0.0, 0.50);
        $this->assertEqualsWithDelta(0.10, (float) $untouched->getBaselineRoic(), 0.0000001, 'Selling nothing changes nothing.');
    }
}
