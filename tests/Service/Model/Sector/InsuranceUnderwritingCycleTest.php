<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\InsuranceBusinessModel;
use App\Service\Model\Sector\RetailInsuranceBusinessModel;
use App\Service\Model\StreamContext;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The underwriting cycle as a CAPACITY cycle (Winter 1994, Gron 1994), and catastrophe seasonality.
 *
 * The distinction that matters: a catastrophe destroys surplus, capacity withdraws, and rates stay hard
 * for years after the capital has returned — the discipline lag the cycle is named for. Modelling it off
 * this quarter's surplus alone would give an instantaneous response and no cycle at all.
 */
#[Group('unit')]
final class InsuranceUnderwritingCycleTest extends TestCase
{
    private const REGIME_KEY = StreamContext::REGIME_STATE_PREFIX . InsuranceBusinessModel::REGIME_HARD_MARKET;

    /** Q3 carries the wind season; Q4 is the quiet quarter. */
    private const Q3_TIME = 0.50;
    private const Q4_TIME = 0.75;

    private function makeStock(float $hardMarketQuarters = 0.0): Stock
    {
        $stock = new Stock();
        $stock->setTicker('ZZZZ');
        $stock->setBeta('0.6');
        if ($hardMarketQuarters > 0.0) {
            $stock->setEarningsMomentumZ([self::REGIME_KEY => $hardMarketQuarters]);
        }

        return $stock;
    }

    private function makeMacro(float $totalTime = 0.0): MacroStateDTO
    {
        return MacroStateDTO::fromArray([
            'total_time'   => $totalTime,
            'inflation_ema' => 0.02,
            // Policy rate at the neutral rate leaves the cash-flow-underwriting discount at zero,
            // isolating the capacity cycle.
            'policy_rate'  => MacroConstants::BASE_NATURAL_RATE + MacroConstants::TARGET_INFLATION,
            'gdp_growth'   => 0.02,
            'credit_spread' => 0.015,
        ]);
    }

    /** A quiet market prices at par; a fresh hard market prices above it. */
    public function testHardMarketLiftsPremiumRatesAndQuietMarketDoesNot(): void
    {
        $model = new InsuranceBusinessModel();

        $soft = $model->getMacroPhysics($this->makeStock(), $this->makeMacro());
        $hard = $model->getMacroPhysics($this->makeStock(1.0), $this->makeMacro());

        $this->assertEqualsWithDelta(1.0, $soft['pricing_power_multiplier'], 1e-9, 'With no capital shock and float yields at baseline, rates sit at par.');
        $this->assertGreaterThan(
            $soft['pricing_power_multiplier'],
            $hard['pricing_power_multiplier'],
            'Withdrawn capacity has to show up as higher premium rates.',
        );
    }

    /**
     * Cash-flow underwriting reads the measured neutral rate: at neutral the book prices at par, and the discount
     * is symmetric around it. It once read a fixed 2% against a 3.5% neutral, pricing every trend quarter 2.25% low.
     */
    public function testFloatYieldDiscountIsCentredOnTheNeutralRate(): void
    {
        $model = new InsuranceBusinessModel();
        $neutral = MacroConstants::BASE_NATURAL_RATE + MacroConstants::TARGET_INFLATION;
        $rate = fn(float $policyRate) => $model->getMacroPhysics($this->makeStock(), MacroStateDTO::fromArray([
            'inflation_ema' => 0.02,
            'policy_rate'   => $policyRate,
            'gdp_growth'    => 0.02,
            'credit_spread' => 0.015,
        ]))['pricing_power_multiplier'];

        $this->assertEqualsWithDelta(1.0, $rate($neutral), 1e-9);
        $this->assertEqualsWithDelta(-0.01 * InsuranceBusinessModel::SOFT_MARKET_CYCLE_BETA, $rate($neutral + 0.01) - 1.0, 1e-9, 'High float yields soften rates.');
        $this->assertEqualsWithDelta(0.01 * InsuranceBusinessModel::SOFT_MARKET_CYCLE_BETA, $rate($neutral - 0.01) - 1.0, 1e-9, 'Low float yields harden them.');
    }

