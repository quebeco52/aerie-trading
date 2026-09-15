<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Entity\User;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What an account's option book is worth and what it must post against it.
 *
 * The requirement is computed in PHP rather than in the aggregate SQL the rest of the margin sweep uses, and
 * deliberately: the Rule 4210 charge is a real formula with a floor that switches base between a call and a
 * put, and writing it a second time in SQL would leave two authorities on the same number with nothing to
 * keep them in step. An account's SHORT option positions are few — most accounts have none, and the query
 * returns nothing at all for them — so the cost of doing it honestly is a query that usually returns zero
 * rows.
 */
final class OptionMarginCalculator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MathUtility $mathUtility,
    ) {}

    /**
     * The account's option book, marked and collateralized.
     *
     * @return array{long_value: float, short_value: float, requirement: float}
     *         What the long side is worth, what buying the short side back would cost, and the equity the
     *         short side has to be backed by.
     */
    public function evaluate(User $user): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT uo.quantity, oc.price AS premium, oc.strike, oc.option_type, s.price AS underlying, s.id AS stock_id
             FROM user_options uo
             JOIN option_contracts oc ON uo.option_contract_id = oc.id
             JOIN stocks s ON oc.stock_id = s.id
             WHERE uo.user_id = :user_id',
            ['user_id' => $user->getId()]
        );

        if ($rows === []) {
            return ['long_value' => 0.0, 'short_value' => 0.0, 'requirement' => 0.0];
        }

        $multiplier = (float) FinancialConstants::OPTION_CONTRACT_MULTIPLIER;
        $longValue = 0.0;
        $shortValue = 0.0;
        $requirement = 0.0;

        // Shares available to cover written calls, consumed as they are used: a hundred shares cover one
        // contract, not every contract written against the same name.
        $coverShares = $this->coverShares($user);

        foreach ($rows as $row) {
            $contracts = (int) $row['quantity'];
            $premium = (float) $row['premium'];

            if ($contracts > 0) {
                $longValue += $contracts * $multiplier * $premium;
                continue;
            }

            $written = abs($contracts);
            $shortValue += $written * $multiplier * $premium;

            $isCall = $row['option_type'] === \App\Entity\OptionContract::TYPE_CALL;
            $stockId = (int) $row['stock_id'];

            if ($isCall && ($coverShares[$stockId] ?? 0) > 0) {
                $covered = min($written, intdiv($coverShares[$stockId], FinancialConstants::OPTION_CONTRACT_MULTIPLIER));
                $coverShares[$stockId] -= $covered * FinancialConstants::OPTION_CONTRACT_MULTIPLIER;
                $written -= $covered;
            }

            if ($written <= 0) {
                continue;
            }

            $requirement += $this->mathUtility->calculateShortOptionRequirement(
                (float) $row['underlying'],
                (float) $row['strike'],
                $premium,
                $isCall
            ) * $multiplier * $written;
        }

        return ['long_value' => $longValue, 'short_value' => $shortValue, 'requirement' => $requirement];
    }

    /**
     * Long share positions that can stand behind a written call, keyed by underlying.
     *
     * @return array<int, int>
     */
    private function coverShares(User $user): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT stock_id, quantity FROM user_stocks WHERE user_id = :user_id AND quantity > 0',
            ['user_id' => $user->getId()]
        );

        $shares = [];
        foreach ($rows as $row) {
            $shares[(int) $row['stock_id']] = (int) $row['quantity'];
        }

        return $shares;
    }
}
