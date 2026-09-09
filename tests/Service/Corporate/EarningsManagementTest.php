<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\DTO\EarningsSimulationContext;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\EarningsEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

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

    protected function setUp(): void
    {
        // manageReportedEarnings touches only the context's stock and strategy; the collaborators are
        // reached solely through resolveTotalAssets, and only once a balance-sheet ledger is open.
        $this->engine = (new ReflectionClass(EarningsEngine::class))->newInstanceWithoutConstructor();
        $this->manage = new ReflectionMethod(EarningsEngine::class, 'manageReportedEarnings');
    }

    private function makeContext(float $consensus, float $actual, float $bank = 0.0): EarningsSimulationContext
    {
        $stock = new Stock();
        $stock->setTicker('ZZZZ');
        $stock->setTotalEquity('1000000000');
        $stock->setWholesaleDebt('1000000000');
        $stock->setManagedAccrualBank($bank);

        $ctx = new EarningsSimulationContext($stock, new MacroStateDTO(), new StandardCorporateBusinessModel(), 'none');
        $ctx->reportedExpectedNetIncome = $consensus;
        $ctx->reportedActualNetIncome = $actual;

        return $ctx;
    }

    /** A near miss is closed, and lands just above consensus rather than exactly on it. */
    public function testSmallShortfallIsClosedAndPrintsASmallBeat(): void
    {
        $consensus = 100_000_000.0;
        // 2% short: inside EARNINGS_MANAGEMENT_MAX_GAP.
        $ctx = $this->makeContext($consensus, $consensus * 0.98);

        $this->manage->invoke($this->engine, $ctx);

        $this->assertGreaterThan(0.0, $ctx->managedAccrual, 'A reachable shortfall must be closed with an accrual.');
        $this->assertGreaterThan(
            $consensus * 0.98,
            $ctx->reportedActualNetIncome,
            'Managing the quarter must raise the reported figure above what the business produced.'
        );
        $this->assertNotEqualsWithDelta(
            $consensus,
            $ctx->reportedActualNetIncome,
            1.0,
            'Landing exactly on consensus is the one outcome real reported distributions never show.'
        );
        $this->assertSame(
            $ctx->managedAccrual,
            $ctx->stock->getManagedAccrualBank(),
            'Everything borrowed has to be owed back.'
        );
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

        $expectedReversal = $bank * FinancialConstants::EARNINGS_MANAGEMENT_REVERSAL_RATE;
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
        $ctx = $this->makeContext($consensus, $consensus * 0.98);

        $strategy = new class extends StandardCorporateBusinessModel {
            public const EARNINGS_MANAGEMENT_PROPENSITY = 0.00;
        };
        $ctx = new EarningsSimulationContext($ctx->stock, new MacroStateDTO(), $strategy, 'none');
        $ctx->reportedExpectedNetIncome = $consensus;
        $ctx->reportedActualNetIncome = $consensus * 0.98;

        $this->manage->invoke($this->engine, $ctx);

        $this->assertSame(0.0, $ctx->managedAccrual);
        $this->assertEqualsWithDelta($consensus * 0.98, $ctx->reportedActualNetIncome, 0.01);
    }

    /** The balance cannot grow without limit: past the cap there is nothing left to borrow. */
    public function testAccrualBankIsCappedAgainstTotalAssets(): void
    {
        $consensus = 100_000_000.0;
        // Total assets are 2bn here, so the cap is 2bn * EARNINGS_MANAGEMENT_MAX_BANK_RATIO.
        $cap = 2_000_000_000.0 * FinancialConstants::EARNINGS_MANAGEMENT_MAX_BANK_RATIO;
        $ctx = $this->makeContext($consensus, $consensus * 0.98, $cap);

        $this->manage->invoke($this->engine, $ctx);

        $this->assertLessThanOrEqual(
            $cap + 0.01,
            $ctx->stock->getManagedAccrualBank(),
            'The balance owed must never exceed what the balance sheet can hide.'
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
            'A rate-regulated utility has less room and less reason to manage the print than a bank setting its own loan loss provision.'
        );
    }
}