    /** Rates erode as capital returns, even while the regime is still running. */
    public function testUpliftDecaysAsCapacityRebuilds(): void
    {
        $model = new InsuranceBusinessModel();

        $onset = $model->getMacroPhysics($this->makeStock(1.0), $this->makeMacro())['pricing_power_multiplier'];
        $mature = $model->getMacroPhysics($this->makeStock(5.0), $this->makeMacro())['pricing_power_multiplier'];
        $exhausted = $model->getMacroPhysics($this->makeStock(20.0), $this->makeMacro())['pricing_power_multiplier'];

        $this->assertGreaterThan($mature, $onset, 'The steepest rate increases come right after the loss.');
        $this->assertGreaterThan(1.0, $mature, 'Rates stay above par for years — that lag is the cycle.');
        $this->assertEqualsWithDelta(1.0, $exhausted, 1e-9, 'Once capital is fully back the uplift is gone.');
    }

    /** A book in a hard market's first quarter, earning from par. */
    private function hardMarketFromPar(float $quarters, float $earned = 1.0, string $ticker = 'ZZZZ'): Stock
    {
        return $this->makeStock($quarters)->setTicker($ticker)->setEarningsMomentumZ([
            self::REGIME_KEY => $quarters,
            InsuranceBusinessModel::STATE_EARNED_RATE_LEVEL => $earned,
        ]);
    }

    /**
     * The book earns a rate change over half a policy term: in the first hard quarter it earns that share of the
     * uplift, not all of it. Struck at once, the uplift took a carrier's combined ratio from 95 to 78 in the quarter.
     */
    public function testHardMarketIsEarnedOverHalfATerm(): void
    {
        $earned = (new InsuranceBusinessModel())->getMacroPhysics($this->hardMarketFromPar(1.0), $this->makeMacro())['pricing_power_multiplier'];

        $this->assertEqualsWithDelta(
            InsuranceBusinessModel::HARD_MARKET_PRICING_UPLIFT * (1.0 - exp(-0.25 / InsuranceBusinessModel::EARNED_RATE_LAG_YEARS)),
            $earned - 1.0,
            1e-9,
        );
    }

    /** Earning spreads a hard market's extra premium out; it does not take any away. */
    public function testEarnedUpliftPreservesTheEpisodeArea(): void
    {
        $model = new InsuranceBusinessModel();
        $earned = 1.0;
        $earnedArea = 0.0;
        $writtenArea = 0.0;
        for ($quarter = 1; $quarter <= 60; ++$quarter) {
            $regime = $quarter <= 9 ? (float) $quarter : 0.0;
            $written = $model->getMacroPhysics($this->makeStock($regime), $this->makeMacro())['pricing_power_multiplier'];
            $earned = $model->getMacroPhysics($this->hardMarketFromPar($regime, $earned), $this->makeMacro())['pricing_power_multiplier'];
            $writtenArea += $written - 1.0;
            $earnedArea += $earned - 1.0;
        }

        $this->assertGreaterThan(1.0, $writtenArea);
        $this->assertEqualsWithDelta($writtenArea, $earnedArea, 1e-6);
    }

    /** The decision to write is made at the rate on offer, whatever the in-force book is still earning. */
    public function testUnderwritingDecisionReadsTheWrittenRate(): void
    {
        $model = new InsuranceBusinessModel();
        $share = fn(float $earned): float => $model->resolveTargetWrittenShare(
            $this->hardMarketFromPar(1.0, $earned)->setTotalEquity('600000000000'),
            $this->makeMacro(),
            new MathUtility(),
            600_000_000_000.0,
        );

        $this->assertEqualsWithDelta($share(1.0), $share(1.2), 1e-12);
    }

    /** Reading the rate does not age it; the physics carries the level the quarter priced at. */
    public function testGetMacroPhysicsDoesNotAgeTheEarnedRate(): void
    {
        $model = new InsuranceBusinessModel();
        $stock = $this->hardMarketFromPar(1.0)->setTotalEquity('600000000000');
        $first = $model->getMacroPhysics($stock, $this->makeMacro())['pricing_power_multiplier'];
        $this->assertSame($first, $model->getMacroPhysics($stock, $this->makeMacro())['pricing_power_multiplier']);

        $math = new ScriptedDraws();
        $result = $model->computeActualFinancials($stock, 50_000_000_000.0, 0.60, 2_000_000_000.0, 0.15, $this->makeMacro(), $math);

        $this->assertEqualsWithDelta($first, $result->streamZ[InsuranceBusinessModel::STATE_EARNED_RATE_LEVEL], 1e-12);
    }

    /** The capacity cycle hardens property-casualty rates; a composite's life book is priced apart from it. */
    public function testLifeBookDoesNotHarden(): void
    {
        $composite = new RetailInsuranceBusinessModel();
        $lifeShare = $composite->resolveLifePremiumShare($this->makeStock()->setTicker('DOVE'));
        $mono = (new InsuranceBusinessModel())->getMacroPhysics($this->hardMarketFromPar(1.0), $this->makeMacro())['pricing_power_multiplier'];
        $mixed = $composite->getMacroPhysics($this->hardMarketFromPar(1.0, 1.0, 'DOVE'), $this->makeMacro())['pricing_power_multiplier'];

        $this->assertGreaterThan(0.0, $lifeShare);
        $this->assertEqualsWithDelta((1.0 - $lifeShare) * ($mono - 1.0), $mixed - 1.0, 1e-9);
    }

