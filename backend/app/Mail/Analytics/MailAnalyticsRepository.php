<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Analytics;

use BitApps\SMTP\Deps\BitApps\WPDatabase\QueryBuilder;
use BitApps\SMTP\Mail\Status\DeliveryStatus;
use BitApps\SMTP\Model\Log;
use WP_Error;

/**
 * Fixed aggregate queries over retained logs using only query bindings and mapped expressions.
 */
class MailAnalyticsRepository
{
    /**
     * Analytics timestamp; UTC hour aggregation to support hosts without MySQL timezone tables.
     */
    private const UTC_HOUR_FORMAT = '%Y-%m-%d %H:00:00';

    private const VERIFIED_DELIVERY_STATUSES = [
        DeliveryStatus::DELIVERED,
        DeliveryStatus::DEFERRED,
        DeliveryStatus::BOUNCED,
        DeliveryStatus::BLOCKED,
        DeliveryStatus::SPAM,
    ];

    private const GROUP_SQL = [
        'source'       => ['COALESCE(NULLIF(source_plugin, %s), %s)', ['', 'unknown']],
        'connection'   => ['COALESCE(NULLIF(connection_id, %s), NULLIF(connection, %s), %s)', ['', '', 'unknown']],
        'routing_type' => ['COALESCE(NULLIF(routing_type, %s), %s)', ['', 'unknown']],
    ];

    private const SUBJECT_PATTERN_LIMIT = 10;

    private const DATABASE_ERROR = 'The retained-log aggregate query failed.';

    /**
     * Send and delivery totals for the range, including the unknown-dimension counts.
     *
     * @return array<string,int>|WP_Error
     */
    public function summary(AnalyticsQuery $query)
    {
        $rows = AnalyticsSql::rows(
            fn () => $this->filtered($query)->selectRaw(...$this->summaryColumns())->get([]),
            self::DATABASE_ERROR
        );
        if ($rows instanceof WP_Error) {
            return $rows;
        }

        return AnalyticsSql::integers($rows[0] ?? []);
    }

    /**
     * Outcome counts per UTC hour across the range, oldest hour first.
     *
     * @return array<int,array<string,int|string>>|WP_Error
     */
    public function timeSeries(AnalyticsQuery $query)
    {
        [$outcomes, $outcomeBindings] = $this->outcomeColumns();
        [$where, $whereBindings]      = AnalyticsSql::conditions($query);

        $rows = AnalyticsSql::rows(
            fn () => Log::query()->rawPrepared(
                "SELECT DATE_FORMAT(created_at_utc, %s) AS utc_hour, {$outcomes}
                FROM {{identifier:logs}} WHERE {$where}
                GROUP BY utc_hour ORDER BY utc_hour ASC",
                array_merge([self::UTC_HOUR_FORMAT], $outcomeBindings, $whereBindings),
                ['logs' => $this->table()]
            ),
            self::DATABASE_ERROR
        );
        if ($rows instanceof WP_Error) {
            return $rows;
        }

        return array_map(static function (array $row): array {
            return AnalyticsSql::integers($row, 'utc_hour') + ['utc_hour' => (string) ($row['utc_hour'] ?? '')];
        }, $rows);
    }

    /**
     * Earliest/latest UTC timestamps across all retained logs, plus how many lack one.
     *
     * @return array{earliest:?string,latest:?string,qualified_timestamp_count:int,unqualified_timestamp_count:int}|WP_Error
     */
    public function retainedRecordBounds()
    {
        $rows = AnalyticsSql::rows(
            static fn () => Log::query()->selectRaw('MIN(created_at_utc) AS earliest,
                MAX(created_at_utc) AS latest,
                COUNT(created_at_utc) AS qualified_timestamp_count, '
                . AnalyticsSql::countWhen('created_at_utc IS NULL', 'unqualified_timestamp_count'))->get([]),
            self::DATABASE_ERROR
        );
        if ($rows instanceof WP_Error) {
            return $rows;
        }

        $row = $rows[0] ?? [];

        return [
            'earliest'                    => isset($row['earliest']) && $row['earliest']   !== '' ? (string) $row['earliest'] : null,
            'latest'                      => isset($row['latest'])   && $row['latest']     !== '' ? (string) $row['latest'] : null,
            'qualified_timestamp_count'   => (int) ($row['qualified_timestamp_count'] ?? 0),
            'unqualified_timestamp_count' => (int) ($row['unqualified_timestamp_count'] ?? 0),
        ];
    }

    /**
     * Outcome counts per value of $dimension, the $limit (1-100) largest groups first.
     *
     * @return array<int,array<string,int|string>>|WP_Error
     */
    public function groups(AnalyticsQuery $query, string $dimension, int $limit)
    {
        if (!isset(self::GROUP_SQL[$dimension])) {
            return new WP_Error('bit_smtp_invalid_analytics_dimension', 'The analytics dimension is unsupported.');
        }

        [$expression, $expressionBindings] = self::GROUP_SQL[$dimension];
        [$outcomes, $outcomeBindings]      = $this->outcomeColumns();
        [$where, $whereBindings]           = AnalyticsSql::conditions($query);

        $rows = AnalyticsSql::rows(
            fn () => Log::query()->rawPrepared(
                "SELECT {$expression} AS dimension, {$outcomes}
                FROM {{identifier:logs}} WHERE {$where}
                GROUP BY dimension ORDER BY total DESC, dimension ASC LIMIT %d",
                array_merge($expressionBindings, $outcomeBindings, $whereBindings, [max(1, min(100, $limit))]),
                ['logs' => $this->table()]
            ),
            self::DATABASE_ERROR
        );
        if ($rows instanceof WP_Error) {
            return $rows;
        }

        return array_map(static function (array $row): array {
            return AnalyticsSql::integers($row, 'dimension') + ['dimension' => (string) ($row['dimension'] ?? 'unknown')];
        }, $rows);
    }

