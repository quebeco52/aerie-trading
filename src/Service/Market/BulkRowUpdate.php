<?php

declare(strict_types=1);

namespace App\Service\Market;

use Doctrine\DBAL\Connection;

/**
 * Writes the same columns on many rows of one table in a handful of statements instead of one per row.
 *
 * Doctrine flushes one UPDATE per dirty entity, and a working set that is remarked wholesale every tick —
 * the bond ladder, an option chain — turns that into hundreds of synchronous round trips inside the tick
 * transaction, which was more wall time than the whole tick budget. A mark is not an entity change in any
 * interesting sense: it is the same seven numbers on every row, so it is written as data, the way the
 * price-history INSERT already is.
 *
 * The statement joins the table to an inline row set on its primary key:
 *
 *   UPDATE t JOIN (SELECT ? AS id, ? AS a, ? AS b UNION ALL SELECT ?, ?, ? ...) v ON v.id = t.id
 *   SET t.a = v.a, t.b = v.b
 *
 * One placeholder per value, portable across MySQL and MariaDB, and chunked so a large ladder never
 * assembles a statement the server would refuse.
 */
final class BulkRowUpdate
{
    // --- Statement Sizing ---
    /** Rows per statement. Keeps every statement well inside the server's placeholder ceiling at any column count. */
    public const ROWS_PER_STATEMENT = 100;

    /**
     * Applies the rows and returns how many statements were sent.
     *
     * @param list<string>                     $columns Column names written on every row, in the row's value order.
     * @param array<int, array<int, mixed>>    $rows    Primary key => values, aligned with $columns.
     */
    public static function apply(Connection $connection, string $table, array $columns, array $rows): int
    {
        $statements = 0;

        foreach (array_chunk($rows, self::ROWS_PER_STATEMENT, true) as $chunk) {
            $statement = self::statement($table, $columns, $chunk);
            $connection->executeStatement($statement['sql'], $statement['params']);
            $statements++;
        }

        return $statements;
    }

    /**
     * One statement for one chunk of rows, with its flat parameter list.
     *
     * @param list<string>                  $columns
     * @param array<int, array<int, mixed>> $rows Primary key => values, aligned with $columns.
     * @return array{sql: string, params: list<mixed>}
     */
    public static function statement(string $table, array $columns, array $rows): array
    {
        if ($columns === [] || $rows === []) {
            throw new \InvalidArgumentException('A bulk update needs at least one column and one row.');
        }

        $width = count($columns);
        $selects = [];
        $params = [];
        $first = true;

        foreach ($rows as $id => $values) {
            if (count($values) !== $width) {
                throw new \InvalidArgumentException(sprintf('Row %d carries %d values for %d columns.', $id, count($values), $width));
            }

            // Only the first SELECT names the derived columns; the rest are positional.
            $cells = $first
                ? array_merge(['? AS id'], array_map(static fn (string $column): string => '? AS ' . $column, $columns))
                : array_fill(0, $width + 1, '?');
            $first = false;

            $selects[] = 'SELECT ' . implode(', ', $cells);
            $params[] = $id;
            foreach ($values as $value) {
                $params[] = $value;
            }
        }

        $assignments = array_map(
            static fn (string $column): string => sprintf('t.%1$s = v.%1$s', $column),
            $columns
        );

        return [
            'sql' => sprintf(
                'UPDATE %s t JOIN (%s) v ON v.id = t.id SET %s',
                $table,
                implode(' UNION ALL ', $selects),
                implode(', ', $assignments)
            ),
            'params' => $params,
        ];
    }
}
