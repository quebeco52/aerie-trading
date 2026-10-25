<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\Distributions;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Model\Sector\BroadcastingBusinessModel;
use App\Service\Politics\Diet\Election;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class BroadcastingBusinessModelTest extends TestCase
{
    private const EXPECTED_REVENUE = 100_000_000.0;
    private const VARIABLE_COST_RATIO = 0.25;
    /** Filed in Year 4 Q2, covering Q1: the first quarter of the campaign year, at its mean nearness to the vote. */
    private const MEAN_CAMPAIGN_TIME = 3.3;
    /** Filed in Year 3 Q1, covering the year before's last quarter: two years from the vote. */
    private const MID_TERM_TIME = 2.05;

    private BroadcastingBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new BroadcastingBusinessModel();
    }

    private static function macro(float $outputGap = 0.0, float $uncertainty = MacroConstants::EPU_BASELINE, float $time = self::MEAN_CAMPAIGN_TIME): MacroStateDTO
    {
        return new MacroStateDTO(totalTime: $time, outputGapEma: $outputGap, policyUncertaintyIndexEma: $uncertainty);
    }

    /**
     * One quarter with every draw at zero unless the dispute draw (the fourth persistent draw) is scripted.
     *
     * @param array<string, float> $momentum
     */
    private function runQuarter(MacroStateDTO $macro, array $momentum = [], ?int $calendarQuarter = null, float $disputeZ = 0.0): ActualFinancialsDTO
    {
        $stock = (new Stock())->setTicker('BROADCAST_TEST')->setBeta('1.00');
        $stock->setEarningsMomentumZ($momentum);
        $draws = 0;
        $season = $calendarQuarter !== null ? $this->model->resolveSeasonalityFactors($stock)[$calendarQuarter] : 1.0;

        return $this->model->computeActualFinancials(
            $stock,
            self::EXPECTED_REVENUE * $season,
            self::VARIABLE_COST_RATIO,
            50_000_000.0,
            0.0,
            $macro,
            new ScriptedDraws(generatePersistentZ: function (float $prev) use (&$draws, $disputeZ): float {
                return ++$draws === 4 ? $disputeZ : 0.0;
            }),
            $calendarQuarter,
        );
    }

    /** What the quarters without a blackout carry so the dark ones leave the expected fees whole. */
    private static function blackoutCompensation(): float
    {
        $loss = BroadcastingBusinessModel::BLACKOUT_DISTRIBUTOR_SHARE * BroadcastingBusinessModel::BLACKOUT_QUARTER_SHARE;

        return 1.0 / (1.0 - (Distributions::standardNormalCdf(BroadcastingBusinessModel::BLACKOUT_Z_SCORE) * $loss));
    }

    public function testACalmQuarterEarnsThePlanMixAndCostsMoveWithEveryDollarSold(): void
    {
        $result = $this->runQuarter(self::macro());
        $compensation = self::blackoutCompensation();

        $this->assertEqualsWithDelta(48_000_000.0 * $compensation, $result->streamRevenue['carriage_fees'], 1.0);
        $this->assertEqualsWithDelta(50_000_000.0 * $compensation, $result->streamRevenue['airtime_advertising'], 1.0);
        $this->assertEqualsWithDelta(2_000_000.0, $result->streamRevenue['political_advertising'], 1.0);
        $this->assertNull($result->eventType);

        // Commissions, the stations' cut and participations are shares of what is sold, so no revenue is booked as
        // costless price.
        $this->assertEqualsWithDelta($result->actualRevenue * self::VARIABLE_COST_RATIO, $result->actualVariableCosts, 1.0);
        $this->assertSame(0.0, $result->priceRevenue);
        $this->assertEqualsWithDelta(1.0, $result->kpis['audience_index'], 1e-12);
        $this->assertEqualsWithDelta(1.0, $result->kpis['carriage_fee_index'], 1e-12);
        $this->assertEqualsWithDelta(1.0, $result->kpis['upfront_price_index'], 1e-12);
    }

    public function testABlackoutDarkensOneDistributorAndTheCalmQuartersCarryItsExpectedLoss(): void
    {
        $result = $this->runQuarter(self::macro(), disputeZ: -3.0);
        $loss = BroadcastingBusinessModel::BLACKOUT_DISTRIBUTOR_SHARE * BroadcastingBusinessModel::BLACKOUT_QUARTER_SHARE;
        $compensation = self::blackoutCompensation();

        $this->assertSame(ShockEvent::CARRIAGE_BLACKOUT, $result->eventType);
        $this->assertEqualsWithDelta(48_000_000.0 * $compensation * (1.0 - $loss), $result->streamRevenue['carriage_fees'], 1.0);
        $this->assertEqualsWithDelta(50_000_000.0 * $compensation * (1.0 - $loss), $result->streamRevenue['airtime_advertising'], 1.0);
        $this->assertEqualsWithDelta(2_000_000.0, $result->streamRevenue['political_advertising'], 1.0);

        // Over the dispute draw's distribution the reach the network sells averages one.
        $probability = Distributions::standardNormalCdf(BroadcastingBusinessModel::BLACKOUT_Z_SCORE);
        $this->assertEqualsWithDelta(1.0, $compensation * (1.0 - ($probability * $loss)), 1e-12);
    }

    public function testARecessionCutsTheGoingRateAtOnceAndTheUpfrontAtTheNextSeason(): void
    {
        $gap = -0.03;
        $calm = $this->runQuarter(self::macro(), calendarQuarter: 0);
        $slump = $this->runQuarter(self::macro(outputGap: $gap), momentum: $calm->streamZ, calendarQuarter: 0);
        $adSpend = MacroTransmission::advertisingSpendShift($gap, 0.0, BroadcastingBusinessModel::OPERATING_CYCLICALITY);

        // The season's upfront was struck in a calm market, so only the airtime sold at the going rate cheapens.
        $scatterOnly = 1.0 + ((1.0 - BroadcastingBusinessModel::UPFRONT_SHARE) * $adSpend);
        $this->assertEqualsWithDelta($scatterOnly, $slump->streamRevenue['airtime_advertising'] / $calm->streamRevenue['airtime_advertising'], 1e-9);
        $this->assertEqualsWithDelta($calm->streamRevenue['carriage_fees'], $slump->streamRevenue['carriage_fees'], 1.0);
        $this->assertEqualsWithDelta($slump->actualRevenue * self::VARIABLE_COST_RATIO, $slump->actualVariableCosts, 1.0);

        // The spring upfront is struck in the slump; it prices the season that opens in the third quarter.
        $upfront = $this->runQuarter(self::macro(outputGap: $gap), momentum: $slump->streamZ, calendarQuarter: BroadcastingBusinessModel::UPFRONT_CALENDAR_QUARTER);
        $this->assertEqualsWithDelta(1.0, $upfront->kpis['upfront_price_index'], 1e-12);
        $calmSummer = $this->runQuarter(self::macro(), calendarQuarter: 2);
        $summer = $this->runQuarter(self::macro(outputGap: $gap), momentum: $upfront->streamZ, calendarQuarter: 2);
        $this->assertEqualsWithDelta(1.0 + $adSpend, $summer->kpis['upfront_price_index'], 1e-12);
        $this->assertEqualsWithDelta(1.0 + $adSpend, $summer->streamRevenue['airtime_advertising'] / $calmSummer->streamRevenue['airtime_advertising'], 1e-9);
    }

    public function testNewsViewingRisesWithUncertaintyAndCarriageRepricesASliceAQuarter(): void
    {
        $uncertainty = MacroConstants::EPU_BASELINE * exp(0.4);
        $audience = BroadcastingBusinessModel::NEWS_AIRTIME_SHARE * BroadcastingBusinessModel::NEWS_AUDIENCE_UNCERTAINTY_ELASTICITY * 0.4;
        $calm = $this->runQuarter(self::macro());

        $first = $this->runQuarter(self::macro(uncertainty: $uncertainty), momentum: $calm->streamZ);
        $this->assertEqualsWithDelta(1.0 + $audience, $first->kpis['audience_index'], 1e-12);
        $this->assertEqualsWithDelta(1.0 + $audience, $first->streamRevenue['airtime_advertising'] / $calm->streamRevenue['airtime_advertising'], 1e-9);
        // Every contract delivering this quarter was signed before the news turned.
        $this->assertEqualsWithDelta(1.0, $first->kpis['carriage_fee_index'], 1e-12);

        $second = $this->runQuarter(self::macro(uncertainty: $uncertainty), momentum: $first->streamZ);
        $renewal = BroadcastingBusinessModel::CARRIAGE_AUDIENCE_PASS_THROUGH * $audience;
        $this->assertEqualsWithDelta(1.0 + ($renewal / BroadcastingBusinessModel::CARRIAGE_CONTRACT_QUARTERS), $second->kpis['carriage_fee_index'], 1e-12);
    }

    public function testCampaignMoneyArrivesInTheYearBeforeTheVoteAndAveragesItsWeight(): void
    {
        $this->assertSame(0.0, $this->runQuarter(self::macro(time: self::MID_TERM_TIME))->streamRevenue['political_advertising']);

        // Filed just after the vote, the report covers the campaign's last quarter, not the talks.
        $eve = $this->runQuarter(self::macro(time: 4.05));
        $this->assertEqualsWithDelta(2_000_000.0 * 0.875 / Election::MEAN_CAMPAIGN_PROXIMITY, $eve->streamRevenue['political_advertising'], 1.0);

        $term = 0.0;
        for ($quarter = 1; $quarter <= 16; ++$quarter) {
            $term += $this->runQuarter(self::macro(time: ($quarter + 0.1) / 4.0))->streamRevenue['political_advertising'];
        }
        $this->assertEqualsWithDelta(2_000_000.0, $term / 16.0, 1.0);
    }

    public function testTheSeasonSitsOnAirtimeAlone(): void
    {
        $stock = (new Stock())->setTicker('BROADCAST_TEST');
        $season = $this->model->resolveSeasonalityFactors($stock);
        $this->assertEqualsWithDelta(4.0, array_sum($season), 1e-12);
        $this->assertEqualsWithDelta(BroadcastingBusinessModel::SEASONALITY_FACTORS, $season, 1e-12);

        $carriage = [];
        foreach ([0, 1, 2, 3] as $quarter) {
            $result = $this->runQuarter(self::macro(), calendarQuarter: $quarter);
            $carriage[] = $result->streamRevenue['carriage_fees'];
            $plan = (($result->streamRevenue['carriage_fees'] + $result->streamRevenue['airtime_advertising']) / self::blackoutCompensation())
                + $result->streamRevenue['political_advertising'];
            $this->assertEqualsWithDelta(self::EXPECTED_REVENUE * $season[$quarter], $plan, 1.0);
        }
        $this->assertEqualsWithDelta($carriage[0], max($carriage), 1.0);
        $this->assertEqualsWithDelta($carriage[0], min($carriage), 1.0);
    }
}
