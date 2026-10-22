<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CorporateReport;
use App\Entity\Stock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CorporateReport>
 */
class CorporateReportRepository extends ServiceEntityRepository
{
    use NewestPerStockTrait;

    // --- Price at Filing ---
    /**
     * Years past a report's filing time the price probe reaches: the filing time is stored to the microyear, so this
     * takes the filing tick's own bar, and ticks are at least 1/3600 of a year apart, so never the next one's.
     */
    private const FILING_TIME_TOLERANCE = 1e-6;
    /** Reports priced per statement: three parameters each, well inside the server's placeholder limit. */
    private const PROBES_PER_STATEMENT = 500;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CorporateReport::class);
    }

    /**
     * A company's most recently filed report, which is the prior quarter to the one being struck.
     */
    public function findLatestFor(Stock $stock): ?CorporateReport
    {
        return $this->findOneBy(['stock' => $stock], ['recordedAt' => 'DESC']);
    }

    /**
     * A company's newest $newest reports, oldest first, each with the share price at its filing as historical_price:
     * the last bar at or before the filing on the simulated clock, or null when no bar precedes it.
     *
     * @return list<array<string, mixed>> The corporate_report columns and historical_price.
     */
    public function findWithPriceAtFiling(Stock $stock, int $newest): array
    {
        return self::withPriceAtFiling($this->getEntityManager()->getConnection(), (int) $stock->getId(), $newest);
    }

    /**
     * The reports, then their prices: one statement per PROBES_PER_STATEMENT reports, each carrying a probe per report.
     *
     * Each probe is its own subquery with its bound as a constant, so it is one seek on the history index; a probe
     * correlated to the report row cannot seek on the filing time and scans the company's whole history.
     *
     * The filing time is stored to the microyear, so the probe reaches FILING_TIME_TOLERANCE past it: the filing
     * tick's own bar, never the next tick's. A report the simulated clock leaves unpriced, filed or recorded before
     * that clock was kept, is priced on the wall clock, which ties dozens of bars to one second, so the newest row of
     * the second is taken.
     *
     * @return list<array<string, mixed>>
     */
    private static function withPriceAtFiling(Connection $conn, int $stockId, int $newest): array
    {
        $reports = $conn->fetchAllAssociative(
            'SELECT newest.* FROM (
                 SELECT cr.* FROM corporate_report cr WHERE cr.stock_id = :id ORDER BY cr.recorded_at DESC LIMIT :newest
             ) newest ORDER BY newest.recorded_at ASC',
            ['id' => $stockId, 'newest' => $newest],
            ['newest' => ParameterType::INTEGER],
        );

        $filedAt = [];
        foreach ($reports as $index => $report) {
            if (is_numeric($report['total_time'] ?? null)) {
                $filedAt[$index] = (float) $report['total_time'] + self::FILING_TIME_TOLERANCE;
            }
        }
        $prices = self::probe($conn, $stockId, $filedAt, 'h.sim_time <= ? ORDER BY h.sim_time DESC');

        $unpriced = [];
        foreach ($reports as $index => $report) {
            if (($prices[$index] ?? null) === null) {
                $unpriced[$index] = (string) $report['recorded_at'];
            }
        }
        $prices = self::probe($conn, $stockId, $unpriced, 'h.recorded_at <= ? ORDER BY h.recorded_at DESC, h.id DESC') + $prices;

        foreach ($reports as $index => &$report) {
            $report['historical_price'] = $prices[$index] ?? null;
        }
        unset($report);

        return $reports;
    }

    /**
     * The price of the last bar each bound reaches, keyed as the bounds are.
     *
     * @param array<int, float|string> $bounds
     * @param string                   $reach  The probe's condition on its bound and the order its newest bar comes
     *                                         first in.
     *
     * @return array<int, string|null>
     */
    private static function probe(Connection $conn, int $stockId, array $bounds, string $reach): array
    {
        $prices = [];
        foreach (array_chunk($bounds, self::PROBES_PER_STATEMENT, true) as $chunk) {
            $probes = [];
            $params = [];
            foreach ($chunk as $index => $bound) {
                $probes[] = "SELECT ? AS report_index, (SELECT h.price FROM stock_history h WHERE h.stock_id = ? AND {$reach} LIMIT 1) AS price";
                array_push($params, $index, $stockId, $bound);
            }
            foreach ($conn->fetchAllKeyValue(implode(' UNION ALL ', $probes), $params) as $index => $price) {
                $prices[(int) $index] = $price === null ? null : (string) $price;
            }
        }

        return $prices;
    }

    /**
     * Each company's newest $perStock filings, grouped by company and newest first within each.
     *
     * One query for the whole set rather than one per company, because the caller is rendering a
     * street of tenants and a query per tile is a query per row of the page.
     *
     * @param  list<Stock>           $stocks
     * @return list<CorporateReport>
     */
    public function findForStocksNewestFirst(array $stocks, int $perStock): array
    {
        if ($stocks === []) {
            return [];
        }

        return $this->createQueryBuilder('r')
            ->andWhere('r.id IN (:ids)')
            ->setParameter('ids', $this->newestIdsPerStock($stocks, $perStock))
            ->orderBy('r.stock', 'ASC')
            ->addOrderBy('r.recordedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