    /**
     * All-time distinct wp_mail source-plugin slugs, excluding empty and unknown senders.
     *
     * @return array<int,string>|WP_Error
     */
    public function distinctSourcePlugins()
    {
        $rows = AnalyticsSql::rows(
            static fn () => Log::query()
                ->distinct()
                ->whereNotNull('source_plugin')
                ->where('source_plugin', '<>', '')
                ->where('source_plugin', '<>', 'unknown')
                ->orderBy('source_plugin')
                ->get(['source_plugin']),
            self::DATABASE_ERROR
        );
        if ($rows instanceof WP_Error) {
            return $rows;
        }

        return array_map(static function (array $row): string {
            return (string) ($row['source_plugin'] ?? '');
        }, $rows);
    }

    /**
     * The most frequent persisted (redacted) subject patterns in the range.
     *
     * @return array<int,array{pattern:string,total:int}>|WP_Error
     */
    public function subjectCounts(AnalyticsQuery $query)
    {
        $rows = AnalyticsSql::rows(
            fn () => $this->filtered($query)
                ->selectRaw('COUNT(*) AS total')
                ->whereNotNull('subject_pattern')
                ->where('subject_pattern', '<>', '')
                ->groupBy(['subject_pattern'])
                ->orderByRaw('total DESC')
                ->orderBy('subject_pattern')
                ->take((string) self::SUBJECT_PATTERN_LIMIT)
                ->get(['subject_pattern AS pattern']),
            self::DATABASE_ERROR
        );
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
     * A log query narrowed to the analytics range and filters.
     */
    private function filtered(AnalyticsQuery $query): QueryBuilder
    {
        return Log::query()->whereRaw(...AnalyticsSql::conditions($query));
    }

    /**
     * The summary's aggregate select list and its bindings, in output-column order.
     *
     * @return array{0:string,1:array<int,string>}
     */
    private function summaryColumns(): array
    {
        $columns = [
            'COUNT(*) AS total',
            'COALESCE(SUM(recipient_count), 0) AS recipient_count',
            AnalyticsSql::countWhen('recipient_count IS NULL', 'unknown_recipient_count'),
            AnalyticsSql::countWhen('subject_pattern IS NULL OR subject_pattern = %s', 'unknown_subject_pattern_count'),
            AnalyticsSql::countWhen('status = 1', 'accepted'),
            AnalyticsSql::countWhen('status <> 1', 'failed'),
            AnalyticsSql::countWhen('source_plugin IS NULL OR source_plugin = %s', 'unknown_source_count'),
        ];
        $bindings = ['', ''];

        foreach (self::VERIFIED_DELIVERY_STATUSES as $status) {
            $columns[]  = AnalyticsSql::countWhen('delivery_status = %s', $status);
            $bindings[] = $status;
        }

        $columns[] = $this->verifiedDeliveryColumn();
        $columns[] = AnalyticsSql::countWhen('delivery_status = %s', 'accepted_delivery');
        $columns[] = AnalyticsSql::countWhen('delivery_status = %s', 'pending_delivery');

        return [
            implode(', ', $columns),
            array_merge($bindings, self::VERIFIED_DELIVERY_STATUSES, [DeliveryStatus::ACCEPTED, DeliveryStatus::PENDING]),
        ];
    }

    /**
     * The per-group outcome select list (total/accepted/failed/delivered/verified) and its bindings.
     *
     * @return array{0:string,1:array<int,string>}
     */
    private function outcomeColumns(): array
    {
        return [
            implode(', ', [
                'COUNT(*) AS total',
                AnalyticsSql::countWhen('status = 1', 'accepted'),
                AnalyticsSql::countWhen('status <> 1', 'failed'),
                AnalyticsSql::countWhen('delivery_status = %s', 'delivered'),
                $this->verifiedDeliveryColumn(),
            ]),
            array_merge([DeliveryStatus::DELIVERED], self::VERIFIED_DELIVERY_STATUSES),
        ];
    }

    /**
     * The count of logs carrying a provider-verified delivery outcome, bound to VERIFIED_DELIVERY_STATUSES.
     */
    private function verifiedDeliveryColumn(): string
    {
        return AnalyticsSql::countWhen(
            'delivery_status IN (' . AnalyticsSql::placeholders(\count(self::VERIFIED_DELIVERY_STATUSES)) . ')',
            'verified_delivery'
        );
    }

    /**
     * The physical log table name, for the raw templates that group by a computed expression.
     */
    private function table(): string
    {
        return (new Log())->getTable();
    }
}
