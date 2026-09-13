<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\AdvertisingAgencyBusinessModel;
use PHPUnit\Framework\TestCase;

class AdvertisingAgencyBusinessModelTest extends TestCase
{
    private AdvertisingAgencyBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new AdvertisingAgencyBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testTriStreamArchitectureAndShares(): void
    {
        $stock = new Stock();
        $stock->setTicker('WEAV');
        $stock->setBeta('1.2');

        $macro = new MacroStateDTO();

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.45,
            fixedCosts: 25_000_000.0,
            baselineVol: 0.12,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('media_buying_commissions', $result->streamRevenue);
        $this->assertArrayHasKey('creative_brand_retainers', $result->streamRevenue);
        $this->assertArrayHasKey('martech_consulting', $result->streamRevenue);

        $this->assertGreaterThan(0.0, $result->actualRevenue);
        $this->assertEqualsWithDelta(
            $result->actualRevenue,
            $result->streamRevenue['media_buying_commissions'] + $result->streamRevenue['creative_brand_retainers'] + $result->streamRevenue['martech_consulting'],
            1.0
        );
    }

    public function testConsumerSentimentAndOutputGapDriveMediaBuyingCommissions(): void
    {
        $stock = new Stock();
        $stock->setTicker('WEAV');
        $stock->setBeta('1.2');

        $recessionMacro = new MacroStateDTO(
            outputGapEma: -0.03,
            consumerSentimentIndexEma: 70.0
        );

        $boomMacro = new MacroStateDTO(
            outputGapEma: 0.03,
            consumerSentimentIndexEma: 130.0
        );

        $recessionResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.45,
            fixedCosts: 25_000_000.0,
            baselineVol: 0.0,
            macroState: $recessionMacro,
            mathUtility: $this->mathUtility
        );

        $boomResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.45,
            fixedCosts: 25_000_000.0,
            baselineVol: 0.0,
            macroState: $boomMacro,
            mathUtility: $this->mathUtility
        );

        $this->assertGreaterThan(
            $recessionResult->streamRevenue['media_buying_commissions'],
            $boomResult->streamRevenue['media_buying_commissions'],
            'Consumer sentiment and output gap booms must expand media buying commissions.'
        );
    }


    /**
     * This model applies the cycle per stream in calculateSectorPhysics, so the parent's generic demand shift
     * must be zeroed or the output gap reaches revenue twice: once in the expectation and again in the streams.
     */
    public function testTheGenericDemandShiftIsZeroedSoTheOutputGapIsNotCountedTwice(): void
    {
        $physics = $this->model->getMacroPhysics((new Stock())->setTicker('LYRE_GAP'), new MacroStateDTO(outputGapEma: 0.03));

        $this->assertSame(0.0, $physics['macro_demand_shift']);
        $this->assertArrayHasKey('pricing_power_multiplier', $physics, 'Only the demand shift is nullified; pricing physics still flow from the parent.');
    }
}