    /**
     * A loss that exhausts the carrier's catastrophe retention puts it into the hard regime, even with its
     * capital intact and the district burden short of the market-wide threshold.
     */
    public function testCoverAttachmentStartsTheHardMarket(): void
    {
        // Own claim draw -3.0 in a quarter with 2.5 times its average district losses: past the retention, under the
        // threshold.
        $attached = $this->runQuarter(-3.0, 2.5);
        $this->assertSame(ShockEvent::REINSURANCE_ATTACHMENT_BREACH, $attached->eventType);
        $this->assertGreaterThan(0.0, $attached->streamZ[self::REGIME_KEY] ?? 0.0, 'An attached cover must turn the market hard.');

        // The same large loss in an average year stays inside the retention and leaves the market alone: the
        // firm's own bad quarter is not a market event.
        $retained = $this->runQuarter(-3.0, 1.0);
        $this->assertNotSame(ShockEvent::REINSURANCE_ATTACHMENT_BREACH, $retained->eventType);
        $this->assertSame(0.0, (float) ($retained->streamZ[self::REGIME_KEY] ?? 0.0));
    }

    /**
     * The wind season reaches claims through the district losses, whose arrivals already follow the calendar.
     * The same multiple of a quarter's average losses and the same draw must cost the same in any quarter: a second,
     * firm-level season counted it twice.
     */
    public function testTheSeasonReachesClaimsOnlyThroughTheDistrictBurden(): void
    {
        $windSeason = $this->runQuarter(-2.0, 1.5, self::Q3_TIME);
        $quietSeason = $this->runQuarter(-2.0, 1.5, self::Q4_TIME);

        $this->assertEqualsWithDelta($quietSeason->actualVariableCosts, $windSeason->actualVariableCosts, 1e-3);
        $this->assertEqualsWithDelta($quietSeason->actualRevenue, $windSeason->actualRevenue, 1e-6, 'Premiums written are not seasonal.');
    }

    /** Seasonal multipliers must average to one, or the calendar would change annual loss expectancy. */
    public function testCatastropheSeasonalityIsNeutralOverTheYear(): void
    {
        $this->assertEqualsWithDelta(
            4.0,
            array_sum(CommodityLogisticsSubsystem::CATASTROPHE_SEASONALITY),
            1e-9,
            'The season redistributes losses across the year, it does not add any.',
        );
        $this->assertCount(4, CommodityLogisticsSubsystem::CATASTROPHE_SEASONALITY);
    }

    /** One quarter for a carrier holding three years of its book in surplus, so no capital trigger fires. */
    private function runQuarter(float $claimDraw, float $burden, float $totalTime = self::Q4_TIME): \App\DTO\ActualFinancialsDTO
    {
        $model = new InsuranceBusinessModel();
        // Firm factor, revenue Z, then the claim draw.
        $math = new ScriptedDraws(generateStandardNormal: [0.0, 0.0, $claimDraw]);

        $stock = $this->makeStock();
        $stock->setTotalEquity('600000000000');
        $window = DistrictCatastropheWindow::prime($stock, $burden, $totalTime);
        $macro = MacroStateDTO::fromArray([
            'total_time' => $totalTime,
            'inflation_ema' => 0.02,
            'policy_rate' => InsuranceBusinessModel::DEFAULT_POLICY_RATE_FALLBACK,
            'catastrophe_loss_index' => $window['catastropheLossIndex'],
        ]);

        return $model->computeActualFinancials($stock, 50_000_000_000.0, 0.60, 2_000_000_000.0, 0.15, $macro, $math);
    }

    /** The quarter is derived from elapsed simulation time, matching what the earnings engine counts. */
    public function testCalendarQuarterTracksElapsedSimulationTime(): void
    {
        foreach ([[0.0, 0], [0.25, 1], [0.5, 2], [0.75, 3], [1.0, 0], [1.25, 1]] as [$totalTime, $expected]) {
            $this->assertSame(
                $expected,
                (new MacroStateDTO(totalTime: $totalTime))->calendarQuarter(),
                sprintf('t=%.2f years should be quarter index %d', $totalTime, $expected),
            );
        }
    }
}
