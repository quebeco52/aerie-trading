<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use App\Service\Model\Sector\AdvertisingAgencyBusinessModel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class AdvertisingAgencyBusinessModelTest extends TestCase
{
    private AdvertisingAgencyBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new AdvertisingAgencyBusinessModel();
        $this->mathUtility = MathUtility::ownStream(20261024);
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
            mathUtility: $this->mathUtility,
        );

        $this->assertArrayHasKey('media_buying_commissions', $result->streamRevenue);
        $this->assertArrayHasKey('creative_brand_retainers', $result->streamRevenue);
        $this->assertArrayHasKey('martech_consulting', $result->streamRevenue);

        $this->assertGreaterThan(0.0, $result->actualRevenue);
        $this->assertEqualsWithDelta(
            $result->actualRevenue,
            $result->streamRevenue['media_buying_commissions'] + $result->streamRevenue['creative_brand_retainers'] + $result->streamRevenue['martech_consulting'],
            1.0,
        );
    }

    public function testConsumerSentimentAndOutputGapDriveMediaBuyingCommissions(): void
    {
        $stock = new Stock();
        $stock->setTicker('WEAV');
        $stock->setBeta('1.2');

        $recessionMacro = new MacroStateDTO(
            outputGapEma: -0.03,
            consumerSentimentIndexEma: 70.0,
        );

        $boomMacro = new MacroStateDTO(
            outputGapEma: 0.03,
            consumerSentimentIndexEma: 130.0,
        );

        $recessionResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.45,
            fixedCosts: 25_000_000.0,
            baselineVol: 0.0,
            macroState: $recessionMacro,
            mathUtility: $this->mathUtility,
        );

        $boomResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.45,
            fixedCosts: 25_000_000.0,
            baselineVol: 0.0,
            macroState: $boomMacro,
            mathUtility: $this->mathUtility,
        );

        $this->assertGreaterThan(
            $recessionResult->streamRevenue['media_buying_commissions'],
            $boomResult->streamRevenue['media_buying_commissions'],
            'Consumer sentiment and output gap booms must expand media buying commissions.',
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

    public function testTheCycleReachesTheCostBaseThroughTheStreamVolumes(): void
    {
        $stock = (new Stock())->setTicker('LYRE_ACTIVITY')->setBeta('1.0');
        // Confidence falls by its gap loading with the gap, so the residual over the gap is zero.
        $sentiment = MacroConstants::SENTIMENT_TREND_LEVEL + (-0.03 * \App\Tests\Support\SentimentOnTheCycle::POINTS_PER_GAP);
        $recession = new MacroStateDTO(outputGapEma: -0.03, exchangeRateIndexEma: 100.0, consumerSentimentIndexEma: $sentiment);

        $this->assertEqualsWithDelta(0.0, $this->model->getMacroPhysics($stock, $recession)['macro_demand_shift'], 1e-12);

        // Ad spend 1.5 x 1.30 x -0.03 = -0.0585, on media 0.50 + MarTech 0.15 x 0.4 = -0.03276; retainers carry none.
        $adSpend = 1.5 * AdvertisingAgencyBusinessModel::OPERATING_CYCLICALITY * -0.03;
        $expected = $adSpend * (AdvertisingAgencyBusinessModel::MEDIA_BUYING_WEIGHT
            + (AdvertisingAgencyBusinessModel::MARTECH_CONSULTING_WEIGHT * AdvertisingAgencyBusinessModel::MARTECH_AD_SPEND_SHARE));
        $this->assertEqualsWithDelta($expected, $this->model->resolveSectorActivityShift($stock, $recession), 1e-9);
    }

    /**
     * An agency with the industry's typical mix grows at the industry's rate; Lyrebird, light in media buying and heavy
     * in data work, grows faster by its lean times each line's drift.
     */
    public function testTheAgencysMixSetsItsGrowthAgainstTheIndustry(): void
    {
        $typical = $this->model->getSecularGrowthRate((new Stock())->setTicker('NONE'));
        $lyre = $this->model->getSecularGrowthRate((new Stock())->setTicker('LYRE'));

        $lean = ((0.20 - AdvertisingAgencyBusinessModel::MEDIA_BUYING_WEIGHT) * AdvertisingAgencyBusinessModel::MEDIA_BUYING_DRIFT)
            + ((0.30 - AdvertisingAgencyBusinessModel::MARTECH_CONSULTING_WEIGHT) * AdvertisingAgencyBusinessModel::MARTECH_CONSULTING_DRIFT);
        $industry = MacroConstants::TREND_REAL_GROWTH + MacroTransmission::gdpShareDrift(AdvertisingAgencyBusinessModel::SECULAR_SHARE_1997, AdvertisingAgencyBusinessModel::SECULAR_SHARE_2019, FinancialConstants::SECULAR_SHARE_WINDOW_YEARS);
        $this->assertEqualsWithDelta($industry, $typical, 1e-15, 'the measured rate already holds the typical mix\'s drifts');
        $this->assertEqualsWithDelta($lean, $lyre - $typical, 1e-12);
        $this->assertGreaterThan(0.0, $lean);
    }

    /**
     * The weights the quarter splits revenue by are the drifted mix at the quarter's date: media buying gives way to
     * data work as the years pass, and the opening quarter sells the opening mix.
     */
    public function testTheQuartersWeightsFollowTheDriftedMix(): void
    {
        $weightsAt = fn(float $years): array => $this->model->computeActualFinancials((new Stock())->setTicker('LYRE')->setBeta('1.0'), 100_000_000.0, 0.45, 25_000_000.0, 0.12, new MacroStateDTO(totalTime: $years), MathUtility::ownStream(7))->streamZ;

        $opening = $weightsAt(0.0);
        $this->assertEqualsWithDelta(0.20, $opening['weight:media_buying_commissions'], 1e-12);
        $this->assertEqualsWithDelta(0.30, $opening['weight:martech_consulting'], 1e-12);

        $later = $weightsAt(20.0);
        $expected = $this->model->resolveLineShares((new Stock())->setTicker('LYRE'), 20.0);
        $this->assertEqualsWithDelta($expected['media_buying_commissions'], $later['weight:media_buying_commissions'], 1e-12);
        $this->assertEqualsWithDelta($expected['martech_consulting'], $later['weight:martech_consulting'], 1e-12);
        $this->assertLessThan(0.20, $later['weight:media_buying_commissions']);
        $this->assertGreaterThan(0.30, $later['weight:martech_consulting']);
    }

    /**
     * The industry ledger grows the firm on its opening lean, faded; the exact mix-weighted path also gains as the
     * growing line takes a larger share. Over 30 years the two stay within 1% for every agency mix shipped.
     */
    public function testTheLedgersPlanStaysOnTheMixWeightedPath(): void
    {
        $years = 30.0;
        $halfLife = FinancialConstants::SECULAR_EXCESS_HALF_LIFE_YEARS;
        $typical = ['media_buying_commissions' => AdvertisingAgencyBusinessModel::MEDIA_BUYING_WEIGHT, 'creative_brand_retainers' => AdvertisingAgencyBusinessModel::BRAND_RETAINER_WEIGHT, 'martech_consulting' => AdvertisingAgencyBusinessModel::MARTECH_CONSULTING_WEIGHT];
        $level = static function (array $weights) use ($years, $halfLife): float {
            $total = array_sum($weights);
            $sum = 0.0;
            foreach ($weights as $line => $weight) {
                $sum += ($weight / $total) * exp(TimeSeries::fadedExcessIntegral(AdvertisingAgencyBusinessModel::LINE_DRIFTS[$line] ?? 0.0, 0.0, $years, $halfLife));
            }

            return log($sum);
        };
        $lyre = ['media_buying_commissions' => 0.20, 'creative_brand_retainers' => 0.50, 'martech_consulting' => 0.30];

        $exact = $level($lyre) - $level($typical);
        $ledger = TimeSeries::fadedExcessIntegral(TimeSeries::lineMixExcessGrowth($lyre, $typical, AdvertisingAgencyBusinessModel::LINE_DRIFTS), 0.0, $years, $halfLife);
        $this->assertEqualsWithDelta($exact, $ledger, 0.01);
    }
}
