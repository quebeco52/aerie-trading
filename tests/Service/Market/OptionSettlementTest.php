<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Entity\OptionContract;
use App\Service\Market\OptionSettlementEngine;
use App\Service\Math\FinancialConstants;
use PHPUnit\Framework\TestCase;

/**
 * Settlement, checked where it can actually be wrong: the signed delivery identity and the exercise
 * threshold. Both sides of a contract settle through the same expression, so the property worth asserting is
 * that a long and a short of the same contract are exact mirrors — if they ever are not, the clearing house
 * has created or destroyed stock.
 */
class OptionSettlementTest extends TestCase
{
    private const MULTIPLIER = FinancialConstants::OPTION_CONTRACT_MULTIPLIER;

    // --- The Delivery Identity ---

    public function testALongCallTakesStockIn(): void
    {
        $this->assertSame(self::MULTIPLIER, OptionSettlementEngine::shareDelta(1, true));
    }

    public function testAWrittenCallDeliversStockOut(): void
    {
        $this->assertSame(-self::MULTIPLIER, OptionSettlementEngine::shareDelta(-1, true));
    }

    public function testALongPutDeliversStockOut(): void
    {
        $this->assertSame(-self::MULTIPLIER, OptionSettlementEngine::shareDelta(1, false));
    }

    public function testAWrittenPutTakesStockIn(): void
    {
        $this->assertSame(self::MULTIPLIER, OptionSettlementEngine::shareDelta(-1, false));
    }

    public function testTheTwoSidesOfAContractAreExactMirrors(): void
    {
        foreach ([true, false] as $isCall) {
            foreach ([1, 7, 250] as $contracts) {
                $long = OptionSettlementEngine::shareDelta($contracts, $isCall);
                $short = OptionSettlementEngine::shareDelta(-$contracts, $isCall);

                // Whatever one side receives, the other delivers. Nothing is created at settlement.
                $this->assertSame(0, $long + $short);
            }
        }
    }

    public function testDeliveryScalesWithTheNumberOfContracts(): void
    {
        $this->assertSame(
            5 * OptionSettlementEngine::shareDelta(1, true),
            OptionSettlementEngine::shareDelta(5, true)
        );
    }

    public function testAClosedPositionDeliversNothing(): void
    {
        $this->assertSame(0, OptionSettlementEngine::shareDelta(0, true));
        $this->assertSame(0, OptionSettlementEngine::shareDelta(0, false));
    }

    // --- Intrinsic Value And Exercise ---

    private function contract(string $type, string $strike): OptionContract
    {
        return (new OptionContract())
            ->setTicker('VANE-13X' . $strike)
            ->setOptionType($type)
            ->setStrike($strike)
            ->setExpirySerial(13)
            ->setExpiresAtTime(13.0 / 12.0);
    }

    public function testACallSettlesForWhatTheStockIsWorthAboveTheStrike(): void
    {
        $call = $this->contract(OptionContract::TYPE_CALL, '100');

        $this->assertEqualsWithDelta(12.5, $call->intrinsicValue(112.5), 1e-9);
        $this->assertSame(0.0, $call->intrinsicValue(88.0));
    }

    public function testAPutSettlesForWhatTheStockIsWorthBelowTheStrike(): void
    {
        $put = $this->contract(OptionContract::TYPE_PUT, '100');

        $this->assertEqualsWithDelta(12.0, $put->intrinsicValue(88.0), 1e-9);
        $this->assertSame(0.0, $put->intrinsicValue(112.0));
    }

    public function testABankruptUnderlyingSettlesEveryPutAtTheFullStrike(): void
    {
        $put = $this->contract(OptionContract::TYPE_PUT, '100');

        $this->assertEqualsWithDelta(100.0, $put->intrinsicValue(0.0), 1e-9);
        $this->assertSame(0.0, $this->contract(OptionContract::TYPE_CALL, '100')->intrinsicValue(0.0));
    }

    public function testAContractInTheMoneyByATickIsExercised(): void
    {
        $call = $this->contract(OptionContract::TYPE_CALL, '100');

        // Exercise by exception: the clearing house does not ask, it delivers anything worth a cent.
        $this->assertGreaterThanOrEqual(
            FinancialConstants::OPTION_EXERCISE_THRESHOLD,
            $call->intrinsicValue(100.0 + FinancialConstants::OPTION_EXERCISE_THRESHOLD)
        );

        $this->assertLessThan(
            FinancialConstants::OPTION_EXERCISE_THRESHOLD,
            $call->intrinsicValue(100.0 + (FinancialConstants::OPTION_EXERCISE_THRESHOLD / 2.0))
        );
    }

    // --- Customer Open Interest ---

    public function testCustomerOpenInterestIsThePlayersAndThePublicsTogether(): void
    {
        $contract = $this->contract(OptionContract::TYPE_CALL, '100');
        $contract->setOpenInterest(40)->setStructuralOpenInterest(1200);

        $this->assertSame(1240, $contract->customerOpenInterest());
    }

    public function testAPlayerWritingAgainstThePublicReducesWhatTheDeskIsShort(): void
    {
        $contract = $this->contract(OptionContract::TYPE_CALL, '100');
        $contract->setOpenInterest(-300)->setStructuralOpenInterest(1200);

        $this->assertSame(900, $contract->customerOpenInterest());
    }
}
