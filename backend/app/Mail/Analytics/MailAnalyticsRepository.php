<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Analytics;

use BitApps\SMTP\Model\Log;
use WP_Error;

/**
 * Fixed aggregate queries over the retained log table. Values travel only through wpdb::prepare;
 * dimensions and expressions are selected exclusively from the maps below.
 */
class MailAnalyticsRepository
{
    /**
     * Database timestamps are stored in UTC. Deliberately aggregate only by a UTC hour here:
     * shared hosts frequently do not load MySQL named timezone tables. The bounded result is
     * converted and merged into requested local buckets by MailAnalyticsService.
     */
    private const UTC_HOUR_SQL = "DATE_FORMAT(created_at, '%%Y-%%m-%%d %%H:00:00')";

    private const VERIFIED_DELIVERY_STATUSES = "'delivered', 'deferred', 'bounced', 'blocked', 'spam'";

    private const PENDING_DELIVERY_STATUS = 'pending';

    private const GROUP_SQL = [
        'source'       => "COALESCE(NULLIF(source_plugin, ''), 'unknown')",
        'connection'   => "COALESCE(NULLIF(connection_id, ''), NULLIF(connection, ''), 'unknown')",
        'routing_type' => "COALESCE(NULLIF(routing_type, ''), 'unknown')",
    ];

    private const SUBJECT_PATTERN_LIMIT = 10;

    private object $database;

    private string $table;

    public function __construct(?object $database = null, ?string $table = null)
    {
        if ($database === null) {
            global $wpdb;
            $database = $wpdb;
        }

        $this->database = $database;
        $this->table    = $table ?? (new Log())->getTable();
    }

