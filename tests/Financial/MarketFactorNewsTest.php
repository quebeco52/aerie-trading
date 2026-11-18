<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\StochasticProcesses;
use App\Service\Math\Valuation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The market factor prices the economy's news (AssetMarketSubsystem): the stream of it in the factor's draw
 * (economicNewsZ(), the demand disturbance's draw and the step in the mainland's quarterly surprise, each weighed by
 * what it does to the output gap) and its events as a repricing, both at one price per point of the gap's expected
 * path. The market moves when a shock lands, and the factor keeps the variance its budget gives it.
 */
#[Group('unit')]
final class MarketFactorNewsTest extends TestCase
{
    private const DT = 1.0 / 360.0;
    private const TICKS = 200_000;

    /**
     * The stream of news takes its loading's square of the shock's variance and the regime and tail legs keep the rest
     * of theirs, so with the events' share the factor's budget is unchanged at every horizon. The regime leg's per-tick
     * variance is small (its persistence supplies its share over the weeks it trends), so the shock's per-tick variance
     * is that leg's and the tail's.
     */
    public function testTheNewsTakesItsShareOfTheShocksVariance(): void
    {
        $math = MathUtility::ownStream(20261030);
        $subsystem = new AssetMarketSubsystem($math);
        $state = new MacroState();

        $sumZ = $sumZz = $sumN = $sumNn = $sumZn = 0.0;
        for ($tick = 0; $tick < self::TICKS; $tick++) {
            $state->demandNewsZ = $math->generateStandardNormal();
            $state->mainlandNewsZ = $math->generateStandardNormal();
            $news = AssetMarketSubsystem::economicNewsZ($state);
            $subsystem->updateSystemicMarketFactor($state, self::DT);

            $z = $state->marketZ;
            $sumZ += $z;
            $sumZz += $z * $z;
            $sumN += $news;
            $sumNn += $news * $news;
            $sumZn += $z * $news;
        }

        $n = self::TICKS;
        $varZ = ($sumZz / $n) - (($sumZ / $n) ** 2);
        $varN = ($sumNn / $n) - (($sumN / $n) ** 2);
        $covariance = ($sumZn / $n) - (($sumZ / $n) * ($sumN / $n));

        $loading = AssetMarketSubsystem::drawNewsLoading();
        $noiseShare = 1.0 - (AssetMarketSubsystem::MARKET_FACTOR_NEWS_LOADING ** 2);
        $regimeScale = StochasticProcesses::calculatePersistenceVarianceScale(exp(-self::DT / AssetMarketSubsystem::MARKET_FACTOR_DECAY_TAU_YEARS));
        $legsVariance = (AssetMarketSubsystem::MARKET_FACTOR_REGIME_VARIANCE_SHARE * ($regimeScale ** 2)) + (1.0 - AssetMarketSubsystem::MARKET_FACTOR_REGIME_VARIANCE_SHARE);

        $this->assertEqualsWithDelta(1.0, $varN, 0.02, 'The news is a standard normal,');
        $this->assertEqualsWithDelta($loading, $covariance, 0.01, 'the shock moves with it at the factor\'s loading,');
        $this->assertEqualsWithDelta(($loading ** 2) + ($noiseShare * $legsVariance), $varZ, 0.02, 'and the legs keep the rest of the variance.');
    }

    /**
     * The fresh leg the variance shock loads on carries the news, so bad news raises volatility as a falling market
     * does: the leg is the news and the tail, at unit variance, and moves with the news at the loading over its root.
     */
    public function testBadNewsRaisesVolatilityThroughTheFreshLeg(): void
    {
        $math = MathUtility::ownStream(20261031);
        $subsystem = new AssetMarketSubsystem($math);
        $state = new MacroState();

        $sumU = $sumUu = $sumN = $sumUn = 0.0;
        for ($tick = 0; $tick < self::TICKS; $tick++) {
            $state->demandNewsZ = $math->generateStandardNormal();
            $state->mainlandNewsZ = $math->generateStandardNormal();
            $news = AssetMarketSubsystem::economicNewsZ($state);
            $fresh = $subsystem->updateSystemicMarketFactor($state, self::DT)['returnInnovation'];

            $sumU += $fresh;
            $sumUu += $fresh * $fresh;
            $sumN += $news;
            $sumUn += $fresh * $news;
        }

        $n = self::TICKS;
        $loading = AssetMarketSubsystem::drawNewsLoading();
        $freshShare = ($loading ** 2) + ((1.0 - (AssetMarketSubsystem::MARKET_FACTOR_NEWS_LOADING ** 2)) * (1.0 - AssetMarketSubsystem::MARKET_FACTOR_REGIME_VARIANCE_SHARE));

        $this->assertEqualsWithDelta(1.0, ($sumUu / $n) - (($sumU / $n) ** 2), 0.02, 'The fresh leg has unit variance,');
        $this->assertEqualsWithDelta($loading / sqrt($freshShare), ($sumUn / $n) - (($sumU / $n) * ($sumN / $n)), 0.01, 'and carries the news.');
    }

