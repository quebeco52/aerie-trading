<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\DTO\EarningsSimulationContext;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\EarningsEngine;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use App\Service\Politics\SecuritiesCommission;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Accrual-based earnings management (Burgstahler & Dichev 1997, Degeorge/Patel/Zeckhauser 1999).
 *
 * The behaviour under test is a threshold rule, not a smoother: a small shortfall against consensus is
 * closed and a large one is reported as it happened, what is borrowed reverses against later quarters
 * (Dechow & Dichev 2002), and the entry never touches cash — which is what leaves the Sloan (1996)
 * accruals signal free to price it.
 */
final class EarningsManagementTest extends TestCase
{
    private EarningsEngine $engine;
    private ReflectionMethod $manage;

    /** Consensus of $100m over 100m shares: a dollar a share, so a cent is 1% of it. */
    private const SHARES = 100_000_000.0;

    protected function setUp(): void
    {
        mt_srand(20260910);

        // manageReportedEarnings touches only the context's stock and strategy, plus the propensity and cushion
        // draws; the rest of the collaborators are reached solely through resolveTotalAssets, and only once a
        // balance-sheet ledger is open. The draws are pinned at a quarter of the unit interval, so a gap whose odds
        // exceed 25% is closed and the cushion is a quarter of a cent.
        $this->engine = $this->engineDrawing(new class extends MathUtility {
            public function generateUniform(): float
            {
                return 0.25;
            }
        });

        $this->manage = new ReflectionMethod(EarningsEngine::class, 'manageReportedEarnings');
    }

