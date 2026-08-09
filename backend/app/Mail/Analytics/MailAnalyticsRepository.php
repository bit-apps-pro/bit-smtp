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
    private const BUCKET_SQL = [
        'hour' => "DATE_FORMAT(CONVERT_TZ(created_at, '+00:00', %s), '%%Y-%%m-%%d %%H:00:00')",
        'day'  => "DATE_FORMAT(CONVERT_TZ(created_at, '+00:00', %s), '%%Y-%%m-%%d')",
        'week' => "YEARWEEK(CONVERT_TZ(created_at, '+00:00', %s), 3)",
    ];

    private const GROUP_SQL = [
        'source'       => "COALESCE(NULLIF(source_plugin, ''), 'unknown')",
        'connection'   => "COALESCE(NULLIF(connection_id, ''), NULLIF(connection, ''), 'unknown')",
        'routing_type' => "COALESCE(NULLIF(routing_type, ''), 'unknown')",
    ];

    private const SUBJECT_PATTERN_LIMIT = 200;

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
            COALESCE(SUM(CASE WHEN JSON_VALID(to_addr) THEN JSON_LENGTH(to_addr) ELSE 0 END), 0) AS recipient_count,
            COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END), 0) AS accepted,
            COALESCE(SUM(CASE WHEN status <> 1 THEN 1 ELSE 0 END), 0) AS failed,
            COALESCE(SUM(CASE WHEN source_plugin IS NULL OR source_plugin = '' THEN 1 ELSE 0 END), 0) AS unknown_source_count,
            COALESCE(SUM(CASE WHEN delivery_status = 'delivered' THEN 1 ELSE 0 END), 0) AS delivered,
            COALESCE(SUM(CASE WHEN delivery_status = 'deferred' THEN 1 ELSE 0 END), 0) AS deferred,
            COALESCE(SUM(CASE WHEN delivery_status = 'bounced' THEN 1 ELSE 0 END), 0) AS bounced,
            COALESCE(SUM(CASE WHEN delivery_status = 'blocked' THEN 1 ELSE 0 END), 0) AS blocked,
            COALESCE(SUM(CASE WHEN delivery_status = 'spam' THEN 1 ELSE 0 END), 0) AS spam,
            COALESCE(SUM(CASE WHEN delivery_status IS NOT NULL AND delivery_status <> '' THEN 1 ELSE 0 END), 0) AS verified_delivery
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
        $bucket           = self::BUCKET_SQL[$query->bucket()];
        [$where, $values] = $this->where($query);
        $sql              = "SELECT {$bucket} AS bucket,
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END), 0) AS accepted,
            COALESCE(SUM(CASE WHEN status <> 1 THEN 1 ELSE 0 END), 0) AS failed,
            COALESCE(SUM(CASE WHEN delivery_status = 'delivered' THEN 1 ELSE 0 END), 0) AS delivered,
            COALESCE(SUM(CASE WHEN delivery_status IS NOT NULL AND delivery_status <> '' THEN 1 ELSE 0 END), 0) AS verified_delivery
            FROM `{$this->table}` WHERE {$where}
            GROUP BY bucket ORDER BY bucket ASC";

        $rows = $this->rows($sql, array_merge([$query->timezone()->getName()], $values));
        if ($rows instanceof WP_Error) {
            return $rows;
        }

        return array_map(function (array $row): array {
            $integer           = $this->integerRow($row);
            $integer['bucket'] = (string) ($row['bucket'] ?? '');

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
            COALESCE(SUM(CASE WHEN delivery_status IS NOT NULL AND delivery_status <> '' THEN 1 ELSE 0 END), 0) AS verified_delivery
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
     * @return array<int,array{subject:string,total:int}>|WP_Error
     */
    public function subjectCounts(AnalyticsQuery $query)
    {
        [$where, $values] = $this->where($query);
        $sql              = "SELECT subject, COUNT(*) AS total FROM `{$this->table}` WHERE {$where}
            GROUP BY subject ORDER BY total DESC, subject ASC LIMIT %d";
        $rows = $this->rows($sql, array_merge($values, [self::SUBJECT_PATTERN_LIMIT]));
        if ($rows instanceof WP_Error) {
            return $rows;
        }

        return array_map(static function (array $row): array {
            return [
                'subject' => (string) ($row['subject'] ?? ''),
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
            if ($key !== 'bucket' && $key !== 'dimension') {
                $result[$key] = (int) $value;
            }
        }

        return $result;
    }
}
