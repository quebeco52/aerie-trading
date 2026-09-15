<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\DTO\OptionQuoteDTO;
use App\Entity\OptionContract;
use App\Entity\TradeOrder;
use App\Entity\User;
use App\Entity\UserOption;
use App\Entity\UserStock;
use App\Service\Macro\MacroStateProvider;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fills orders in listed contracts.
 *
 * Separate from TradeExecutionService because an option is not a share with a different price. It settles in
 * contracts of a hundred shares, it is paid for in full rather than on margin when bought and collateralized
 * against the underlying rather than against itself when written, and the position it creates is one row
 * whose SIGN says which side of the contract the account is on. Threading all of that through the equity
 * path would have made four actions into eight branches in a method that already carries the borrow.
 *
 * The four actions mirror the equity desk's exactly — BUY and SELL for the long side, WRITE and COVER for
 * the short — and for the same reason: writing an option is not selling something owned, it is taking on a
 * liability with unbounded loss on the call side, and a trader has to say so rather than discovering it by
 * overselling a position.
 *
 * Every fill reprices the contract against the LIVE underlying. A resting mark is a tick old, and an option
 * mark that is a tick old against a spot that is not is a free trade for whoever notices.
 */
final class OptionTradeService
{
    /** The sides an option order may be placed on. */
    public const VALID_ACTIONS = ['BUY', 'SELL', 'WRITE', 'COVER'];

    /** Sides that pay cash out and move the position up. */
    private const BUY_SIDE_ACTIONS = ['BUY', 'COVER'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OptionPricingEngine $pricingEngine,
        private readonly MacroStateProvider $macroStateProvider,
        private readonly MarginEngine $marginEngine,
        private readonly MathUtility $mathUtility,
        private readonly \App\Service\User\CashLedger $cashLedger,
    ) {}

    /** Whether an action pays cash out, as opposed to taking it in. */
    public static function isBuySide(string $action): bool
    {
        return in_array($action, self::BUY_SIDE_ACTIONS, true);
    }

    /**
     * Fills one option order.
     *
     * The caller has already opened the transaction and locked the user; this runs inside it, exactly as
     * the equity path's own settlement does.
     *
     * @param int $contracts Number of contracts, always positive; the action carries the direction.
     */
    public function execute(User $user, OptionContract $contract, string $action, int $contracts): void
    {
        if ($contracts <= 0) {
            throw new \Exception('Invalid quantity.');
        }

        if (!in_array($action, self::VALID_ACTIONS, true)) {
            throw new \Exception('Invalid option order action.');
        }

        $macroState = $this->macroStateProvider->liveState();
        $quote = $this->pricingEngine->quoteContract(
            $contract,
            $macroState->sovereignCurve(),
            $macroState->totalTime
        );

        if ($quote->timeToExpiry < MathUtility::MIN_OPTION_TIME_TO_EXPIRY) {
            throw new \Exception("{$contract->getTicker()} has expired and is awaiting settlement.");
        }

        // The contract is now held, so it carries a stored mark. Stamping it here rather than waiting for
        // the sweep is what keeps net worth and the margin requirement — both of which read the column
        // through SQL — correct between the fill and the next pass over this name's slice.
        $this->pricingEngine->applyMark($contract, $quote);

        $position = $this->findPosition($user, $contract);
        $held = $position !== null ? (int) $position->getQuantity() : 0;

        $this->requireDirection($action, $held, $contracts, $contract);

        $premium = self::isBuySide($action) ? $quote->ask : $quote->bid;
        $consideration = MathUtility::formatDecimal(
            $premium * (float) FinancialConstants::OPTION_CONTRACT_MULTIPLIER * $contracts,
            4
        );

        $delta = self::isBuySide($action) ? $contracts : -$contracts;

        if ($action === 'WRITE') {
            $this->requireCollateral($user, $contract, $quote, $contracts);
        }

        if (self::isBuySide($action)) {
            $this->payPremium($user, $action, $consideration);
        } else {
            $this->cashLedger->credit($user, $consideration);
        }

        $this->applyPosition($user, $contract, $position, $held, $delta, $premium);

        // Open interest is the market's net long position, which is the sum of every account's. Adding the
        // same delta the position moved by is what keeps it that, without a second place to get it wrong.
        $contract->setOpenInterest((int) $contract->getOpenInterest() + $delta);

        $order = (new TradeOrder())
            ->setUser($user)
            ->setTicker($contract->getTicker())
            ->setAssetType('OPTION')
            ->setAction($action)
            ->setOrderType('MARKET')
            ->setQuantity($contracts)
            ->setFilledQuantity($contracts)
            ->setExecutionPrice(MathUtility::formatDecimal($premium, 4))
            ->setSpreadCost(MathUtility::formatDecimal(abs($premium - $quote->mark) * (float) FinancialConstants::OPTION_CONTRACT_MULTIPLIER * $contracts, 4))
            ->setImpactCost('0.0000')
            ->setStatus(TradeOrder::STATUS_FILLED)
            ->setFilledAt(new \DateTime());

        $this->em->persist($order);
        $this->em->persist($user);
    }

