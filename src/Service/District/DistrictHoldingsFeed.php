<?php

declare(strict_types=1);

namespace App\Service\District;

use App\Entity\TradeOrder;
use App\Entity\User;
use App\Entity\UserStock;
use App\Service\User\CostBasisCalculator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The signed-in player's open positions, keyed by ticker, in the shape the district page marks
 * an owned building with: a pennant on the roof, a "Your Position" line in the panel, and the
 * quick-trade ticket's holding readout.
 *
 * UserStock carries a SIGNED quantity (a short is negative) because the mark-to-market arithmetic
 * is symmetric. Nothing on the street is symmetric: a flag is a flag whichever way the position
 * points, share counts are printed unsigned, and the ticket's "Max" for COVER wants the size of
 * the short, not a negative number. So the sign is split out here, once, into `isShort`, and
 * `quantity` is always the absolute size.
 *
 * Average cost comes from CostBasisCalculator, the one implementation every surface that prints an
 * average cost or an unrealised P&L is required to share (see its class docblock); for a short it
 * is average proceeds per share, which is the basis the JS measures a short's P&L down from.
 */
class DistrictHoldingsFeed
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CostBasisCalculator $costBasis,
    ) {}

    /**
     * @return array<string, array{quantity: int, isShort: bool, averageCost: float|null}>
     */
    public function holdingsForUser(User $user): array
    {
        $userStocks = $this->entityManager->getRepository(UserStock::class)->findBy(['user' => $user]);

        // Same query the dashboard and stock page run before calling the calculator: filled orders,
        // oldest first, because the weighted average is a running one.
        $filledOrders = $this->entityManager->createQuery(
            'SELECT o FROM App\Entity\TradeOrder o WHERE o.user = :user AND o.status = :status ORDER BY o.createdAt ASC'
        )->setParameter('user', $user)->setParameter('status', 'FILLED')->getResult();

        return $this->describe($userStocks, $this->costBasis->calculate($filledOrders));
    }

    /**
     * Pure shaping step, separated from the queries so the sign handling is unit-testable.
     *
     * @param  iterable<UserStock>  $userStocks
     * @param  array<string, float> $averageCostByTicker CostBasisCalculator::calculate() output.
     * @return array<string, array{quantity: int, isShort: bool, averageCost: float|null}>
     */
    public function describe(iterable $userStocks, array $averageCostByTicker): array
    {
        $holdings = [];
        foreach ($userStocks as $userStock) {
            $signed = (int) $userStock->getQuantity();
            if ($signed === 0) {
                continue;
            }

            $ticker = (string) $userStock->getStock()?->getTicker();
            if ($ticker === '') {
                continue;
            }

            $holdings[$ticker] = [
                'quantity' => abs($signed),
                'isShort' => $signed < 0,
                'averageCost' => $averageCostByTicker[$ticker] ?? null,
            ];
        }

        return $holdings;
    }
}
