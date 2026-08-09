<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Abilities;

/**
 * JSON Schema contracts for the public, aggregate-only mail analytics abilities.
 *
 * Schemas intentionally enumerate every property. This both keeps Core output validation useful
 * and prevents future callback changes from accidentally exposing retained log content.
 */
final class AbilitySchemas
{
    private const PLUGIN_PATTERN = '^[a-z0-9][a-z0-9._-]*(?::[a-z0-9][a-z0-9._-]*)?$';

    private const CONNECTION_ID_PATTERN = '^[A-Za-z0-9][A-Za-z0-9_-]{0,190}$';

    private const DOMAIN_PATTERN = '^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$';

    /**
     * @return array<string,mixed>
     */
    public static function overviewInput(): array
    {
        return self::analyticsInput();
    }

    /**
     * @return array<string,mixed>
     */
    public static function pluginInput(): array
    {
        $schema                                      = self::analyticsInput();
        $schema['required']                          = ['plugin'];
        $schema['properties']['plugin']['minLength'] = 1;

        return $schema;
    }

    /**
     * @return array<string,mixed>
     */
    public static function deliverabilityInput(): array
    {
        return self::analyticsInput();
    }

    /**
     * @return array<string,mixed>
     */
    public static function anomaliesInput(): array
    {
        return self::analyticsInput();
    }

