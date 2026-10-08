<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Politics\PoliticsEngine;
use Doctrine\DBAL\Connection;

/**
 * The quarterly record in macro_report as the server-drawn pages read it: the laws in force each quarter, and the
 * Sovereign Reserve Fund's policy weights. The browser charts read the same rows through /api/macro-reports.
 */
class MacroReportHistoryRepository
{
    /** Quarters of history the pages draw: the twenty-five years /api/macro-reports serves. */
    public const QUARTERS = 100;

    public function __construct(private readonly Connection $connection) {}

    /**
     * The laws in force at each recorded quarter, oldest first: each Diet lever (PoliticsEngine::LEVER_FIELDS keys) and
     * the core capital requirement. A quarter recorded before a column existed carries null for it.
     *
     * @return list<array{t: float, levers: array<string, float|null>, capitalRequirement: float|null}>
     */
    public function laws(int $quarters = self::QUARTERS): array
    {
        $columns = array_map(self::column(...), PoliticsEngine::LEVER_FIELDS);
        $rows = $this->connection->fetchAllAssociative(sprintf(
            'SELECT total_time, bank_capital_requirement, %s FROM macro_report WHERE total_time IS NOT NULL ORDER BY id DESC LIMIT %d',
            implode(', ', $columns),
            $quarters
        ));

        $laws = [];
        foreach (array_reverse($rows) as $row) {
            $laws[] = [
                't' => (float) $row['total_time'],
                'levers' => array_map(static fn(string $column): ?float => self::number($row[$column] ?? null), $columns),
                'capitalRequirement' => self::number($row['bank_capital_requirement'] ?? null),
            ];
        }

        return $laws;
    }

    /**
     * The fund's policy weights at each recorded quarter, keyed by the quarter's total_time as the row carries it, so a
     * browser chart reading /api/macro-reports can look each quarter up.
     *
     * @return array<string, array{target: float|null, equityPolicy: float|null}>
     */
    public function fundPolicy(int $quarters = self::QUARTERS): array
    {
        $rows = $this->connection->fetchAllAssociative(sprintf(
            'SELECT total_time, sovereign_fund_target_weight, sovereign_fund_policy_equity_share FROM macro_report WHERE total_time IS NOT NULL ORDER BY id DESC LIMIT %d',
            $quarters
        ));

        $policy = [];
        foreach ($rows as $row) {
            $policy[(string) $row['total_time']] = [
                'target' => self::number($row['sovereign_fund_target_weight'] ?? null),
                'equityPolicy' => self::number($row['sovereign_fund_policy_equity_share'] ?? null),
            ];
        }

        return $policy;
    }

    /** A state field's macro_report column: corporateTaxPolicyShift is corporate_tax_policy_shift. */
    private static function column(string $field): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
    }

    private static function number(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