    private function engineDrawing(MathUtility $math): EarningsEngine
    {
        $engine = (new ReflectionClass(EarningsEngine::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(EarningsEngine::class, 'mathUtility'))->setValue($engine, $math);

        return $engine;
    }

    /**
     * Propensity is the probability management acts, so every test of the ENTRY pins it at certainty and
     * the two tests of the gate pin it at 0 and 1. Leaving it at the sector default would make each of
     * these a coin flip on the draw rather than an assertion about the rule.
     */
    private function certainManager(): StandardCorporateBusinessModel
    {
        return new class extends StandardCorporateBusinessModel {
            public const EARNINGS_MANAGEMENT_PROPENSITY = 1.00;
        };
    }

    private function makeContext(float $consensus, float $actual, float $bank = 0.0, ?MacroStateDTO $macro = null): EarningsSimulationContext
    {
        $stock = new Stock();
        $stock->setTicker('ZZZZ');
        $stock->setTotalEquity('1000000000');
        $stock->setWholesaleDebt('1000000000');
        $stock->setManagedAccrualBank($bank);

        $ctx = new EarningsSimulationContext($stock, $macro ?? new MacroStateDTO(), $this->certainManager(), 'none');
        $ctx->reportedExpectedNetIncome = $consensus;
        $ctx->reportedActualNetIncome = $actual;
        $ctx->sharesOutstanding = self::SHARES;

        return $ctx;
    }

    /**
     * The Securities Commission's enforcement scales the odds a firm closes a near miss: a gap closed at 40% odds under
     * the founding rules (the draw at 25%) is left at 20% under the strictest enforcement, and certainty never exceeds one.
     */
    public function testEnforcementScalesTheOddsOfClosingAGap(): void
    {
        $consensus = 100_000_000.0;
        $short = $consensus - (0.012 * self::SHARES);

        $founding = $this->makeContext($consensus, $short);
        $this->manage->invoke($this->engine, $founding);
        $this->assertGreaterThan(0.0, $founding->managedAccrual);

        $strict = $this->makeContext($consensus, $short, macro: new MacroStateDTO(earningsManagementScale: SecuritiesCommission::earningsManagementScale(1.0)));
        $this->manage->invoke($this->engine, $strict);
        $this->assertSame(0.0, $strict->managedAccrual, 'Halved odds fall under the draw.');

        $light = $this->makeContext($consensus, $short, macro: new MacroStateDTO(earningsManagementScale: SecuritiesCommission::earningsManagementScale(-1.0)));
        $this->manage->invoke($this->engine, $light);
        $this->assertGreaterThan(0.0, $light->managedAccrual);
    }

    /** A near miss is closed, and lands on the line or within a cent above it, where the excess mass sits. */
    public function testSmallShortfallIsClosedAndPrintsAMeetOrACentBeat(): void
    {
        $consensus = 100_000_000.0;
        // Half a cent short: well inside the two-cent reachable gap.
        $ctx = $this->makeContext($consensus, $consensus * 0.995);

        $this->manage->invoke($this->engine, $ctx);

        $this->assertGreaterThan(0.0, $ctx->managedAccrual, 'A reachable shortfall must be closed with an accrual.');
        $this->assertGreaterThanOrEqual($consensus, $ctx->reportedActualNetIncome, 'A managed quarter does not print a miss.');
        $this->assertLessThanOrEqual(
            $consensus + (EarningsEngine::EARNINGS_MANAGEMENT_BEAT_CUSHION_PER_SHARE * self::SHARES),
            $ctx->reportedActualNetIncome,
            'It lands within a cent of the line, not comfortably past it.',
        );
        $this->assertSame(
            $ctx->managedAccrual,
            $ctx->stock->getManagedAccrualBank(),
            'Everything borrowed has to be owed back.',
        );
    }

    /**
     * The odds fall with the gap (Degeorge, Patel & Zeckhauser 1999: the cost of manipulation rises with the shortfall),
     * so the hole in the reported distribution is just below zero and not across the whole reachable band.
     */
    public function testTheOddsOfClosingFallWithTheGapAndVanishAtItsEdge(): void
    {
        $engine = $this->engineDrawing(new MathUtility());
        $closed = function (float $centsShort) use ($engine): float {
            $hits = 0;
            for ($trial = 0; $trial < 4000; $trial++) {
                $ctx = $this->makeContext(100_000_000.0, 100_000_000.0 - ($centsShort * 0.01 * self::SHARES));
                $this->manage->invoke($engine, $ctx);
                $hits += $ctx->managedAccrual > 0.0 ? 1 : 0;
            }

            return $hits / 4000;
        };

        $edge = EarningsEngine::EARNINGS_MANAGEMENT_MAX_GAP_PER_SHARE * 100.0;
        $this->assertEqualsWithDelta(1.0 - (0.5 / $edge), $closed(0.5), 0.03);
        $this->assertEqualsWithDelta(1.0 - (1.5 / $edge), $closed(1.5), 0.03);
        $this->assertSame(0.0, $closed($edge + 0.5), 'past the reachable gap the miss is reported');
    }

    /**
     * The regression. Propensity used to scale the SIZE of the entry rather than the odds of booking one,
     * so a firm short by S booked half of S and printed the miss anyway: measured across every industry
     * over twelve quarters, only 3 of 103 reachable near-misses ever crossed the line. The spike just above
     * consensus is the entire finding this method models, so a managed quarter has to actually beat.
     */
    public function testAManagedQuarterActuallyClearsConsensus(): void
    {
        $consensus = 100_000_000.0;

        // Shortfalls of 0.2 to 1.4 cents, every one with odds above the pinned draw.
        foreach ([0.998, 0.996, 0.994, 0.99, 0.986] as $shortfallFraction) {
            $ctx = $this->makeContext($consensus, $consensus * $shortfallFraction);

            $this->manage->invoke($this->engine, $ctx);

            $this->assertGreaterThan(
                $consensus,
                $ctx->reportedActualNetIncome,
                sprintf('A quarter %.1f cents short that management closes must print at or above the line, not a smaller miss.', (1.0 - $shortfallFraction) * 100),
            );
        }
    }

    /** A collapse is reported, not papered over: accruals are a timing entry with a hard ceiling. */
    public function testLargeShortfallIsReportedRatherThanManaged(): void
    {
        $consensus = 100_000_000.0;
        // 40% short: far outside the reachable gap.
        $ctx = $this->makeContext($consensus, $consensus * 0.60);

        $this->manage->invoke($this->engine, $ctx);

        $this->assertSame(0.0, $ctx->managedAccrual, 'A shortfall this wide cannot be closed with accruals.');
        $this->assertEqualsWithDelta($consensus * 0.60, $ctx->reportedActualNetIncome, 0.01);
        $this->assertSame(0.0, $ctx->stock->getManagedAccrualBank());
    }

    /** Every managed beat is a debt: the bank unwinds against later quarters. */
    public function testBorrowedAccrualsReverseAgainstLaterQuarters(): void
    {
        $bank = 10_000_000.0;
        // A quarter that beats comfortably on its own, so nothing new is borrowed and only the reversal shows.
        $ctx = $this->makeContext(50_000_000.0, 80_000_000.0, $bank);

        $this->manage->invoke($this->engine, $ctx);

        $expectedReversal = $bank * EarningsEngine::EARNINGS_MANAGEMENT_REVERSAL_RATE;
        $this->assertEqualsWithDelta(-$expectedReversal, $ctx->managedAccrual, 0.01, 'The reversal must reduce reported earnings.');
        $this->assertEqualsWithDelta(80_000_000.0 - $expectedReversal, $ctx->reportedActualNetIncome, 0.01);
        $this->assertEqualsWithDelta($bank - $expectedReversal, $ctx->stock->getManagedAccrualBank(), 0.01, 'The balance owed shrinks by what was repaid.');
    }

    /** The reversal is booked before the gap is measured, so a managed quarter makes the next one harder. */
    public function testReversalIsChargedBeforeManagementLooksAtTheGap(): void
    {
        $consensus = 100_000_000.0;
        $bank = 4_000_000.0;
        $ctx = $this->makeContext($consensus, $consensus, $bank);

        $this->manage->invoke($this->engine, $ctx);

        // The quarter met consensus on its own, but repaying last quarter's borrowing opened a fresh hole
        // that had to be closed again — the treadmill the literature describes.
        $this->assertGreaterThan(0.0, $ctx->stock->getManagedAccrualBank(), 'Repaying then re-borrowing leaves a balance still outstanding.');
    }

    /** A management team with no appetite for it reports what happened. */
    public function testZeroPropensityManagementNeverBooksAnAccrual(): void
    {
        $consensus = 100_000_000.0;
        $ctx = $this->makeContext($consensus, $consensus * 0.995);

        $strategy = new class extends StandardCorporateBusinessModel {
            public const EARNINGS_MANAGEMENT_PROPENSITY = 0.00;
        };
        $ctx = new EarningsSimulationContext($ctx->stock, new MacroStateDTO(), $strategy, 'none');
        $ctx->reportedExpectedNetIncome = $consensus;
        $ctx->reportedActualNetIncome = $consensus * 0.995;
        $ctx->sharesOutstanding = self::SHARES;

        $this->manage->invoke($this->engine, $ctx);

        $this->assertSame(0.0, $ctx->managedAccrual);
        $this->assertEqualsWithDelta($consensus * 0.995, $ctx->reportedActualNetIncome, 0.01);
    }

    /** The balance cannot grow without limit: past the cap there is nothing left to borrow. */
    public function testAccrualBankIsCappedAgainstTotalAssets(): void
    {
        $consensus = 100_000_000.0;
        // Total assets are 2bn here, so the cap is 2bn * EARNINGS_MANAGEMENT_MAX_BANK_RATIO.
        $cap = 2_000_000_000.0 * EarningsEngine::EARNINGS_MANAGEMENT_MAX_BANK_RATIO;
        $ctx = $this->makeContext($consensus, $consensus * 0.995, $cap);

        $this->manage->invoke($this->engine, $ctx);

        $this->assertLessThanOrEqual(
            $cap + 0.01,
            $ctx->stock->getManagedAccrualBank(),
            'The balance owed must never exceed what the balance sheet can hide.',
        );
    }

    /** Sector reporting incentives differ, and the ticker override wins over the sector default. */
    public function testPropensityIsSectorSpecific(): void
    {
        $stock = new Stock();
        $stock->setTicker('ZZZZ');

        $utility = new \App\Service\Model\Sector\UtilityBusinessModel();
        $bank    = new \App\Service\Model\Sector\CommercialBankBusinessModel();

        $this->assertLessThan(
            $bank->getEarningsManagementPropensity($stock),
            $utility->getEarningsManagementPropensity($stock),
            'A rate-regulated utility has less room and less reason to manage the print than a bank setting its own loan loss provision.',
        );
    }
}