    /**
     * @return array<string,int>|WP_Error
     */
    public function summary(AnalyticsQuery $query)
    {
        [$where, $values] = $this->where($query);
        $sql              = "SELECT
            COUNT(*) AS total,
            COALESCE(SUM(recipient_count), 0) AS recipient_count,
            COALESCE(SUM(CASE WHEN recipient_count IS NULL THEN 1 ELSE 0 END), 0) AS unknown_recipient_count,
            COALESCE(SUM(CASE WHEN subject_pattern IS NULL OR subject_pattern = '' THEN 1 ELSE 0 END), 0) AS unknown_subject_pattern_count,
            COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END), 0) AS accepted,
            COALESCE(SUM(CASE WHEN status <> 1 THEN 1 ELSE 0 END), 0) AS failed,
            COALESCE(SUM(CASE WHEN source_plugin IS NULL OR source_plugin = '' THEN 1 ELSE 0 END), 0) AS unknown_source_count,
            COALESCE(SUM(CASE WHEN delivery_status = 'delivered' THEN 1 ELSE 0 END), 0) AS delivered,
            COALESCE(SUM(CASE WHEN delivery_status = 'deferred' THEN 1 ELSE 0 END), 0) AS deferred,
            COALESCE(SUM(CASE WHEN delivery_status = 'bounced' THEN 1 ELSE 0 END), 0) AS bounced,
            COALESCE(SUM(CASE WHEN delivery_status = 'blocked' THEN 1 ELSE 0 END), 0) AS blocked,
            COALESCE(SUM(CASE WHEN delivery_status = 'spam' THEN 1 ELSE 0 END), 0) AS spam,
            COALESCE(SUM(CASE WHEN delivery_status IN (" . self::VERIFIED_DELIVERY_STATUSES . ") THEN 1 ELSE 0 END), 0) AS verified_delivery,
            COALESCE(SUM(CASE WHEN delivery_status = 'accepted' THEN 1 ELSE 0 END), 0) AS accepted_delivery,
            COALESCE(SUM(CASE WHEN delivery_status = '" . self::PENDING_DELIVERY_STATUS . "' THEN 1 ELSE 0 END), 0) AS pending_delivery
            FROM `{$this->table}` WHERE {$where}";

        $rows = $this->rows($sql, $values);
        if ($rows instanceof WP_Error) {
            return $rows;
        }

        return $this->integerRow($rows[0] ?? []);
    }

    /**
     * @return array<int,array<string,int|string>>|WP_Error
     */
    public function timeSeries(AnalyticsQuery $query)
    {
        [$where, $values] = $this->where($query);
        $sql              = 'SELECT ' . self::UTC_HOUR_SQL . " AS utc_hour,
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END), 0) AS accepted,
            COALESCE(SUM(CASE WHEN status <> 1 THEN 1 ELSE 0 END), 0) AS failed,
            COALESCE(SUM(CASE WHEN delivery_status = 'delivered' THEN 1 ELSE 0 END), 0) AS delivered,
            COALESCE(SUM(CASE WHEN delivery_status IN (" . self::VERIFIED_DELIVERY_STATUSES . ") THEN 1 ELSE 0 END), 0) AS verified_delivery
            FROM `{$this->table}` WHERE {$where}
            GROUP BY utc_hour ORDER BY utc_hour ASC";

        $rows = $this->rows($sql, $values);
        if ($rows instanceof WP_Error) {
            return $rows;
        }

        return array_map(function (array $row): array {
            $integer             = $this->integerRow($row);
            $integer['utc_hour'] = (string) ($row['utc_hour'] ?? '');

            return $integer;
        }, $rows);
    }

    /**
     * @return array<int,array<string,int|string>>|WP_Error
     */
    public function groups(AnalyticsQuery $query, string $dimension, int $limit)
    {
        if (!isset(self::GROUP_SQL[$dimension])) {
            return new WP_Error('bit_smtp_invalid_analytics_dimension', 'The analytics dimension is unsupported.');
        }

        $expression       = self::GROUP_SQL[$dimension];
        [$where, $values] = $this->where($query);
        $sql              = "SELECT {$expression} AS dimension,
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END), 0) AS accepted,
            COALESCE(SUM(CASE WHEN status <> 1 THEN 1 ELSE 0 END), 0) AS failed,
            COALESCE(SUM(CASE WHEN delivery_status = 'delivered' THEN 1 ELSE 0 END), 0) AS delivered,
            COALESCE(SUM(CASE WHEN delivery_status IN (" . self::VERIFIED_DELIVERY_STATUSES . ") THEN 1 ELSE 0 END), 0) AS verified_delivery
            FROM `{$this->table}` WHERE {$where}
            GROUP BY dimension ORDER BY total DESC, dimension ASC LIMIT %d";
        $rows = $this->rows($sql, array_merge($values, [max(1, min(100, $limit))]));
        if ($rows instanceof WP_Error) {
            return $rows;
        }

        return array_map(function (array $row): array {
            $integer              = $this->integerRow($row);
            $integer['dimension'] = (string) ($row['dimension'] ?? 'unknown');

            return $integer;
        }, $rows);
    }

    /**
     * @return array<int,array{pattern:string,total:int}>|WP_Error
     */
    public function subjectCounts(AnalyticsQuery $query)
    {
        [$where, $values] = $this->where($query);
        $sql              = "SELECT subject_pattern AS pattern, COUNT(*) AS total FROM `{$this->table}`
            WHERE {$where} AND subject_pattern IS NOT NULL AND subject_pattern <> ''
            GROUP BY subject_pattern ORDER BY total DESC, subject_pattern ASC LIMIT %d";
        $rows = $this->rows($sql, array_merge($values, [self::SUBJECT_PATTERN_LIMIT]));
        if ($rows instanceof WP_Error) {
            return $rows;
        }

        return array_map(static function (array $row): array {
            return [
                'pattern' => (string) ($row['pattern'] ?? ''),
                'total'   => (int) ($row['total'] ?? 0),
            ];
        }, $rows);
    }

    /**
     * @return array{0:string,1:array<int,mixed>}
     */
    private function where(AnalyticsQuery $query): array
    {
        $clauses = ['created_at >= %s', 'created_at < %s'];
        $values  = [$query->startSql(), $query->endSql()];

        if ($query->plugin() !== null) {
            if ($query->plugin() === 'unknown') {
                $clauses[] = "(source_plugin IS NULL OR source_plugin = '')";
            } else {
                $clauses[] = 'source_plugin = %s';
                $values[]  = $query->plugin();
            }
        }

        if ($query->connectionId() !== null) {
            $clauses[] = 'connection_id = %s';
            $values[]  = $query->connectionId();
        }

        return [implode(' AND ', $clauses), $values];
    }

    /**
     * @param array<int,mixed> $values
     *
     * @return array<int,array<string,mixed>>|WP_Error
     */
    private function rows(string $sql, array $values)
    {
        $prepared = $this->database->prepare($sql, ...$values);
        $output   = \defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
        $rows     = $this->database->get_results($prepared, $output);
        $error    = (string) ($this->database->last_error ?? '');
        if ($rows === null && $error !== '') {
            return new WP_Error('bit_smtp_analytics_database_error', 'The retained-log aggregate query failed.');
        }

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,int>
     */
    private function integerRow(array $row): array
    {
        $result = [];
        foreach ($row as $key => $value) {
            if ($key !== 'bucket' && $key !== 'utc_hour' && $key !== 'dimension') {
                $result[$key] = (int) $value;
            }
        }

        return $result;
    }
}
