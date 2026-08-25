<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Analytics;

use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Model\LogEngagementEvent;
use WP_Error;

/**
 * Fixed aggregate query over log_engagement_events joined to their in-range logs. Send time (the
 * log's created_at_utc) bounds the range; values travel only through wpdb::prepare. Human figures
 * keep automated fires (Apple MPP / image proxies / prefetch) separate so nothing is inflated.
 */
class EngagementRepository
{
    private object $database;

    private string $logsTable;

    private string $eventsTable;

    public function __construct(?object $database = null, ?string $logsTable = null, ?string $eventsTable = null)
    {
        if ($database === null) {
            global $wpdb;
            $database = $wpdb;
        }

        $this->database    = $database;
        $this->logsTable   = $logsTable   ?? (new Log())->getTable();
        $this->eventsTable = $eventsTable ?? (new LogEngagementEvent())->getTable();
    }

    /**
     * Opens and clicks folded across the range: total/automated hits, unique folded rows, and the
     * distinct logs with at least one human fire (the numerator for the honest engagement rates).
     *
     * @return array<string,int>|WP_Error
     */
    public function engagement(AnalyticsQuery $query)
    {
        [$where, $values] = $this->where($query);
        $sql              = "SELECT
            COALESCE(SUM(CASE WHEN e.type = 'open' THEN e.hits ELSE 0 END), 0) AS open_hits,
            COALESCE(SUM(CASE WHEN e.type = 'open' THEN e.automated_hits ELSE 0 END), 0) AS open_automated_hits,
            COALESCE(SUM(CASE WHEN e.type = 'open' THEN 1 ELSE 0 END), 0) AS open_rows,
            COUNT(DISTINCT CASE WHEN e.type = 'open' AND e.hits > e.automated_hits THEN e.log_id END) AS open_human_logs,
            COALESCE(SUM(CASE WHEN e.type = 'click' THEN e.hits ELSE 0 END), 0) AS click_hits,
            COALESCE(SUM(CASE WHEN e.type = 'click' THEN e.automated_hits ELSE 0 END), 0) AS click_automated_hits,
            COALESCE(SUM(CASE WHEN e.type = 'click' THEN 1 ELSE 0 END), 0) AS click_rows,
            COUNT(DISTINCT CASE WHEN e.type = 'click' AND e.hits > e.automated_hits THEN e.log_id END) AS click_human_logs
            FROM `{$this->eventsTable}` e
            INNER JOIN `{$this->logsTable}` l ON l.id = e.log_id
            WHERE {$where}";

        $rows = $this->rows($sql, $values);
        if ($rows instanceof WP_Error) {
            return $rows;
        }

        return $this->integerRow($rows[0] ?? []);
    }

    /**
     * @return array{0:string,1:array<int,mixed>}
     */
    private function where(AnalyticsQuery $query): array
    {
        $clauses = ['l.created_at_utc >= %s', 'l.created_at_utc < %s'];
        $values  = [$query->startSql(), $query->endSql()];

        if ($query->plugin() !== null) {
            if ($query->plugin() === 'unknown') {
                $clauses[] = "(l.source_plugin IS NULL OR l.source_plugin = '')";
            } else {
                $clauses[] = 'l.source_plugin = %s';
                $values[]  = $query->plugin();
            }
        }

        if ($query->connectionId() !== null) {
            $clauses[] = 'l.connection_id = %s';
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
        $prepared = $values === [] ? $sql : $this->database->prepare($sql, ...$values);
        $output   = \defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
        $rows     = $this->database->get_results($prepared, $output);
        $error    = (string) ($this->database->last_error ?? '');
        // An empty result is a legitimate zero-engagement range; only a reported database error is
        // authoritative, so surface that rather than masking a failure as an empty aggregate.
        if ($error !== '') {
            return new WP_Error('bit_smtp_analytics_database_error', 'The engagement aggregate query failed.');
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
            $result[$key] = (int) $value;
        }

        return $result;
    }
}