    /**
     * Rejects an order that would move the position through zero.
     *
     * A BUY against a short position and a SELL against none are not trades a trader meant to make: they are
     * an open dressed as a close. Requiring the closing action to name the side it is closing is what stops
     * an oversized SELL from silently becoming a written call with unbounded loss.
     */
    private function requireDirection(string $action, int $held, int $contracts, OptionContract $contract): void
    {
        $symbol = $contract->getTicker();

        match ($action) {
            'BUY' => $held >= 0
                ? null
                : throw new \Exception("You are short {$symbol}. Cover the position before buying it."),
            'WRITE' => $held <= 0
                ? null
                : throw new \Exception("You are long {$symbol}. Sell the position before writing it."),
            'SELL' => $held >= $contracts
                ? null
                : throw new \Exception("You do not hold {$contracts} contracts of {$symbol} to sell."),
            'COVER' => $held <= -$contracts
                ? null
                : throw new \Exception("You are not short {$contracts} contracts of {$symbol} to cover."),
            default => throw new \Exception('Invalid option order action.'),
        };
    }

    /**
     * Checks that an account can collateralize a contract it is about to write.
     *
     * A written call backed share for share by the underlying is a covered call: the worst case is that the
     * stock is called away at the strike, which is a position the account already holds, so it carries no
     * additional requirement. Everything else is naked and posts the Rule 4210 minimum.
     */
    private function requireCollateral(User $user, OptionContract $contract, OptionQuoteDTO $quote, int $contracts): void
    {
        if (!$user->isMarginEnabled()) {
            throw new \Exception('Writing options requires a margin account.');
        }

        $naked = $contracts - $this->coveredContracts($user, $contract, $contracts);

        if ($naked <= 0) {
            return;
        }

        $requirement = $this->mathUtility->calculateShortOptionRequirement(
            (float) $contract->getStock()->getPrice(),
            (float) $contract->getStrike(),
            $quote->mark,
            $contract->isCall()
        ) * (float) FinancialConstants::OPTION_CONTRACT_MULTIPLIER * $naked;

        // canOpen() measures a POSITION VALUE, which Reg T backs with half of itself in equity. The Rule
        // 4210 figure is not a position value — it is the equity itself, already net of the collateral the
        // contract provides. Handing it over unconverted asked the account for half of what the contract
        // actually needs, and the write would then fill and call the account on the very same tick. Grossing
        // it up by the initial requirement expresses it in the units canOpen speaks.
        $positionEquivalent = $requirement / FinancialConstants::INITIAL_MARGIN_REQUIREMENT;

        if (!$this->marginEngine->canOpen($user, $positionEquivalent)) {
            throw new \Exception('Insufficient buying power to collateralize this position.');
        }
    }

