<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Data\ManagementStyle;
use App\Entity\Stock;
use App\Service\Corporate\ManagementSuccessionEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Management turnover is the mechanism behind Bertrand & Schoar (2003) rather than an ornament on it: the
 * style only means something if it can arrive, persist and be taken away again.
 */
#[AllowMockObjectsWithoutExpectations]
final class ManagementSuccessionEngineTest extends TestCase
{
    private MarketEventPublisher&Stub $publisher;

    protected function setUp(): void
    {
        $this->publisher = $this->createStub(MarketEventPublisher::class);
        $this->publisher->method('publish')->willReturn([]);
    }

    private function engine(MathUtility $mathUtility): ManagementSuccessionEngine
    {
        return new ManagementSuccessionEngine($this->publisher, $mathUtility);
    }

    private function firm(ManagementStyle $style, float $tenure): Stock
    {
        $stock = new Stock();
        $stock->setTicker('GOVN');
        $stock->setName('Governance Corp');
        $stock->setManagementStyle($style);
        $stock->setCeoTenureYears($tenure);

        return $stock;
    }

    /** Calibration: the hazard has to land on the turnover rates the literature actually measures. */
    public function testHazardMatchesTheEmpiricalTurnoverRates(): void
    {
        $engine = $this->engine(new MathUtility());
        $settled = ManagementSuccessionEngine::GRACE_PERIOD_YEARS + 1.0;

        // A firm earning exactly its cost of capital: baseline departures plus the ~3% unconditional
        // forced rate, which together put median tenure in the 7-8 year range CEOs actually serve.
        $atCostOfCapital = $engine->resolveAnnualHazard($settled, 0.0);
        $this->assertGreaterThan(0.08, $atCostOfCapital);
        $this->assertLessThan(0.12, $atCostOfCapital);
        $this->assertGreaterThan(6.0, log(2.0) / $atCostOfCapital);
        $this->assertLessThan(9.0, log(2.0) / $atCostOfCapital);

        // Weisbach (1988): the board's sensitivity to performance is the observable part of governance.
        $destroyingValue = $engine->resolveAnnualHazard($settled, -0.08);
        $this->assertGreaterThan(0.12, $destroyingValue, 'A firm 800bps below its cost of capital loses its manager at a worst-decile rate.');
        $this->assertLessThan(ManagementSuccessionEngine::MAX_ANNUAL_HAZARD + 1e-9, $destroyingValue);

        // A manager earning well above the cost of capital is left alone.
        $this->assertLessThan(
            $atCostOfCapital,
            $engine->resolveAnnualHazard($settled, 0.10),
            'Economic profit has to lower the hazard, not merely fail to raise it.'
        );
    }

    /** A new manager is not dismissed for the numbers their predecessor left behind. */
    public function testGracePeriodSuppressesTheForcedComponentEntirely(): void
    {
        $engine = $this->engine(new MathUtility());

        $this->assertSame(
            ManagementSuccessionEngine::BASE_DEPARTURE_HAZARD,
            $engine->resolveAnnualHazard(0.5, -0.20),
            'Inside the grace period only the baseline applies, however bad the inherited spread.'
        );
        $this->assertGreaterThan(
            ManagementSuccessionEngine::BASE_DEPARTURE_HAZARD,
            $engine->resolveAnnualHazard(ManagementSuccessionEngine::GRACE_PERIOD_YEARS + 0.01, -0.20)
        );
    }

    /**
     * The hazard is quoted per year and consumed per tick, so it must survive a change of tick rate — the
     * same rate has to produce the same turnover whether the ticker runs 252 or 14,400 times a year.
     */
    public function testTurnoverRateIsInvariantToTheTickRate(): void
    {
        $engine = $this->engine(new MathUtility());
        $hazard = $engine->resolveAnnualHazard(5.0, 0.0);

        foreach ([252, 1440, 14400] as $ticksPerYear) {
            $dt = 1.0 / $ticksPerYear;
            $perTick = 1.0 - exp(-$hazard * $dt);
            $survivalOverAYear = (1.0 - $perTick) ** $ticksPerYear;

            $this->assertEqualsWithDelta(exp(-$hazard), $survivalOverAYear, 1e-9);
        }
    }

