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

    private const BUSY_TIME_LIMIT = 10;

    private const ANOMALY_GROUP_LIMIT = 100;

    private const TIMING_OBSERVATION_LIMIT = 10;

    private const MINIMUM_RATE_SAMPLE = 20;

    private const CONTACT_FORM_PLUGINS = ['contact-form-7', 'wpforms', 'wpforms-lite', 'gravityforms', 'ninja-forms'];

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
        $loggingError = $this->loggingDisabledError();
        if ($loggingError !== null) {
            return $loggingError;
        }

        $summary     = $this->repository->summary($query);
        $series      = $this->repository->timeSeries($query);
        $sources     = $this->repository->groups($query, 'source', self::TOP_LIMIT);
        $connections = $this->repository->groups($query, 'connection', self::TOP_LIMIT);
        $bounds      = $this->repository->retainedRecordBounds();
        $error       = $this->firstError([$summary, $series, $sources, $connections, $bounds]);
        if ($error !== null) {
            return $error;
        }

        $busyTimes = $this->busyTimes($query, $series);

        return array_merge($this->metadata($query, $summary), [
            'logging_enabled'  => $this->loggingEnabled(),
            'retained_records' => [
                'earliest' => $this->utcTimestamp($bounds['earliest'] ?? null),
                'latest'   => $this->utcTimestamp($bounds['latest'] ?? null),
            ],
            'recipients'       => (int) ($summary['recipient_count'] ?? 0),
            'acceptance'       => $this->acceptance($summary),
            'delivery'         => $this->delivery($summary),
            'busiest_hours'    => $busyTimes['hours'],
            'busiest_weekdays' => $busyTimes['weekdays'],
            'series'           => $this->fillBuckets($query, $series),
            'top_sources'      => $sources,
            'top_connections'  => $connections,
        ]);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function plugin(AnalyticsQuery $query)
    {
        $loggingError = $this->loggingDisabledError();
        if ($loggingError !== null) {
            return $loggingError;
        }

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

        $busyTimes = $this->busyTimes($query, $series);

        return array_merge($this->metadata($query, $summary), [
            'plugin'                        => $query->plugin(),
            'acceptance'                    => $this->acceptance($summary),
            'delivery'                      => $this->delivery($summary),
            'series'                        => $this->fillBuckets($query, $series),
            'busiest_hours'                 => $busyTimes['hours'],
            'busiest_weekdays'              => $busyTimes['weekdays'],
            'connections'                   => $connections,
            'routing_types'                 => $routingTypes,
            'subject_patterns'              => $this->subjectPatterns($subjects),
            'subject_pattern_unknown_count' => (int) ($summary['unknown_subject_pattern_count'] ?? 0),
            'proxy_interpretation'          => $this->pluginProxyInterpretation($query->plugin()),
        ]);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function deliverability(AnalyticsQuery $query)
    {
        $loggingError = $this->loggingDisabledError();
        if ($loggingError !== null) {
            return $loggingError;
        }

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
        $loggingError = $this->loggingDisabledError();
        if ($loggingError !== null) {
            return $loggingError;
        }

        $prior          = $query->priorPeriod();
        $currentSummary = $this->repository->summary($query);
        $priorSummary   = $this->repository->summary($prior);
        $bounds         = $this->repository->retainedRecordBounds();
        $error          = $this->firstError([$currentSummary, $priorSummary, $bounds]);
        if ($error !== null) {
            return $error;
        }

        $actualRetainedFrom = $this->utcTimestamp($bounds['earliest'] ?? null);
        $completeCoverage   = $actualRetainedFrom !== null
            && $this->utcDate($actualRetainedFrom) <= $prior->start();

        $response = array_merge($this->metadata($query, $currentSummary), [
            'current'             => $this->counts($currentSummary),
            'prior'               => $this->counts($priorSummary),
            'prior_range'         => $this->range($prior),
            'comparison_coverage' => [
                'complete'                 => $completeCoverage,
                'retained_from'            => $actualRetainedFrom,
                'configured_retained_from' => $query->retainedFrom() === null ? null : $query->retainedFrom()->format(DATE_ATOM),
            ],
        ]);
        if (!$completeCoverage) {
            $response['observations'] = [];

            return $response;
        }

        $currentSources     = $this->repository->groups($query, 'source', self::ANOMALY_GROUP_LIMIT);
        $priorSources       = $this->repository->groups($prior, 'source', self::ANOMALY_GROUP_LIMIT);
        $currentConnections = $this->repository->groups($query, 'connection', self::ANOMALY_GROUP_LIMIT);
        $priorConnections   = $this->repository->groups($prior, 'connection', self::ANOMALY_GROUP_LIMIT);
        $currentSeries      = $this->repository->timeSeries($query);
        $priorSeries        = $this->repository->timeSeries($prior);
        $error              = $this->firstError([
            $currentSources,
            $priorSources,
            $currentConnections,
            $priorConnections,
            $currentSeries,
            $priorSeries,
        ]);
        if ($error !== null) {
            return $error;
        }

        $response['observations'] = $this->observations(
            $query,
            $currentSummary,
            $priorSummary,
            $currentSources,
            $priorSources,
            $currentConnections,
            $priorConnections,
            $currentSeries,
            $priorSeries
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

    private function pluginProxyInterpretation(string $plugin): string
    {
        if ($plugin === 'woocommerce') {
            return 'WooCommerce email activity is derived from retained notifications and is only a proxy for order-related activity.';
        }

        if (\in_array($plugin, self::CONTACT_FORM_PLUGINS, true)) {
            return 'Contact-form email activity is derived from retained notifications and is only a proxy for user contact submissions.';
        }

        return 'Plugin activity is derived from retained email notifications and is only a proxy for application activity.';
    }

    /**
     * @param array<int,array<string,int|string>> $rows
     *
     * @return array{hours:array<int,array{hour:int,label:string,total:int}>,weekdays:array<int,array{weekday:int,label:string,total:int}>}
     */
    private function busyTimes(AnalyticsQuery $query, array $rows): array
    {
        $distribution = $this->localTimeDistribution($query, $rows);
        $hours        = $distribution['hours'];
        $weekdays     = $distribution['weekdays'];

        $busiestHours = [];
        foreach ($hours as $hour => $total) {
            if ($total > 0) {
                $busiestHours[] = ['hour' => $hour, 'label' => \sprintf('%02d:00', $hour), 'total' => $total];
            }
        }
        usort($busiestHours, static function (array $left, array $right): int {
            return $right['total'] <=> $left['total'] ?: $left['hour'] <=> $right['hour'];
        });

        $busiestWeekdays = [];
        foreach ($weekdays as $weekday => $total) {
            if ($total > 0) {
                $busiestWeekdays[] = [
                    'weekday' => $weekday,
                    'label'   => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$weekday - 1],
                    'total'   => $total,
                ];
            }
        }
        usort($busiestWeekdays, static function (array $left, array $right): int {
            return $right['total'] <=> $left['total'] ?: $left['weekday'] <=> $right['weekday'];
        });

        return [
            'hours'    => \array_slice($busiestHours, 0, self::BUSY_TIME_LIMIT),
            'weekdays' => \array_slice($busiestWeekdays, 0, self::BUSY_TIME_LIMIT),
        ];
    }

    /**
     * @param array<int,array<string,int|string>> $rows
     *
     * @return array{hours:array<int,int>,weekdays:array<int,int>}
     */
    private function localTimeDistribution(AnalyticsQuery $query, array $rows): array
    {
        $hours    = array_fill(0, 24, 0);
        $weekdays = array_fill(1, 7, 0);
        foreach ($rows as $row) {
            $utcHour = $this->utcHour($row);
            if ($utcHour === null) {
                continue;
            }

            $local                                        = $utcHour->setTimezone($query->timezone());
            $total                                        = (int) ($row['total'] ?? 0);
            $hours[(int) $local->format('G')]    += $total;
            $weekdays[(int) $local->format('N')] += $total;
        }

        return ['hours' => $hours, 'weekdays' => $weekdays];
    }

    /**
     * @param array<int,array<string,int|string>> $current
     * @param array<int,array<string,int|string>> $prior
     *
     * @return array<int,array<string,int|float|string>>
     */
    private function timingDistributionObservations(
        AnalyticsQuery $query,
        array $current,
        array $prior,
        int $currentTotal,
        int $priorTotal
    ): array {
        if ($currentTotal < self::MINIMUM_RATE_SAMPLE || $priorTotal < self::MINIMUM_RATE_SAMPLE) {
            return [];
        }

        $currentDistribution = $this->localTimeDistribution($query, $current);
        $priorDistribution   = $this->localTimeDistribution($query, $prior);

        return array_merge(
            $this->distributionShiftObservations(
                $currentDistribution['hours'],
                $priorDistribution['hours'],
                $currentTotal,
                $priorTotal,
                'hourly_distribution_shift',
                'hour'
            ),
            $this->distributionShiftObservations(
                $currentDistribution['weekdays'],
                $priorDistribution['weekdays'],
                $currentTotal,
                $priorTotal,
                'weekday_distribution_shift',
                'weekday'
            )
        );
    }

    /**
     * @param array<int,int> $current
     * @param array<int,int> $prior
     *
     * @return array<int,array<string,int|float|string>>
     */
    private function distributionShiftObservations(
        array $current,
        array $prior,
        int $currentTotal,
        int $priorTotal,
        string $type,
        string $dimension
    ): array {
        $observations = [];
        foreach ($current as $bucket => $currentCount) {
            $priorCount        = $prior[$bucket] ?? 0;
            $currentPercentage = $this->rate($currentCount, $currentTotal);
            $priorPercentage   = $this->rate($priorCount, $priorTotal);
            if ($currentPercentage === $priorPercentage) {
                continue;
            }

            $observations[] = [
                'type'                     => $type,
                $dimension                 => $bucket,
                'current'                  => $currentCount,
                'prior'                    => $priorCount,
                'current_percentage'       => $currentPercentage,
                'prior_percentage'         => $priorPercentage,
                'percentage_point_change'  => round($currentPercentage - $priorPercentage, 2),
            ];
        }
        usort($observations, static function (array $left, array $right) use ($dimension): int {
            $change = abs((float) $right['percentage_point_change']) <=> abs((float) $left['percentage_point_change']);

            return $change !== 0 ? $change : $left[$dimension] <=> $right[$dimension];
        });

        return \array_slice($observations, 0, self::TIMING_OBSERVATION_LIMIT);
    }

    /**
     * @param array<string,int|string> $row
     */
    private function utcHour(array $row): ?DateTimeImmutable
    {
        $utcHour = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            (string) ($row['utc_hour'] ?? ''),
            new DateTimeZone('UTC')
        );

        return $utcHour === false ? null : $utcHour;
    }

    /**
     * @param mixed $value
     */
    private function utcTimestamp($value): ?string
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        $timestamp = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));

        return $timestamp === false ? null : $timestamp->format(DATE_ATOM);
    }

    private function utcDate(string $timestamp): DateTimeImmutable
    {
        return new DateTimeImmutable($timestamp, new DateTimeZone('UTC'));
    }

    private function loggingEnabled(): bool
    {
        if (!\function_exists('get_option')) {
            return true;
        }

        return (bool) \BitApps\SMTP\Config::getOption('logging_enabled', true);
    }

    private function loggingDisabledError(): ?WP_Error
    {
        if ($this->loggingEnabled()) {
            return null;
        }

        return new WP_Error('bit_smtp_logging_disabled', 'Email analytics require Bit SMTP logging to be enabled.');
    }

    /**
     * @param array<string,int>                   $current
     * @param array<string,int>                   $prior
     * @param array<int,array<string,int|string>> $currentSources
     * @param array<int,array<string,int|string>> $priorSources
     * @param array<int,array<string,int|string>> $currentConnections
     * @param array<int,array<string,int|string>> $priorConnections
     * @param array<int,array<string,int|string>> $currentSeries
     * @param array<int,array<string,int|string>> $priorSeries
     *
     * @return array<int,array<string,int|float|string>>
     */
    private function observations(
        AnalyticsQuery $query,
        array $current,
        array $prior,
        array $currentSources,
        array $priorSources,
        array $currentConnections,
        array $priorConnections,
        array $currentSeries,
        array $priorSeries
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

        $observations = array_merge($observations, $this->timingDistributionObservations(
            $query,
            $currentSeries,
            $priorSeries,
            $currentTotal,
            $priorTotal
        ));

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