    /**
     * @return array<string,mixed>
     */
    public static function routingInput(): array
    {
        $actual = self::object([
            'log_id' => self::integer(1),
        ], ['log_id']);
        $simulation = self::object([
            'to_domains' => [
                'type'        => 'array',
                'items'       => self::string(['pattern' => self::DOMAIN_PATTERN]),
                'minItems'    => 1,
                'maxItems'    => 50,
                'uniqueItems' => true,
            ],
            'source_plugin' => self::string(['pattern' => self::PLUGIN_PATTERN]),
        ], ['to_domains']);

        return self::object(array_merge($actual['properties'], $simulation['properties']), [], [
            'oneOf' => [$actual, $simulation],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public static function overviewOutput(): array
    {
        return self::object(array_merge(self::metadataProperties(), [
            'logging_enabled'    => ['type' => 'boolean'],
            'retained_records'   => self::retainedRecords(),
            'timestamp_coverage' => self::timestampCoverage(),
            'recipients'         => self::integer(),
            'acceptance'         => self::acceptance(),
            'delivery'           => self::delivery(),
            'busiest_hours'      => self::boundedArrayOf(self::busiestHour()),
            'busiest_weekdays'   => self::boundedArrayOf(self::busiestWeekday()),
            'series'             => self::arrayOf(self::seriesRow()),
            'top_sources'        => self::arrayOf(self::groupRow()),
            'top_connections'    => self::arrayOf(self::groupRow()),
        ]), [
            'range',
            'timezone',
            'total',
            'unknown_source_count',
            'unknown_recipient_count',
            'interpretation',
            'logging_enabled',
            'retained_records',
            'timestamp_coverage',
            'recipients',
            'acceptance',
            'delivery',
            'busiest_hours',
            'busiest_weekdays',
            'series',
            'top_sources',
            'top_connections',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public static function pluginOutput(): array
    {
        return self::object(array_merge(self::metadataProperties(), [
            'plugin'                        => self::string(['pattern' => self::PLUGIN_PATTERN]),
            'acceptance'                    => self::acceptance(),
            'delivery'                      => self::delivery(),
            'series'                        => self::arrayOf(self::seriesRow()),
            'busiest_hours'                 => self::boundedArrayOf(self::busiestHour()),
            'busiest_weekdays'              => self::boundedArrayOf(self::busiestWeekday()),
            'timestamp_coverage'            => self::timestampCoverage(),
            'connections'                   => self::arrayOf(self::groupRow()),
            'routing_types'                 => self::arrayOf(self::groupRow()),
            'subject_patterns'              => self::arrayOf(self::subjectPattern()),
            'subject_pattern_unknown_count' => self::integer(),
            'proxy_interpretation'          => self::string(),
        ]), [
            'range',
            'timezone',
            'total',
            'unknown_source_count',
            'unknown_recipient_count',
            'interpretation',
            'plugin',
            'acceptance',
            'delivery',
            'series',
            'busiest_hours',
            'busiest_weekdays',
            'timestamp_coverage',
            'connections',
            'routing_types',
            'subject_patterns',
            'subject_pattern_unknown_count',
            'proxy_interpretation',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public static function deliverabilityOutput(): array
    {
        return self::object(array_merge(self::metadataProperties(), [
            'acceptance'  => self::acceptance(),
            'delivery'    => self::delivery(),
            'sources'     => self::arrayOf(self::groupRow()),
            'connections' => self::arrayOf(self::groupRow()),
        ]), [
            'range',
            'timezone',
            'total',
            'unknown_source_count',
            'unknown_recipient_count',
            'interpretation',
            'acceptance',
            'delivery',
            'sources',
            'connections',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public static function routingOutput(): array
    {
        $actual = self::object([
            'log_id'                     => self::integer(1),
            'routing_metadata_available' => ['type' => 'boolean'],
            'source_plugin'              => self::string(),
            'routing_type'               => self::nullable(self::string(['enum' => ['rule', 'default', 'fallback', 'native']])),
            'matched_rule_index'         => self::nullable(self::integer(0)),
            'selected_connection_id'     => self::nullable(self::string()),
            'fallback_chain'             => self::arrayOf(self::string()),
        ], [
            'log_id',
            'routing_metadata_available',
            'source_plugin',
            'routing_type',
            'matched_rule_index',
            'selected_connection_id',
            'fallback_chain',
        ]);
        $simulation = self::object([
            'mode'                   => self::string(['enum' => ['simulation']]),
            'rules'                  => self::arrayOf(self::routingRule()),
            'matched_rule_index'     => self::nullable(self::integer(0)),
            'matched_connection_id'  => self::nullable(self::string()),
            'routing_type'           => self::string(['enum' => ['rule', 'default']]),
            'selected_connection_id' => self::nullable(self::string()),
            'fallback_candidates'    => self::arrayOf(self::string()),
        ], [
            'mode',
            'rules',
            'matched_rule_index',
            'matched_connection_id',
            'routing_type',
            'selected_connection_id',
            'fallback_candidates',
        ]);
        $properties                 = array_merge($actual['properties'], $simulation['properties']);
        $properties['routing_type'] = self::nullable(self::string());

        return self::object($properties, [], [
            'oneOf' => [$actual, $simulation],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public static function anomaliesOutput(): array
    {
        return self::object(array_merge(self::metadataProperties(), [
            'current'             => self::counts(),
            'prior'               => self::counts(),
            'prior_range'         => self::range(),
            'timestamp_coverage'  => self::timestampCoverage(),
            'comparison_coverage' => self::object([
                'complete'                 => ['type' => 'boolean'],
                'retained_from'            => self::nullable(self::string(['format' => 'date-time'])),
                'continuity_from'          => self::nullable(self::string(['format' => 'date-time'])),
                'configured_retained_from' => self::nullable(self::string(['format' => 'date-time'])),
            ], ['complete', 'retained_from', 'continuity_from', 'configured_retained_from']),
            'observations' => self::arrayOf(self::observation()),
        ]), [
            'range',
            'timezone',
            'total',
            'unknown_source_count',
            'unknown_recipient_count',
            'interpretation',
            'current',
            'prior',
            'prior_range',
            'timestamp_coverage',
            'comparison_coverage',
            'observations',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private static function analyticsInput(): array
    {
        return self::object([
            'start'         => self::string(['format' => 'date-time']),
            'end'           => self::string(['format' => 'date-time']),
            'bucket'        => self::string(['enum' => ['hour', 'day', 'week']]),
            'plugin'        => self::string(['pattern' => self::PLUGIN_PATTERN]),
            'connection_id' => self::string(['pattern' => self::CONNECTION_ID_PATTERN]),
        ], [], ['default' => []]);
    }

    /**
     * @return array<string,mixed>
     */
    private static function range(): array
    {
        return self::object([
            'start' => self::string(['format' => 'date-time']),
            'end'   => self::string(['format' => 'date-time']),
        ], ['start', 'end']);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function metadataProperties(): array
    {
        return [
            'range'                   => self::range(),
            'timezone'                => self::string(),
            'total'                   => self::integer(),
            'unknown_source_count'    => self::integer(),
            'unknown_recipient_count' => self::integer(),
            'interpretation'          => self::string(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function acceptance(): array
    {
        return self::object([
            'accepted'      => self::integer(),
            'failed'        => self::integer(),
            'denominator'   => self::integer(),
            'accepted_rate' => self::number(),
        ], ['accepted', 'failed', 'denominator', 'accepted_rate']);
    }

    /**
     * @return array<string,mixed>
     */
    private static function delivery(): array
    {
        return self::object([
            'delivered'      => self::integer(),
            'delayed'        => self::integer(),
            'bounced'        => self::integer(),
            'blocked'        => self::integer(),
            'spam'           => self::integer(),
            'accepted'       => self::integer(),
            'pending'        => self::integer(),
            'unknown'        => self::integer(),
            'denominator'    => self::integer(),
            'delivered_rate' => self::number(),
        ], [
            'delivered',
            'delayed',
            'bounced',
            'blocked',
            'spam',
            'accepted',
            'pending',
            'unknown',
            'denominator',
            'delivered_rate',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private static function seriesRow(): array
    {
        return self::object([
            'bucket'            => self::string(),
            'label'             => self::string(),
            'total'             => self::integer(),
            'accepted'          => self::integer(),
            'failed'            => self::integer(),
            'delivered'         => self::integer(),
            'verified_delivery' => self::integer(),
        ], ['bucket', 'label', 'total', 'accepted', 'failed', 'delivered', 'verified_delivery']);
    }

    /**
     * @return array<string,mixed>
     */
    private static function groupRow(): array
    {
        return self::object([
            'dimension'         => self::string(),
            'total'             => self::integer(),
            'accepted'          => self::integer(),
            'failed'            => self::integer(),
            'delivered'         => self::integer(),
            'verified_delivery' => self::integer(),
        ], ['dimension', 'total', 'accepted', 'failed', 'delivered', 'verified_delivery']);
    }

    /**
     * @return array<string,mixed>
     */
    private static function retainedRecords(): array
    {
        return self::object([
            'earliest' => self::nullable(self::string(['format' => 'date-time'])),
            'latest'   => self::nullable(self::string(['format' => 'date-time'])),
        ], ['earliest', 'latest']);
    }

    /**
     * @return array<string,mixed>
     */
    private static function timestampCoverage(): array
    {
        return self::object([
            'qualified_records'   => self::integer(),
            'unqualified_records' => self::integer(),
            'interpretation'      => self::string(),
        ], ['qualified_records', 'unqualified_records', 'interpretation']);
    }

    /**
     * @return array<string,mixed>
     */
    private static function busiestHour(): array
    {
        return self::object([
            'hour'  => array_merge(self::integer(), ['maximum' => 23]),
            'label' => self::string(),
            'total' => self::integer(),
        ], ['hour', 'label', 'total']);
    }

    /**
     * @return array<string,mixed>
     */
    private static function busiestWeekday(): array
    {
        return self::object([
            'weekday' => array_merge(self::integer(1), ['maximum' => 7]),
            'label'   => self::string(),
            'total'   => self::integer(),
        ], ['weekday', 'label', 'total']);
    }

    /**
     * @return array<string,mixed>
     */
    private static function subjectPattern(): array
    {
        return self::object([
            'pattern' => self::string(['maxLength' => 160]),
            'total'   => self::integer(),
        ], ['pattern', 'total']);
    }

    /**
     * @return array<string,mixed>
     */
    private static function routingRule(): array
    {
        return self::object([
            'index'         => self::integer(0),
            'connection_id' => self::string(),
            'conditions'    => self::arrayOf(self::routingCondition()),
            'matches'       => ['type' => 'boolean'],
        ], ['index', 'connection_id', 'conditions', 'matches']);
    }

    /**
     * @return array<string,mixed>
     */
    private static function routingCondition(): array
    {
        return self::object([
            'field'      => self::string(['enum' => ['recipient', 'from', 'subject', 'source_plugin']]),
            'operator'   => self::string(['enum' => ['equals', 'contains', 'domain', 'matches']]),
            'descriptor' => self::string(),
            'matches'    => ['type' => 'boolean'],
        ], ['field', 'operator', 'descriptor', 'matches']);
    }

    /**
     * @return array<string,mixed>
     */
    private static function counts(): array
    {
        return self::object([
            'total'    => self::integer(),
            'accepted' => self::integer(),
            'failed'   => self::integer(),
        ], ['total', 'accepted', 'failed']);
    }

    /**
     * @return array<string,mixed>
     */
    private static function observation(): array
    {
        $volume = self::object([
            'type'              => self::string(['enum' => ['volume_change']]),
            'current'           => self::integer(),
            'prior'             => self::integer(),
            'percentage_change' => self::number(),
        ], ['type', 'current', 'prior', 'percentage_change']);
        $rate = self::object([
            'type'                    => self::string(['enum' => ['failure_rate_change']]),
            'current_failure_rate'    => self::number(),
            'prior_failure_rate'      => self::number(),
            'percentage_point_change' => self::number(),
        ], ['type', 'current_failure_rate', 'prior_failure_rate', 'percentage_point_change']);
        $activity = self::object([
            'type'   => self::string(['enum' => ['newly_active_source', 'inactive_source']]),
            'source' => self::string(),
            'total'  => self::integer(),
        ], ['type', 'source', 'total']);
        $connection = self::object([
            'type'                    => self::string(['enum' => ['connection_failure_rate_change']]),
            'connection'              => self::string(),
            'current_failure_rate'    => self::number(),
            'prior_failure_rate'      => self::number(),
            'percentage_point_change' => self::number(),
        ], ['type', 'connection', 'current_failure_rate', 'prior_failure_rate', 'percentage_point_change']);
        $hourlyDistribution  = self::distributionShift('hourly_distribution_shift', 'hour', array_merge(self::integer(), ['maximum' => 23]));
        $weekdayDistribution = self::distributionShift('weekday_distribution_shift', 'weekday', array_merge(self::integer(1), ['maximum' => 7]));
        $properties          = array_merge(
            $volume['properties'],
            $rate['properties'],
            $activity['properties'],
            $connection['properties'],
            $hourlyDistribution['properties'],
            $weekdayDistribution['properties']
        );
        $properties['type'] = self::string([
            'enum' => [
                'volume_change',
                'failure_rate_change',
                'newly_active_source',
                'inactive_source',
                'connection_failure_rate_change',
                'hourly_distribution_shift',
                'weekday_distribution_shift',
            ],
        ]);

        return self::object($properties, [], [
            'oneOf' => [$volume, $rate, $activity, $connection, $hourlyDistribution, $weekdayDistribution],
        ]);
    }

    /**
     * @param array<string,mixed> $dimensionSchema
     *
     * @return array<string,mixed>
     */
    private static function distributionShift(string $type, string $dimension, array $dimensionSchema): array
    {
        return self::object([
            'type'                     => self::string(['enum' => [$type]]),
            $dimension                 => $dimensionSchema,
            'current'                  => self::integer(),
            'prior'                    => self::integer(),
            'current_percentage'       => self::number(),
            'prior_percentage'         => self::number(),
            'percentage_point_change'  => self::number(),
        ], ['type', $dimension, 'current', 'prior', 'current_percentage', 'prior_percentage', 'percentage_point_change']);
    }

    /**
     * @param array<string,mixed> $extra
     *
     * @return array<string,mixed>
     */
    private static function string(array $extra = []): array
    {
        return array_merge(['type' => 'string'], $extra);
    }

    /**
     * @return array<string,mixed>
     */
    private static function integer(?int $minimum = 0): array
    {
        $schema = ['type' => 'integer'];
        if ($minimum !== null) {
            $schema['minimum'] = $minimum;
        }

        return $schema;
    }

    /**
     * @return array<string,mixed>
     */
    private static function number(): array
    {
        return ['type' => 'number'];
    }

    /**
     * @param array<string,mixed> $schema
     *
     * @return array<string,mixed>
     */
    private static function nullable(array $schema): array
    {
        return [
            'oneOf' => [
                $schema,
                ['type' => 'null'],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $item
     *
     * @return array<string,mixed>
     */
    private static function arrayOf(array $item): array
    {
        return [
            'type'  => 'array',
            'items' => $item,
        ];
    }

    /**
     * @param array<string,mixed> $item
     *
     * @return array<string,mixed>
     */
    private static function boundedArrayOf(array $item): array
    {
        return array_merge(self::arrayOf($item), ['maxItems' => 10]);
    }

    /**
     * @param array<string,array<string,mixed>> $properties
     * @param array<int,string>                 $required
     * @param array<string,mixed>               $extra
     *
     * @return array<string,mixed>
     */
    private static function object(array $properties, array $required = [], array $extra = []): array
    {
        return array_merge([
            'type'                 => 'object',
            'properties'           => $properties,
            'required'             => $required,
            'additionalProperties' => false,
        ], $extra);
    }
}