    /** The persistence Bertrand & Schoar measured: an orderly handover mostly keeps the house style. */
    public function testUnforcedSuccessionUsuallyContinuesTheHouseStyle(): void
    {
        $continues = $this->engine($this->drawing([0.10]));
        $this->assertSame(ManagementStyle::Fortress, $continues->drawSuccessor(ManagementStyle::Fortress, false));

        // Past the continuity threshold the seat goes to someone else, never back to the incumbent.
        $drifts = $this->engine($this->drawing([0.99, 0.0]));
        $this->assertNotSame(ManagementStyle::Fortress, $drifts->drawSuccessor(ManagementStyle::Fortress, false));
    }

    /**
     * A dismissal is the board correcting, and it corrects toward the opposite failing: discipline after
     * capital was spent below its cost, growth after the firm was run quietly into stagnation.
     */
    public function testBoardsCorrectTowardTheOppositeFailing(): void
    {
        $corrects = $this->engine($this->drawing([0.10, 0.0]));
        $this->assertSame(
            ManagementStyle::Steward,
            $corrects->drawSuccessor(ManagementStyle::EmpireBuilder, true),
            'Capital went out below its cost, so the mandate is to stop and return it.'
        );

        $seeksGrowth = $this->engine($this->drawing([0.10, 0.0]));
        $this->assertSame(
            ManagementStyle::EmpireBuilder,
            $seeksGrowth->drawSuccessor(ManagementStyle::Steward, true),
            'A custodian that stopped growing is answered with someone told to grow.'
        );

        // A board that could not agree on a direction hires a safe pair of hands.
        $hedges = $this->engine($this->drawing([0.99]));
        $this->assertSame(ManagementStyle::Operator, $hedges->drawSuccessor(ManagementStyle::EmpireBuilder, true));
    }

    /** End to end: the seat changes hands, tenure restarts, and the event carries no invented price shock. */
    public function testSuccessionRestartsTenureAndStrikesNoPriceShock(): void
    {
        // Draws in order: the hazard fires, the exit is attributed to the forced component, the board
        // corrects, and the first of the corrective styles is drawn.
        $stock = $this->firm(ManagementStyle::EmpireBuilder, 6.0);
        $engine = $this->engine($this->drawing([0.0, 0.10, 0.10, 0.0]));

        $result = $engine->evaluateSuccession($stock, 0.25, -0.10);

        $this->assertIsArray($result);
        $this->assertTrue($result['was_forced'], 'Value destruction past the grace period is a dismissal.');
        $this->assertSame('empire_builder', $result['outgoing']);
        $this->assertSame(ManagementStyle::Steward, $stock->getManagementStyle());
        $this->assertEqualsWithDelta(0.0, $stock->getCeoTenureYears(), 1e-12, 'The clock restarts for the incoming manager.');
        $this->assertSame(
            0.0,
            $result['shock'],
            'The policy change is priced by the engines downstream; striking a shock here would charge the price twice for one piece of news.'
        );
    }

    /**
     * Two hazards compete for the seat, and which one took it is settled in proportion to the two
     * intensities — not by asking whether the spread happened to be negative. At the cost of capital that
     * puts roughly a third of departures in the forced column, which is where the literature puts them.
     */
    public function testDepartureIsAttributedToTheTwoHazardsInProportion(): void
    {
        $engine = $this->engine(new MathUtility());
        $settled = ManagementSuccessionEngine::GRACE_PERIOD_YEARS + 1.0;

        $forcedShare = static function (ManagementSuccessionEngine $engine, float $eva) use ($settled): float {
            $forced = $engine->resolveForcedHazard($settled, $eva);

            return $forced / (ManagementSuccessionEngine::BASE_DEPARTURE_HAZARD + $forced);
        };

        $atCostOfCapital = $forcedShare($engine, 0.0);
        $this->assertGreaterThan(0.25, $atCostOfCapital);
        $this->assertLessThan(0.45, $atCostOfCapital);

        // The worse the economic profit, the larger the share of exits that are the board's doing.
        $this->assertGreaterThan($atCostOfCapital, $forcedShare($engine, -0.08));
        $this->assertLessThan($atCostOfCapital, $forcedShare($engine, 0.08));

        // And inside the grace period no departure can be a dismissal at all.
        $this->assertSame(0.0, $engine->resolveForcedHazard(0.5, -0.20));
    }

