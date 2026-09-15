<?php

declare(strict_types=1);

namespace App\Tests\Service\User;

use App\Entity\User;
use App\Service\User\CashLedger;
use PHPUnit\Framework\TestCase;

/**
 * The single authority on money moving in and out of an account, which three desks now share.
 *
 * The properties worth pinning are the sweep order in both directions — draw cash before borrowing, repay
 * the loan before crediting cash — and that a round trip through both is exact. Before this was one class
 * the equity desk, the option desk and option settlement each carried their own copy of these rules, which
 * agreed only until the first of them was corrected.
 */
class CashLedgerTest extends TestCase
{
    private CashLedger $ledger;

    protected function setUp(): void
    {
        $this->ledger = new CashLedger();
    }

    private function account(string $cash, string $debit = '0.00'): User
    {
        $user = new User();
        $user->setCashBalance($cash);
        $user->setMarginDebit($debit);

        return $user;
    }

    // --- Paying Out ---

    public function testAPaymentCoveredByCashBorrowsNothing(): void
    {
        $user = $this->account('1000.0000');

        $this->ledger->debit($user, '250.0000');

        $this->assertSame(0, \bccomp((string) $user->getCashBalance(), '750.0000', 4));
        $this->assertSame(0, \bccomp((string) $user->getMarginDebit(), '0.00', 2));
    }

    public function testAPaymentDrawsCashToZeroBeforeItBorrows(): void
    {
        $user = $this->account('400.0000');

        $this->ledger->debit($user, '1000.0000');

        // Nobody pays margin interest while holding idle cash.
        $this->assertSame(0, \bccomp((string) $user->getCashBalance(), '0.0000', 4));
        $this->assertSame(0, \bccomp((string) $user->getMarginDebit(), '600.00', 2));
    }

    public function testBorrowingAddsToAnExistingLoanRatherThanReplacingIt(): void
    {
        $user = $this->account('0.0000', '500.00');

        $this->ledger->debit($user, '300.0000');

        $this->assertSame(0, \bccomp((string) $user->getMarginDebit(), '800.00', 2));
    }

    public function testAnExactlyFundedPaymentBorrowsNothing(): void
    {
        $user = $this->account('1000.0000');

        $this->ledger->debit($user, '1000.0000');

        $this->assertSame(0, \bccomp((string) $user->getCashBalance(), '0.0000', 4));
        $this->assertSame(0, \bccomp((string) $user->getMarginDebit(), '0.00', 2));
    }

    // --- Taking In ---

    public function testAReceiptPaysDownTheLoanBeforeItReachesCash(): void
    {
        $user = $this->account('0.0000', '400.00');

        $this->ledger->credit($user, '250.0000');

        $this->assertSame(0, \bccomp((string) $user->getMarginDebit(), '150.00', 2));
        $this->assertSame(0, \bccomp((string) $user->getCashBalance(), '0.0000', 4));
    }

    public function testAReceiptLargerThanTheLoanClearsItAndBanksTheRest(): void
    {
        $user = $this->account('100.0000', '400.00');

        $this->ledger->credit($user, '1000.0000');

        $this->assertSame(0, \bccomp((string) $user->getMarginDebit(), '0.00', 2));
        $this->assertSame(0, \bccomp((string) $user->getCashBalance(), '700.0000', 4));
    }

    public function testAReceiptIntoAnUnleveredAccountIsAllCash(): void
    {
        $user = $this->account('100.0000');

        $this->ledger->credit($user, '50.0000');

        $this->assertSame(0, \bccomp((string) $user->getCashBalance(), '150.0000', 4));
    }

    // --- Round Trips ---

    public function testPayingAndReceivingTheSameAmountLeavesTheAccountWhereItStarted(): void
    {
        foreach ([['1000.0000', '0.00'], ['100.0000', '0.00'], ['0.0000', '250.00']] as [$cash, $debit]) {
            $user = $this->account($cash, $debit);

            $this->ledger->debit($user, '750.0000');
            $this->ledger->credit($user, '750.0000');

            $this->assertSame(0, \bccomp((string) $user->getCashBalance(), $cash, 4), "cash from {$cash}/{$debit}");
            $this->assertSame(0, \bccomp((string) $user->getMarginDebit(), $debit, 2), "debit from {$cash}/{$debit}");
        }
    }

    public function testFractionalConsiderationIsCarriedAtCashPrecision(): void
    {
        $user = $this->account('10.0000');

        // A fill can be struck at a fraction of a cent, so the cash leg must not round it away.
        $this->ledger->debit($user, '0.0001');

        $this->assertSame(0, \bccomp((string) $user->getCashBalance(), '9.9999', 4));
    }
}