    /** Good news lifts the market from either economy, and the mix of the two keeps unit variance. */
    public function testEachEconomysNewsLiftsTheMarketAtItsWeight(): void
    {
        $state = new MacroState();
        $state->demandNewsZ = 1.0;
        $domestic = AssetMarketSubsystem::economicNewsZ($state);
        $state->demandNewsZ = 0.0;
        $state->mainlandNewsZ = 1.0;
        $mainland = AssetMarketSubsystem::economicNewsZ($state);

        $this->assertGreaterThan(0.0, $domestic, 'A demand surprise lifts the market,');
        $this->assertGreaterThan(0.0, $mainland, 'and so does a mainland one.');
        $this->assertEqualsWithDelta(1.0, ($domestic ** 2) + ($mainland ** 2), 1e-12, 'Their weights keep the mix at unit variance.');
        // The mainland's surprise carries the regional factor on its hump-shaped path; the District's disturbance
        // reaches the gap only as demand's own pull lets it, so it is the smaller part of the news.
        $this->assertEqualsWithDelta(0.15, $domestic ** 2, 0.03);
    }

    /**
     * An event is priced at the factor's price per point of the gap's expected path and taken in over about a week:
     * two markets on the same draws differ by the repricing released so far, and it reaches prices through the
     * market-wide jump multiplier.
     */
    public function testAnEventRepricesTheMarketAtThePriceOfItsNewsOverAboutAWeek(): void
    {
        $quiet = new MacroState();
        $struck = new MacroState();
        $quietMarket = new AssetMarketSubsystem(MathUtility::ownStream(7));
        $struckMarket = new AssetMarketSubsystem(MathUtility::ownStream(7));
        $news = -0.01;
        $struck->economicEventNews = $news;

        $quietMarket->updateSystemicMarketFactor($quiet, self::DT);
        $struckMarket->updateSystemicMarketFactor($struck, self::DT);
        $repricing = AssetMarketSubsystem::pricePerGapPath() * $news;
        $firstDay = $repricing * (1.0 - exp(-self::DT / AssetMarketSubsystem::EVENT_REPRICING_TAU_YEARS));

        $this->assertSame(0.0, $struck->economicEventNews, 'The market factor reads the news once.');
        $this->assertEqualsWithDelta($firstDay, log($struck->marketJumpMultiplier / $quiet->marketJumpMultiplier), 1e-12, 'The day\'s share reaches prices as a market-wide jump,');
        $this->assertEqualsWithDelta($firstDay, $struck->equityValuationShift - $quiet->equityValuationShift, 1e-12, 'and equity value with it.');

        $fade = exp(-Valuation::equityValuationShiftFade() * self::DT);
        $expected = $firstDay;
        for ($day = 1; $day < 60; $day++) {
            $quietMarket->updateSystemicMarketFactor($quiet, self::DT);
            $struckMarket->updateSystemicMarketFactor($struck, self::DT);
            $expected = ($expected * $fade) + ($repricing - $firstDay) * (1.0 - exp(-self::DT / AssetMarketSubsystem::EVENT_REPRICING_TAU_YEARS)) * exp(-($day - 1) * self::DT / AssetMarketSubsystem::EVENT_REPRICING_TAU_YEARS);
        }

        $this->assertLessThan(-0.15, $repricing, 'One point of the gap\'s expected path is a fall worth the name.');
        $this->assertEqualsWithDelta($expected, $struck->equityValuationShift - $quiet->equityValuationShift, 1e-9, 'The rest lands day by day,');
        $this->assertEqualsWithDelta($repricing, $struck->equityValuationShift - $quiet->equityValuationShift, 0.05 * abs($repricing), 'and two months on the event is nearly all priced.');
    }

    /**
     * The news keeps its loading's square of the factor's variance whether it comes as a draw or an event: the stream's
     * loading and the events' variance at the price per point add back to it.
     */
    public function testTheStreamAndTheEventsShareTheNewsBudget(): void
    {
        $anchor = MacroConstants::MACRO_VOL_BASE_ANCHOR;
        $eventShare = (AssetMarketSubsystem::pricePerGapPath() ** 2) * AssetMarketSubsystem::eventNewsVariance() / ($anchor ** 2);

        $this->assertGreaterThan(0.2, $eventShare, 'The events are a real part of the news,');
        $this->assertEqualsWithDelta(AssetMarketSubsystem::MARKET_FACTOR_NEWS_LOADING ** 2, (AssetMarketSubsystem::drawNewsLoading() ** 2) + $eventShare, 1e-12, 'and with the stream they keep the news budget.');
    }
}
