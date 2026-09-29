<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Stock;
use Doctrine\DBAL\ArrayParameterType;

/**
 * Each company's newest rows, for an entity carrying a `stock` association and a `recordedAt` column.
 */
trait NewestPerStockTrait
{
    /**
     * Ids of each company's newest $perStock rows, in one query. ROW_NUMBER() is the per-company
     * limit DQL cannot express, and the (stock_id, recorded_at) index serves its partition and order,
     * so hydration stays bounded by the page however long the market has run.
     *
     * @param  list<Stock> $stocks
     * @return list<int>
     */
    private function newestIdsPerStock(array $stocks, int $perStock): array
    {
        $metadata = $this->getClassMetadata();
        $sql = sprintf(
            'SELECT %1$s FROM (SELECT %1$s, ROW_NUMBER() OVER (PARTITION BY %2$s ORDER BY %3$s DESC, %1$s DESC) AS rn'
            . ' FROM %4$s WHERE %2$s IN (:stocks)) ranked WHERE rn <= :perStock',
            $metadata->getSingleIdentifierColumnName(),
            $metadata->getSingleAssociationJoinColumnName('stock'),
            $metadata->getColumnName('recordedAt'),
            $metadata->getTableName(),
        );

        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            $sql,
            ['stocks' => array_map(static fn (Stock $stock): int => (int) $stock->getId(), $stocks), 'perStock' => $perStock],
            ['stocks' => ArrayParameterType::INTEGER],
        );

        return array_map(intval(...), $ids);
    }
}