    /**
     * How many of the contracts being written are already covered by stock the account holds.
     *
     * Only a call can be covered this way. A put is covered by cash, which the buying-power check already
     * measures, so counting stock against it would credit the same collateral twice.
     *
     * Shares ALREADY standing behind written calls on the same name are deducted first. Without that, one
     * hundred shares covered the first contract written against them and then covered the next one too, and
     * the one after that: every write past the first skipped the margin gate entirely, and the account then
     * met the whole naked requirement on the next sweep — when OptionMarginCalculator, which does consume
     * the shares as it goes, counted the same contracts as naked and called it.
     */
    private function coveredContracts(User $user, OptionContract $contract, int $contracts): int
    {
        if (!$contract->isCall()) {
            return 0;
        }

        $holding = $this->em->getRepository(UserStock::class)->findOneBy([
            'user' => $user,
            'stock' => $contract->getStock(),
        ]);

        $shares = $holding !== null ? (int) $holding->getQuantity() : 0;

        if ($shares <= 0) {
            return 0;
        }

        $free = $shares - ($this->writtenCalls($user, $contract) * FinancialConstants::OPTION_CONTRACT_MULTIPLIER);

        if ($free <= 0) {
            return 0;
        }

        return min($contracts, intdiv($free, FinancialConstants::OPTION_CONTRACT_MULTIPLIER));
    }

    /**
     * Calls the account has already written on one underlying, across every contract on it.
     *
     * Every written call on the name competes for the same shares, whatever its strike or expiry, so the
     * count has to span the chain rather than the one contract being traded.
     */
    private function writtenCalls(User $user, OptionContract $contract): int
    {
        $written = (int) $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(-uo.quantity), 0)
             FROM user_options uo
             JOIN option_contracts oc ON uo.option_contract_id = oc.id
             WHERE uo.user_id = :user_id
               AND oc.stock_id = :stock_id
               AND oc.option_type = :call
               AND uo.quantity < 0',
            [
                'user_id' => $user->getId(),
                'stock_id' => $contract->getStock()->getId(),
                'call' => OptionContract::TYPE_CALL,
            ]
        );

        return max(0, $written);
    }

    /**
     * Pays for a fill.
     *
     * A long option is paid for in full. Listed options are not marginable under Regulation T — there is no
     * loan value in an asset that can expire worthless — so a BUY is measured against settled cash and never
     * borrows. A COVER is the closing leg of a position the account is already collateralizing, so it is
     * allowed to draw on the margin loan, exactly as covering a short stock position is.
     */
    private function payPremium(User $user, string $action, string $consideration): void
    {
        if ($action === 'BUY' && \bccomp((string) $user->getCashBalance(), $consideration, 4) < 0) {
            throw new \Exception('Insufficient funds. Long options are paid for in full.');
        }

        $this->cashLedger->debit($user, $consideration);
    }

    /**
     * Moves the position and keeps its basis.
     *
     * The basis only moves on a leg that ADDS to the position. A close realizes against the existing basis
     * and leaves it where it was, which is what makes the remaining contracts still carry the price they
     * were actually opened at.
     */
    private function applyPosition(
        User $user,
        OptionContract $contract,
        ?UserOption $position,
        int $held,
        int $delta,
        float $premium
    ): void {
        $updated = $held + $delta;

        if ($position === null) {
            $position = (new UserOption())
                ->setUser($user)
                ->setContract($contract)
                ->setQuantity(0)
                ->setAveragePremium('0.00000000');

            $this->em->persist($position);
        }

        $isOpening = abs($updated) > abs($held);

        if ($isOpening) {
            $addedContracts = abs($updated) - abs($held);
            $existingCost = (float) $position->getAveragePremium() * abs($held);
            $basis = ($existingCost + ($premium * $addedContracts)) / max(1, abs($updated));

            $position->setAveragePremium(MathUtility::formatDecimal($basis, 8));
        }

        $position->setQuantity($updated);

        if ($updated === 0) {
            $this->em->remove($position);
        }
    }

    /** The account's position in one contract, or null if it holds none. */
    public function findPosition(User $user, OptionContract $contract): ?UserOption
    {
        return $this->em->getRepository(UserOption::class)->findOneBy([
            'user' => $user,
            'contract' => $contract,
        ]);
    }
}
