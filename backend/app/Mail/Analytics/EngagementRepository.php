<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Analytics;

use BitApps\SMTP\Deps\BitApps\WPDatabase\QueryBuilder;
use BitApps\SMTP\Model\LogEngagementEvent;
use WP_Error;

/**
 * Fixed aggregate query over engagement events joined to in-range logs; separates automated fires from human engagement.
 */
class EngagementRepository
{
    private const DATABASE_ERROR = 'The engagement aggregate query failed.';

    private const CLICK = 'click';

    /**
     * Aggregate opens and clicks with human-vs-automated split across the range.
     *
     * @return array<string,int>|WP_Error
     */
    public function engagement(AnalyticsQuery $query)
    {
        $columns  = [];
        $bindings = [];
        foreach (['open', self::CLICK] as $type) {
            array_push(
                $columns,
                "COALESCE(SUM(CASE WHEN e.type = %s THEN e.hits ELSE 0 END), 0) AS {$type}_hits",
                "COALESCE(SUM(CASE WHEN e.type = %s THEN e.automated_hits ELSE 0 END), 0) AS {$type}_automated_hits",
                AnalyticsSql::countWhen('e.type = %s', "{$type}_rows"),
                "COUNT(DISTINCT CASE WHEN e.type = %s AND e.hits > e.automated_hits THEN e.log_id END) AS {$type}_human_logs"
            );
            array_push($bindings, $type, $type, $type, $type);
        }

        $rows = AnalyticsSql::rows(
            fn () => $this->inRange($query)->selectRaw(implode(', ', $columns), $bindings)->get([]),
            self::DATABASE_ERROR
        );
        if ($rows instanceof WP_Error) {
            return $rows;
        }

        return AnalyticsSql::integers($rows[0] ?? []);
    }

    /**
     * Top clicked link targets by total hits with automated-fire split across the range.
     *
     * @return array<int,array{target:string,hits:int,automated_hits:int}>|WP_Error
     */
    public function topClickedLinks(AnalyticsQuery $query, int $limit)
    {
        $rows = AnalyticsSql::rows(
            fn () => $this->inRange($query)
                ->selectRaw('COALESCE(SUM(e.hits), 0) AS hits, COALESCE(SUM(e.automated_hits), 0) AS automated_hits')
                ->where('e.type', self::CLICK)
                ->where('e.target', '<>', '')
                ->groupBy(['e.target'])
                ->orderByRaw('hits DESC')
                ->orderBy('e.target')
                ->take((string) max(1, $limit))
                ->get(['e.target AS target']),
            self::DATABASE_ERROR
        );
        if ($rows instanceof WP_Error) {
            return $rows;
        }

        return array_map(static function (array $row): array {
            return [
                'target'         => (string) ($row['target'] ?? ''),
                'hits'           => (int) ($row['hits'] ?? 0),
                'automated_hits' => (int) ($row['automated_hits'] ?? 0),
            ];
        }, $rows);
    }

    /**
     * Engagement events (aliased e) joined to their logs (aliased l), narrowed to the range and filters.
     */
    private function inRange(AnalyticsQuery $query): QueryBuilder
    {
        return LogEngagementEvent::query()
            ->from('e')
            ->join('logs AS l', 'l.id', '=', 'e.log_id')
            ->whereRaw(...AnalyticsSql::conditions($query, 'l.'));
    }
}
