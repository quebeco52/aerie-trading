<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\DTO\ResolvedAssetDTO;
use App\Entity\Bond;
use App\Entity\Etf;
use App\Entity\Stock;
use App\Entity\User;
use App\Entity\UserBond;
use App\Entity\UserEtf;
use App\Entity\UserStock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Maps a ticker to its instrument and a user to their holding in it, for every asset class the desk trades.
 *
 * One authority on the mapping. The alternative is the same nullable-entity chain repeated at each of the
 * four places TradeExecutionService needs it, where adding an asset class means finding all four and getting
 * the branch order right in each — and a missed one does not fail loudly, it silently treats a bond as an
 * ETF and looks up a holding that will never exist.
 */
final class AssetResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Resolves a ticker, or null when nothing trades under it.
     *
     * Ordered by how often each class is looked up rather than alphabetically: most orders are for stocks,
     * and a miss costs a query.
     */
    public function resolve(string $ticker): ?ResolvedAssetDTO
    {
        $stock = $this->em->getRepository(Stock::class)->findOneBy(['ticker' => $ticker]);
        if ($stock instanceof Stock) {
            return new ResolvedAssetDTO($stock, 'STOCK');
        }

        $etf = $this->em->getRepository(Etf::class)->findOneBy(['ticker' => $ticker]);
        if ($etf instanceof Etf) {
            return new ResolvedAssetDTO($etf, 'ETF');
        }

        $bond = $this->em->getRepository(Bond::class)->findOneBy(['ticker' => $ticker]);
        if ($bond instanceof Bond) {
            return new ResolvedAssetDTO($bond, 'BOND');
        }

        return null;
    }

    /**
     * The user's existing holding in an instrument, or null if they hold none.
     */
    public function findHolding(User $user, ResolvedAssetDTO $asset): UserStock|UserEtf|UserBond|null
    {
        return match ($asset->type) {
            'STOCK' => $this->em->getRepository(UserStock::class)->findOneBy(['user' => $user, 'stock' => $asset->entity]),
            'ETF' => $this->em->getRepository(UserEtf::class)->findOneBy(['user' => $user, 'etf' => $asset->entity]),
            'BOND' => $this->em->getRepository(UserBond::class)->findOneBy(['user' => $user, 'bond' => $asset->entity]),
            default => null,
        };
    }

    /**
     * Adds units to a holding, opening one if the user does not have it yet.
     *
     * @param UserStock|UserEtf|UserBond|null $holding  Existing holding, from findHolding().
     * @param int                             $quantity Units to add.
     */
    public function addToHolding(User $user, ResolvedAssetDTO $asset, UserStock|UserEtf|UserBond|null $holding, int $quantity): UserStock|UserEtf|UserBond
    {
        if ($holding === null) {
            $holding = match ($asset->type) {
                'STOCK' => (new UserStock())->setUser($user)->setStock($asset->entity),
                'ETF' => (new UserEtf())->setUser($user)->setEtf($asset->entity),
                'BOND' => (new UserBond())->setUser($user)->setBond($asset->entity),
                default => throw new \InvalidArgumentException("Unknown asset type {$asset->type}."),
            };

            $holding->setQuantity(0);
            $this->em->persist($holding);
        }

        $holding->setQuantity((int) $holding->getQuantity() + $quantity);

        return $holding;
    }

    /**
     * Removes units from a holding, deleting the row once it reaches zero.
     */
    public function removeFromHolding(UserStock|UserEtf|UserBond $holding, int $quantity): void
    {
        $remaining = (int) $holding->getQuantity() - $quantity;
        $holding->setQuantity($remaining);

        if ($remaining === 0) {
            $this->em->remove($holding);
        }
    }
}
