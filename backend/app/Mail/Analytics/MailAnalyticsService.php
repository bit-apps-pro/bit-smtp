<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Analytics;

use DateInterval;
use WP_Error;

final class MailAnalyticsService
{
    private const INTERPRETATION = 'Results reflect retained Bit SMTP email logs, not orders, form submissions, or external business records.';

    private const TOP_LIMIT = 10;

    private const ANOMALY_GROUP_LIMIT = 100;

    private const MINIMUM_RATE_SAMPLE = 20;

    private MailAnalyticsRepository $repository;

    private SubjectPatternNormalizer $normalizer;

    public function __construct(MailAnalyticsRepository $repository, ?SubjectPatternNormalizer $normalizer = null)
    {
        $this->repository = $repository;
        $this->normalizer = $normalizer ?? new SubjectPatternNormalizer();
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
            'plugin'               => $query->plugin(),
            'acceptance'           => $this->acceptance($summary),
            'delivery'             => $this->delivery($summary),
            'series'               => $this->fillBuckets($query, $series),
            'connections'          => $connections,
            'routing_types'        => $routingTypes,
            'subject_patterns'     => $this->subjectPatterns($subjects),
            'proxy_interpretation' => 'Plugin activity is derived from retained email notifications and is only a proxy for application activity.',
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
        $prior              = $query->priorPeriod();
        $currentSummary     = $this->repository->summary($query);
        $priorSummary       = $this->repository->summary($prior);
        $currentSources     = $this->repository->groups($query, 'source', self::ANOMALY_GROUP_LIMIT);
        $priorSources       = $this->repository->groups($prior, 'source', self::ANOMALY_GROUP_LIMIT);
        $currentConnections = $this->repository->groups($query, 'connection', self::ANOMALY_GROUP_LIMIT);
        $priorConnections   = $this->repository->groups($prior, 'connection', self::ANOMALY_GROUP_LIMIT);
        $error              = $this->firstError([
            $currentSummary,
            $priorSummary,
            $currentSources,
            $priorSources,
            $currentConnections,
            $priorConnections,
        ]);
        if ($error !== null) {
            return $error;
        }

        return array_merge($this->metadata($query, $currentSummary), [
            'current'      => $this->counts($currentSummary),
            'prior'        => $this->counts($priorSummary),
            'prior_range'  => $this->range($prior),
            'observations' => $this->observations(
                $currentSummary,
                $priorSummary,
                $currentSources,
                $priorSources,
                $currentConnections,
                $priorConnections
            ),
        ]);
    }

    /**
     * @param array<string,int> $summary
     *
     * @return array<string,mixed>
     */
    private function metadata(AnalyticsQuery $query, array $summary): array
    {
        return [
            'range'                => $this->range($query),
            'timezone'             => $query->timezone()->getName(),
            'total'                => (int) ($summary['total'] ?? 0),
            'unknown_source_count' => (int) ($summary['unknown_source_count'] ?? 0),
            'interpretation'       => self::INTERPRETATION,
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
            'unknown'        => max(0, $total - $denominator),
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
            $byBucket[(string) ($row['bucket'] ?? '')] = $row;
        }

        $filled = [];
        foreach ($this->bucketKeys($query) as $bucket) {
            $row      = $byBucket[$bucket] ?? [];
            $filled[] = [
                'bucket'            => $bucket,
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
     * @return array<int,string>
     */
    private function bucketKeys(AnalyticsQuery $query): array
    {
        $start = $query->start()->setTimezone($query->timezone());
        $end   = $query->end()->setTimezone($query->timezone());
        if ($query->bucket() === 'hour') {
            $cursor = $start->setTime((int) $start->format('H'), 0, 0);
            $format = 'Y-m-d H:00:00';
            $step   = 'PT1H';
        } elseif ($query->bucket() === 'week') {
            $cursor = $start->modify('monday this week')->setTime(0, 0, 0);
            $format = 'oW';
            $step   = 'P1W';
        } else {
            $cursor = $start->setTime(0, 0, 0);
            $format = 'Y-m-d';
            $step   = 'P1D';
        }

        $keys = [];
        while ($cursor < $end) {
            $key = $cursor->format($format);
            if (!\in_array($key, $keys, true)) {
                $keys[] = $key;
            }
            $cursor = $cursor->add(new DateInterval($step));
        }

        return $keys;
    }

    /**
     * @param array<int,array{subject:string,total:int}> $subjects
     *
     * @return array<int,array{pattern:string,total:int}>
     */
    private function subjectPatterns(array $subjects): array
    {
        $patterns = [];
        foreach ($subjects as $subject) {
            $pattern            = $this->normalizer->normalize($subject['subject']);
            $patterns[$pattern] = ($patterns[$pattern] ?? 0) + (int) $subject['total'];
        }

        arsort($patterns, SORT_NUMERIC);
        $result = [];
        foreach (\array_slice($patterns, 0, self::TOP_LIMIT, true) as $pattern => $total) {
            $result[] = ['pattern' => $pattern, 'total' => $total];
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
