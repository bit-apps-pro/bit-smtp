<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Analytics;

use BitApps\SMTP\Deps\BitApps\WPDatabase\Connection;
use BitApps\SMTP\Deps\BitApps\WPDatabase\Model;
use WP_Error;

/**
 * Shared SQL building blocks for analytics aggregates: placeholder-bound conditions, counts, error-aware fetching.
 */
final class AnalyticsSql
{
    /**
     * The range and plugin/connection filter as a `%s`-placeholder condition plus its bindings.
     *
     * @return array{0:string,1:array<int,string>}
     */
    public static function conditions(AnalyticsQuery $query, string $qualifier = ''): array
    {
        $clauses  = ["{$qualifier}created_at_utc >= %s", "{$qualifier}created_at_utc < %s"];
        $bindings = [$query->startSql(), $query->endSql()];

        if ($query->plugin() === 'unknown') {
            $clauses[]  = "({$qualifier}source_plugin IS NULL OR {$qualifier}source_plugin = %s)";
            $bindings[] = '';
        } elseif ($query->plugin() !== null) {
            $clauses[]  = "{$qualifier}source_plugin = %s";
            $bindings[] = $query->plugin();
        }

        if ($query->connectionId() !== null) {
            $clauses[]  = "{$qualifier}connection_id = %s";
            $bindings[] = $query->connectionId();
        }

        return [implode(' AND ', $clauses), $bindings];
    }

    /**
     * A zero-defaulted count of the rows matching $condition, aliased as $alias.
     */
    public static function countWhen(string $condition, string $alias): string
    {
        return "COALESCE(SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END), 0) AS {$alias}";
    }

    /**
     * A comma-separated `%s` placeholder list for an IN (...) over $count bound values.
     */
    public static function placeholders(int $count): string
    {
        return implode(', ', array_fill(0, $count, '%s'));
    }

    /**
     * Run $fetch and return rows as arrays, or WP_Error on database error; detected via last_error.
     *
     * @param callable():iterable<int,Model|object> $fetch
     *
     * @return array<int,array<string,mixed>>|WP_Error
     */
    public static function rows(callable $fetch, string $failureMessage)
    {
        $result = $fetch();

        if ((string) Connection::prop('last_error') !== '') {
            return new WP_Error('bit_smtp_analytics_database_error', $failureMessage);
        }

        $rows = [];
        foreach ($result ?: [] as $row) {
            $rows[] = $row instanceof Model ? $row->toArray() : (array) $row;
        }

        return $rows;
    }

    /**
     * The row with every column cast to int, minus the $except columns.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,int>
     */
    public static function integers(array $row, string ...$except): array
    {
        $integers = [];
        foreach ($row as $key => $value) {
            if (!\in_array($key, $except, true)) {
                $integers[$key] = (int) $value;
            }
        }

        return $integers;
    }
}
