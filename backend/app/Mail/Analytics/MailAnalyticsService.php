<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Analytics;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use WP_Error;

final class MailAnalyticsService
{
    private const INTERPRETATION = 'Results reflect retained Bit SMTP email logs, not orders, form submissions, or external business records.';

    private const TOP_LIMIT = 10;

    private const ANOMALY_GROUP_LIMIT = 100;

    private const MINIMUM_RATE_SAMPLE = 20;

    private MailAnalyticsRepository $repository;

    public function __construct(MailAnalyticsRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function overview(AnalyticsQuery $query)
    {
        $summary     = $this->repository->summary($query);
        $series      = $this->repository->timeSeries($query);
        $sources     = $this->repository->groups($query, 'source', self::TOP_LIMIT);
        $connections = $this->repository->groups($query, 'connection', self::TOP_LIMIT);
        $error       = $this->firstError([$summary, $series, $sources, $connections]);
        if ($error !== null) {
            return $error;
        }

        return array_merge($this->metadata($query, $summary), [
            'recipients'      => (int) ($summary['recipient_count'] ?? 0),
            'acceptance'      => $this->acceptance($summary),
            'delivery'        => $this->delivery($summary),
            'series'          => $this->fillBuckets($query, $series),
            'top_sources'     => $sources,
            'top_connections' => $connections,
        ]);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function plugin(AnalyticsQuery $query)
    {
        if ($query->plugin() === null) {
            return new WP_Error('bit_smtp_missing_analytics_plugin', 'A plugin filter is required for plugin analytics.');
        }

        $summary      = $this->repository->summary($query);
        $series       = $this->repository->timeSeries($query);
        $connections  = $this->repository->groups($query, 'connection', self::TOP_LIMIT);
        $routingTypes = $this->repository->groups($query, 'routing_type', self::TOP_LIMIT);
        $subjects     = $this->repository->subjectCounts($query);
        $error        = $this->firstError([$summary, $series, $connections, $routingTypes, $subjects]);
        if ($error !== null) {
            return $error;
        }

        return array_merge($this->metadata($query, $summary), [
            'plugin'                        => $query->plugin(),
            'acceptance'                    => $this->acceptance($summary),
            'delivery'                      => $this->delivery($summary),
            'series'                        => $this->fillBuckets($query, $series),
            'connections'                   => $connections,
            'routing_types'                 => $routingTypes,
            'subject_patterns'              => $this->subjectPatterns($subjects),
            'subject_pattern_unknown_count' => (int) ($summary['unknown_subject_pattern_count'] ?? 0),
            'proxy_interpretation'          => 'Plugin activity is derived from retained email notifications and is only a proxy for application activity.',
        ]);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function deliverability(AnalyticsQuery $query)
    {
        $summary     = $this->repository->summary($query);
        $sources     = $this->repository->groups($query, 'source', self::TOP_LIMIT);
        $connections = $this->repository->groups($query, 'connection', self::TOP_LIMIT);
        $error       = $this->firstError([$summary, $sources, $connections]);
        if ($error !== null) {
            return $error;
        }

        return array_merge($this->metadata($query, $summary), [
            'acceptance'  => $this->acceptance($summary),
            'delivery'    => $this->delivery($summary),
            'sources'     => $sources,
            'connections' => $connections,
        ]);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function anomalies(AnalyticsQuery $query)
    {
        $prior          = $query->priorPeriod();
        $currentSummary = $this->repository->summary($query);
        $priorSummary   = $this->repository->summary($prior);
        $error          = $this->firstError([$currentSummary, $priorSummary]);
        if ($error !== null) {
            return $error;
        }

        $response = array_merge($this->metadata($query, $currentSummary), [
            'current'             => $this->counts($currentSummary),
            'prior'               => $this->counts($priorSummary),
            'prior_range'         => $this->range($prior),
            'comparison_coverage' => [
                'complete'      => $query->hasCompletePriorCoverage(),
                'retained_from' => $query->retainedFrom() === null ? null : $query->retainedFrom()->format(DATE_ATOM),
            ],
        ]);
        if (!$query->hasCompletePriorCoverage()) {
            $response['observations'] = [];

            return $response;
        }

        $currentSources     = $this->repository->groups($query, 'source', self::ANOMALY_GROUP_LIMIT);
        $priorSources       = $this->repository->groups($prior, 'source', self::ANOMALY_GROUP_LIMIT);
        $currentConnections = $this->repository->groups($query, 'connection', self::ANOMALY_GROUP_LIMIT);
        $priorConnections   = $this->repository->groups($prior, 'connection', self::ANOMALY_GROUP_LIMIT);
        $error              = $this->firstError([$currentSources, $priorSources, $currentConnections, $priorConnections]);
        if ($error !== null) {
            return $error;
        }

        $response['observations'] = $this->observations(
            $currentSummary,
            $priorSummary,
            $currentSources,
            $priorSources,
            $currentConnections,
            $priorConnections
        );

        return $response;
    }

    /**
     * @param array<string,int> $summary
     *
     * @return array<string,mixed>
     */
    private function metadata(AnalyticsQuery $query, array $summary): array
    {
        return [
            'range'                   => $this->range($query),
            'timezone'                => $query->timezone()->getName(),
            'total'                   => (int) ($summary['total'] ?? 0),
            'unknown_source_count'    => (int) ($summary['unknown_source_count'] ?? 0),
            'unknown_recipient_count' => (int) ($summary['unknown_recipient_count'] ?? 0),
            'interpretation'          => self::INTERPRETATION,
        ];
    }

    /**
     * @return array{start:string,end:string}
     */
    private function range(AnalyticsQuery $query): array
    {
        return [
            'start' => $query->start()->format(DATE_ATOM),
            'end'   => $query->end()->format(DATE_ATOM),
        ];
    }

    /**
     * @param array<string,int> $summary
     *
     * @return array<string,int|float>
     */
    private function acceptance(array $summary): array
    {
        $total    = (int) ($summary['total'] ?? 0);
        $accepted = (int) ($summary['accepted'] ?? 0);

        return [
            'accepted'      => $accepted,
            'failed'        => (int) ($summary['failed'] ?? 0),
            'denominator'   => $total,
            'accepted_rate' => $this->rate($accepted, $total),
        ];
    }

    /**
     * @param array<string,int> $summary
     *
     * @return array<string,int|float>
     */
    private function delivery(array $summary): array
    {
        $denominator = (int) ($summary['verified_delivery'] ?? 0);
        $total       = (int) ($summary['total'] ?? 0);
        $delivered   = (int) ($summary['delivered'] ?? 0);

        return [
            'delivered'      => $delivered,
            'delayed'        => (int) ($summary['deferred'] ?? 0),
            'bounced'        => (int) ($summary['bounced'] ?? 0),
            'blocked'        => (int) ($summary['blocked'] ?? 0),
            'spam'           => (int) ($summary['spam'] ?? 0),
            'accepted'       => (int) ($summary['accepted_delivery'] ?? 0),
            'pending'        => (int) ($summary['pending_delivery'] ?? 0),
            'unknown'        => max(0, $total - $denominator - (int) ($summary['accepted_delivery'] ?? 0) - (int) ($summary['pending_delivery'] ?? 0)),
            'denominator'    => $denominator,
            'delivered_rate' => $this->rate($delivered, $denominator),
        ];
    }

    /**
     * @param array<int,array<string,int|string>> $rows
     *
     * @return array<int,array<string,int|string>>
     */
    private function fillBuckets(AnalyticsQuery $query, array $rows): array
    {
        $byBucket = [];
        foreach ($rows as $row) {
            $utcHour = DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                (string) ($row['utc_hour'] ?? ''),
                new DateTimeZone('UTC')
            );
            if ($utcHour === false) {
                continue;
            }

            $bucket = $this->bucketDescriptor($query, $utcHour);
            $key    = $bucket['bucket'];
            $byBucket[$key] ??= [
                'total'             => 0,
                'accepted'          => 0,
                'failed'            => 0,
                'delivered'         => 0,
                'verified_delivery' => 0,
            ];
            foreach (array_keys($byBucket[$key]) as $metric) {
                $byBucket[$key][$metric] += (int) ($row[$metric] ?? 0);
            }
        }

        $filled = [];
        foreach ($this->bucketDescriptors($query) as $bucket) {
            $row      = $byBucket[$bucket['bucket']] ?? [];
            $filled[] = [
                'bucket'            => $bucket['bucket'],
                'label'             => $bucket['label'],
                'total'             => (int) ($row['total'] ?? 0),
                'accepted'          => (int) ($row['accepted'] ?? 0),
                'failed'            => (int) ($row['failed'] ?? 0),
                'delivered'         => (int) ($row['delivered'] ?? 0),
                'verified_delivery' => (int) ($row['verified_delivery'] ?? 0),
            ];
        }

        return $filled;
    }

    /**
     * @return array<int,array{bucket:string,label:string}>
     */
    private function bucketDescriptors(AnalyticsQuery $query): array
    {
        $cursor  = $query->start()->setTime((int) $query->start()->format('H'), 0, 0);
        $buckets = [];
        while ($cursor < $query->end()) {
            $bucket = $this->bucketDescriptor($query, $cursor);
            if (!isset($buckets[$bucket['bucket']])) {
                $buckets[$bucket['bucket']] = $bucket;
            }
            $cursor = $cursor->add(new DateInterval('PT1H'));
        }

        return array_values($buckets);
    }

    /**
     * @return array{bucket:string,label:string}
     */
    private function bucketDescriptor(AnalyticsQuery $query, DateTimeImmutable $utcHour): array
    {
        $local = $utcHour->setTimezone($query->timezone());
        if ($query->bucket() === 'hour') {
            return [
                'bucket' => $local->format(DATE_ATOM),
                'label'  => $local->format('Y-m-d H:00'),
            ];
        }

        if ($query->bucket() === 'week') {
            $bucket = $local->format('o-\\WW');

            return ['bucket' => $bucket, 'label' => $bucket];
        }

        $bucket = $local->format('Y-m-d');

        return ['bucket' => $bucket, 'label' => $bucket];
    }

    /**
     * @param array<int,array{pattern:string,total:int}> $subjects
     *
     * @return array<int,array{pattern:string,total:int}>
     */
    private function subjectPatterns(array $subjects): array
    {
        $result = [];
        foreach (\array_slice($subjects, 0, self::TOP_LIMIT) as $subject) {
            $pattern = (string) ($subject['pattern'] ?? '');
            if ($pattern !== '') {
                $result[] = ['pattern' => $pattern, 'total' => (int) $subject['total']];
            }
        }

        return $result;
    }

    /**
     * @param array<string,int>                   $current
     * @param array<string,int>                   $prior
     * @param array<int,array<string,int|string>> $currentSources
     * @param array<int,array<string,int|string>> $priorSources
     * @param array<int,array<string,int|string>> $currentConnections
     * @param array<int,array<string,int|string>> $priorConnections
     *
     * @return array<int,array<string,int|float|string>>
     */
    private function observations(
        array $current,
        array $prior,
        array $currentSources,
        array $priorSources,
        array $currentConnections,
        array $priorConnections
    ): array {
        $observations = [];
        $currentTotal = (int) ($current['total'] ?? 0);
        $priorTotal   = (int) ($prior['total'] ?? 0);
        if ($priorTotal > 0) {
            $observations[] = [
                'type'              => 'volume_change',
                'current'           => $currentTotal,
                'prior'             => $priorTotal,
                'percentage_change' => round((($currentTotal - $priorTotal) / $priorTotal) * 100, 2),
            ];
        }

        if ($currentTotal >= self::MINIMUM_RATE_SAMPLE && $priorTotal >= self::MINIMUM_RATE_SAMPLE) {
            $observations[] = [
                'type'                     => 'failure_rate_change',
                'current_failure_rate'     => $this->rate((int) ($current['failed'] ?? 0), $currentTotal),
                'prior_failure_rate'       => $this->rate((int) ($prior['failed'] ?? 0), $priorTotal),
                'percentage_point_change'  => round(
                    $this->rate((int) ($current['failed'] ?? 0), $currentTotal)
                    - $this->rate((int) ($prior['failed'] ?? 0), $priorTotal),
                    2
                ),
            ];
        }

        $observations = array_merge($observations, $this->activityObservations($currentSources, $priorSources, 'source'));

        return array_merge($observations, $this->connectionRateObservations($currentConnections, $priorConnections));
    }

    /**
     * @param array<int,array<string,int|string>> $current
     * @param array<int,array<string,int|string>> $prior
     *
     * @return array<int,array<string,int|string>>
     */
    private function activityObservations(array $current, array $prior, string $field): array
    {
        $currentByDimension = $this->indexGroups($current);
        $priorByDimension   = $this->indexGroups($prior);
        $observations       = [];
        foreach ($currentByDimension as $dimension => $row) {
            if (!isset($priorByDimension[$dimension])) {
                $observations[] = ['type' => 'newly_active_' . $field, $field => $dimension, 'total' => (int) $row['total']];
            }
        }
        foreach ($priorByDimension as $dimension => $row) {
            if (!isset($currentByDimension[$dimension])) {
                $observations[] = ['type' => 'inactive_' . $field, $field => $dimension, 'total' => (int) $row['total']];
            }
        }

        return $observations;
    }

    /**
     * @param array<int,array<string,int|string>> $current
     * @param array<int,array<string,int|string>> $prior
     *
     * @return array<int,array<string,int|float|string>>
     */
    private function connectionRateObservations(array $current, array $prior): array
    {
        $currentByDimension = $this->indexGroups($current);
        $priorByDimension   = $this->indexGroups($prior);
        $observations       = [];
        foreach ($currentByDimension as $connection => $row) {
            if (!isset($priorByDimension[$connection])
                || (int) $row['total']                           < self::MINIMUM_RATE_SAMPLE
                || (int) $priorByDimension[$connection]['total'] < self::MINIMUM_RATE_SAMPLE) {
                continue;
            }

            $currentRate = $this->rate((int) $row['failed'], (int) $row['total']);
            $priorRate   = $this->rate((int) $priorByDimension[$connection]['failed'], (int) $priorByDimension[$connection]['total']);
            if ($currentRate !== $priorRate) {
                $observations[] = [
                    'type'                     => 'connection_failure_rate_change',
                    'connection'               => $connection,
                    'current_failure_rate'     => $currentRate,
                    'prior_failure_rate'       => $priorRate,
                    'percentage_point_change'  => round($currentRate - $priorRate, 2),
                ];
            }
        }

        return $observations;
    }

    /**
     * @param array<int,array<string,int|string>> $groups
     *
     * @return array<string,array<string,int|string>>
     */
    private function indexGroups(array $groups): array
    {
        $indexed = [];
        foreach ($groups as $group) {
            $indexed[(string) $group['dimension']] = $group;
        }

        return $indexed;
    }

    /**
     * @param array<string,int> $summary
     *
     * @return array{total:int,accepted:int,failed:int}
     */
    private function counts(array $summary): array
    {
        return [
            'total'    => (int) ($summary['total'] ?? 0),
            'accepted' => (int) ($summary['accepted'] ?? 0),
            'failed'   => (int) ($summary['failed'] ?? 0),
        ];
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator === 0 ? 0.0 : round(($numerator / $denominator) * 100, 2);
    }

    /**
     * @param array<int,mixed> $values
     */
    private function firstError(array $values): ?WP_Error
    {
        foreach ($values as $value) {
            if ($value instanceof WP_Error) {
                return $value;
            }
        }

        return null;
    }
}