    /** A long-serving manager leaving is a retirement, not a firing, and the house style usually survives. */
    public function testALongServingDepartureIsReadAsRetirement(): void
    {
        $stock = $this->firm(ManagementStyle::Steward, ManagementSuccessionEngine::RETIREMENT_TENURE_YEARS + 1.0);
        $engine = $this->engine($this->drawing([0.0, 0.10]));

        $result = $engine->evaluateSuccession($stock, 0.25, -0.10);

        $this->assertIsArray($result);
        $this->assertFalse($result['was_forced']);
        $this->assertSame(ManagementStyle::Steward, $stock->getManagementStyle());
    }

    /** The incumbent survives an ordinary tick, and simply ages by it. */
    public function testAnUneventfulTickOnlyAgesTheIncumbent(): void
    {
        $stock = $this->firm(ManagementStyle::Operator, 4.0);
        $engine = $this->engine($this->drawing([0.99]));

        $this->assertNull($engine->evaluateSuccession($stock, 0.25, 0.02));
        $this->assertEqualsWithDelta(4.25, $stock->getCeoTenureYears(), 1e-12);
    }

    /** A bankrupt shell has no board left to act, and must not age or change hands. */
    public function testBankruptShellIsLeftAlone(): void
    {
        $stock = $this->firm(ManagementStyle::Operator, 4.0);
        $stock->setIsBankrupt(true);

        $this->assertNull($this->engine(new MathUtility())->evaluateSuccession($stock, 0.25, -0.20));
        $this->assertEqualsWithDelta(4.0, $stock->getCeoTenureYears(), 1e-12);
    }

    /** Seeded tenures are spread, so the market does not march out of the grace period as one cohort. */
    public function testSeedTenuresAreSpreadAcrossTheRoster(): void
    {
        mt_srand(20260915);
        $mathUtility = new MathUtility();
        $drawn = [];
        for ($i = 0; $i < 400; $i++) {
            $drawn[] = ManagementSuccessionEngine::drawSeedTenure($mathUtility);
        }

        $this->assertGreaterThanOrEqual(0.0, min($drawn));
        $this->assertLessThanOrEqual(ManagementSuccessionEngine::RETIREMENT_TENURE_YEARS, max($drawn));
        $this->assertGreaterThan(
            ManagementSuccessionEngine::GRACE_PERIOD_YEARS,
            max($drawn),
            'Some firms must open the simulation already past the grace period.'
        );
        $this->assertLessThan(ManagementSuccessionEngine::GRACE_PERIOD_YEARS, min($drawn));
    }

    /**
     * A MathUtility whose uniform draws are scripted, so a probabilistic branch can be pinned exactly.
     *
     * @param list<float> $draws
     */
    private function drawing(array $draws): MathUtility&Stub
    {
        $mathUtility = $this->createStub(MathUtility::class);
        $sequence = $draws;
        $mathUtility->method('generateUniform')->willReturnCallback(
            static function () use (&$sequence): float {
                return array_shift($sequence) ?? 0.999;
            }
        );
        $mathUtility->method('checkProbability')->willReturnCallback(
            static function (float $probability) use (&$sequence): bool {
                return (array_shift($sequence) ?? 0.999) < $probability;
            }
        );
        $mathUtility->method('calculateNormalCDF')->willReturnCallback(
            static fn (float $z): float => (new MathUtility())->calculateNormalCDF($z)
        );

        return $mathUtility;
    }
}
