<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Entity\Stock;

/**
 * The companies a stock is compared with: the others in its industry, or in its sector when its industry has fewer
 * than two others listed, as a terminal falls back to the broader group when the narrow one is too thin to read.
 */
final class PeerGroup
{
    /** Fewest other companies that make a peer group; with fewer in the industry the sector is used. */
    public const MIN_PEERS = 2;

    /**
     * @param  list<Stock>                                                             $candidates
     * @return array{label: 'industry'|'sector', name: string, members: list<Stock>}
     */
    public static function of(Stock $stock, array $candidates): array
    {
        $others = array_values(array_filter($candidates, static fn(Stock $s): bool => $s->getId() !== $stock->getId()));
        $industry = array_values(array_filter($others, static fn(Stock $s): bool => $s->getIndustry() === $stock->getIndustry()));
        if (count($industry) >= self::MIN_PEERS) {
            return ['label' => 'industry', 'name' => (string) $stock->getIndustry(), 'members' => $industry];
        }

        return [
            'label' => 'sector',
            'name' => $stock->getSector(),
            'members' => array_values(array_filter($others, static fn(Stock $s): bool => $s->getSector() === $stock->getSector())),
        ];
    }
}
