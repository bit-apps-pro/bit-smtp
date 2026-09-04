<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\Model\Log;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Locks analytics REST execution to aggregate database work at the maximum supported retention.
 *
 * @internal
 *
 * @coversNothing
 */
final class MailAnalyticsPerformanceTest extends IntegrationTestCase
{
    private const RETENTION_DAYS = 200;

    private const HOURS_PER_DAY = 24;

    // Multiple records in every hour distinguish a bounded SQL aggregate from a raw-row query
    // followed by PHP-side aggregation: a 200-day hourly result has 4,800 rows, not 14,400.
    private const ROWS_PER_HOUR = 3;

    private const HOURLY_BUCKETS = self::RETENTION_DAYS * self::HOURS_PER_DAY;

    private const MAX_RESPONSE_BYTES = 1048576;

    private string $table;

    /**
     * @var array<int,string>
     */
    private array $analyticsQueries = [];

    /**
     * @var mixed
     */
    private $previousTimezone;

    /**
     * @var mixed
     */
    private $previousRetention;

    /**
     * @var mixed
     */
    private $previousContinuity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->table              = (new Log())->getTable();
        $this->previousTimezone   = get_option('timezone_string', null);
        $this->previousRetention  = Config::getOption('log_retention', null);
        $this->previousContinuity = Config::getOption(Config::LOGGING_CONTINUITY_FROM_OPTION, null);
        $this->truncateTables($this->table);

        update_option('timezone_string', 'Asia/Dhaka');
        Config::updateOption('log_retention', self::RETENTION_DAYS, true);
        Config::updateOption(Config::LOGGING_CONTINUITY_FROM_OPTION, '2026-01-01 00:00:00', true);
        $this->seedMaximumRetention();
        wp_set_current_user(1);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        $this->restoreOption('timezone_string', $this->previousTimezone);
        $this->restoreOption(Config::withPrefix('log_retention'), $this->previousRetention);
        $this->restoreOption(Config::withPrefix(Config::LOGGING_CONTINUITY_FROM_OPTION), $this->previousContinuity);

        parent::tearDown();
    }

    public function testMaximumRetentionOverviewUsesBoundedAggregateQueriesAndACompactResponse(): void
    {
        $response = $this->recordAbilityExecution('bit-smtp/get-email-analytics', $this->maximumRetentionRange());

        self::assertSame(200, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame('Asia/Dhaka', $data['timezone']);
        self::assertSame(self::HOURLY_BUCKETS * self::ROWS_PER_HOUR, $data['total']);
        self::assertCount(self::HOURLY_BUCKETS, $data['series']);
        self::assertLessThanOrEqual(self::MAX_RESPONSE_BYTES, \strlen((string) wp_json_encode($data)));
        $this->assertBoundedAggregateQueries(5);
        $this->assertRawLogContentIsAbsent($data);
        $this->assertPlansMatchEveryQueryContract($this->analyticsQueries);
        $this->assertRetentionDeletionPlanUsesCreatedAtIndex();
    }

    public function testSelectiveConnectionFilteredRestRequestUsesCompositeUtcIndex(): void
    {
        $response = $this->recordAbilityExecution('bit-smtp/get-email-analytics', [
            'start'         => '2026-05-01T06:00:00+06:00',
            'end'           => '2026-05-02T06:00:00+06:00',
            'bucket'        => 'hour',
            'connection_id' => 'conn_primary',
        ]);

        self::assertSame(200, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame(36, $data['total']);
        self::assertCount(24, $data['series']);
        self::assertLessThanOrEqual(self::MAX_RESPONSE_BYTES, \strlen((string) wp_json_encode($data)));
        $this->assertBoundedAggregateQueries(5);
        $this->assertRawLogContentIsAbsent($data);
        $this->assertSelectiveConnectionPlansUseCompositeUtcIndex($this->analyticsQueries);
    }

    public function testPluginDeliverabilityAndAnomalyRequestsRemainBoundedOverMaximumRetention(): void
    {
        $plugin = $this->recordAbilityExecution('bit-smtp/analyze-plugin-email', array_merge($this->maximumRetentionRange(), [
            'plugin' => 'woocommerce',
        ]));
        self::assertSame(200, $plugin->get_status());
        self::assertLessThanOrEqual(self::MAX_RESPONSE_BYTES, \strlen((string) wp_json_encode($plugin->get_data())));
        $this->assertBoundedAggregateQueries(6);
        $this->assertRawLogContentIsAbsent($plugin->get_data());
        $this->assertPlansMatchEveryQueryContract($this->analyticsQueries);

        $deliverability = $this->recordAbilityExecution('bit-smtp/analyze-deliverability', array_merge($this->maximumRetentionRange(), [
            'connection_id' => 'conn_primary',
        ]));
        self::assertSame(200, $deliverability->get_status());
        self::assertLessThanOrEqual(self::MAX_RESPONSE_BYTES, \strlen((string) wp_json_encode($deliverability->get_data())));
        $this->assertBoundedAggregateQueries(3);
        $this->assertRawLogContentIsAbsent($deliverability->get_data());
        $this->assertPlansMatchEveryQueryContract($this->analyticsQueries);

        $anomalies = $this->recordAbilityExecution('bit-smtp/detect-email-anomalies', [
            'start'  => '2026-04-11T06:00:00+06:00',
            'end'    => '2026-07-20T06:00:00+06:00',
            'bucket' => 'hour',
        ]);
        self::assertSame(200, $anomalies->get_status());
        self::assertLessThanOrEqual(self::MAX_RESPONSE_BYTES, \strlen((string) wp_json_encode($anomalies->get_data())));
        $this->assertBoundedAggregateQueries(9);
        $this->assertRawLogContentIsAbsent($anomalies->get_data());
        $this->assertPlansMatchEveryQueryContract($this->analyticsQueries);
    }

    /**
     * @param mixed $query
     *
     * @return mixed
     */
    public function recordAnalyticsQuery($query)
    {
        if (\is_string($query) && str_contains($query, '`' . $this->table . '`')) {
            $this->analyticsQueries[] = $query;
        }

        return $query;
    }

    /**
     * @param array<string,mixed> $input
     */
    private function recordAbilityExecution(string $ability, array $input): WP_REST_Response
    {
        $this->analyticsQueries = [];
        add_filter('query', [$this, 'recordAnalyticsQuery']);

        try {
            $request = new WP_REST_Request('GET', '/wp-abilities/v1/abilities/' . $ability . '/run');
            $request->set_query_params(['input' => $input]);

            return rest_get_server()->dispatch($request);
        } finally {
            remove_filter('query', [$this, 'recordAnalyticsQuery']);
        }
    }

    /**
     * @return array{start:string,end:string,bucket:string}
     */
    private function maximumRetentionRange(): array
    {
        return [
            'start'  => '2026-01-01T06:00:00+06:00',
            'end'    => '2026-07-20T06:00:00+06:00',
            'bucket' => 'hour',
        ];
    }

    private function assertBoundedAggregateQueries(int $maximum): void
    {
        self::assertNotEmpty($this->analyticsQueries);
        self::assertLessThanOrEqual($maximum, \count($this->analyticsQueries));

        foreach ($this->analyticsQueries as $query) {
            $contract = $this->queryContract($query);
            $this->assertNoRawProjection($query);

            global $wpdb;
            $rows = $wpdb->get_results($query, ARRAY_A);
            self::assertSame('', (string) $wpdb->last_error, "Aggregate query must execute cleanly:\n{$query}");
            self::assertIsArray($rows);
            self::assertLessThanOrEqual(
                $contract['maximum_rows'],
                \count($rows),
                "Aggregate query returned more rows than its bounded contract:\n{$query}"
            );
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function assertRawLogContentIsAbsent(array $data): void
    {
        $json = (string) wp_json_encode($data);
        self::assertStringNotContainsString('private-customer-0@example.test', $json);
        self::assertStringNotContainsString('Raw retained subject 0', $json);
        self::assertStringNotContainsString('raw details 0', $json);
        self::assertStringNotContainsString('raw debug token 0', $json);
    }

    /**
     * @param array<int,string> $queries
     */
    private function assertPlansMatchEveryQueryContract(array $queries): void
    {
        global $wpdb;

        foreach ($queries as $query) {
            $contract = $this->queryContract($query);
            $plan     = $wpdb->get_results('EXPLAIN ' . $query, ARRAY_A);

            self::assertIsArray($plan);
            self::assertCount(1, $plan, "EXPLAIN must yield one plan for {$contract['shape']} analytics query.");
            self::assertSame(
                $contract['index'],
                $plan[0]['key'],
                "{$contract['shape']} must retain its expected index choice:\n{$query}"
            );
            self::assertSame(
                $contract['access'],
                $plan[0]['type'],
                "{$contract['shape']} must retain its expected access type:\n{$query}"
            );
        }
    }

    private function assertRetentionDeletionPlanUsesCreatedAtIndex(): void
    {
        global $wpdb;

        $query = $wpdb->prepare("DELETE FROM `{$this->table}` WHERE created_at < %s", '2026-01-05 06:00:00');
        $plan  = $wpdb->get_results('EXPLAIN ' . $query, ARRAY_A);

        self::assertIsArray($plan);
        self::assertCount(1, $plan);
        self::assertSame('idx_created_at', $plan[0]['key']);
        self::assertSame('range', $plan[0]['type']);
    }

    /**
     * @param array<int,string> $queries
     */
    private function assertSelectiveConnectionPlansUseCompositeUtcIndex(array $queries): void
    {
        global $wpdb;

        $connectionQueries = array_values(array_filter($queries, static function (string $query): bool {
            return str_contains($query, 'connection_id =');
        }));
        self::assertCount(4, $connectionQueries, 'Overview must issue four connection-filtered aggregate queries.');

        foreach ($connectionQueries as $query) {
            $shape = $this->queryContract($query)['shape'];
            $plan  = $wpdb->get_results('EXPLAIN ' . $query, ARRAY_A);

            self::assertIsArray($plan);
            self::assertCount(1, $plan, "EXPLAIN must yield one plan for selective {$shape}.");
            self::assertSame(
                'idx_connection_id_created_utc',
                $plan[0]['key'],
                "Selective connection-filtered {$shape} must use the UTC composite index:\n{$query}"
            );
            self::assertSame(
                'range',
                $plan[0]['type'],
                "Selective connection-filtered {$shape} must use range access:\n{$query}"
            );
        }
    }

    /**
     * @return array{shape:string,index:?string,access:string,maximum_rows:int}
     */
    private function queryContract(string $query): array
    {
        $normalized = preg_replace('/\s+/', ' ', $query) ?? $query;

        if (str_contains($normalized, 'MIN(created_at_utc) AS earliest')) {
            self::assertMatchesRegularExpression('/COUNT\(created_at_utc\)/i', $normalized);
            self::assertDoesNotMatchRegularExpression('/\bGROUP BY\b/i', $normalized);

            return [
                'shape'        => 'retained-bounds aggregate',
                'index'        => 'idx_created_at_utc',
                'access'       => 'index',
                'maximum_rows' => 1,
            ];
        }

        if (str_contains($normalized, 'DATE_FORMAT(created_at_utc')) {
            self::assertMatchesRegularExpression('/COUNT\(\*\) AS total/i', $normalized);
            self::assertMatchesRegularExpression('/SUM\(/i', $normalized);
            self::assertMatchesRegularExpression('/GROUP BY utc_hour/i', $normalized);

            return [
                'shape'        => 'hourly aggregate',
                // A full retained-window scan cannot reduce rows through this range predicate;
                // MariaDB correctly chooses a table scan while SQL still returns one row/hour.
                'index'        => null,
                'access'       => 'ALL',
                'maximum_rows' => self::HOURLY_BUCKETS,
            ];
        }

        if (str_contains($normalized, 'subject_pattern AS pattern')) {
            self::assertMatchesRegularExpression('/COUNT\(\*\) AS total/i', $normalized);
            self::assertMatchesRegularExpression('/GROUP BY subject_pattern/i', $normalized);
            self::assertMatchesRegularExpression('/LIMIT 10/i', $normalized);

            return [
                'shape'        => 'subject-pattern aggregate',
                'index'        => 'idx_source_created_utc',
                'access'       => 'range',
                'maximum_rows' => 10,
            ];
        }

        if (str_contains($normalized, ' AS dimension')) {
            self::assertMatchesRegularExpression('/COUNT\(\*\) AS total/i', $normalized);
            self::assertMatchesRegularExpression('/SUM\(/i', $normalized);
            self::assertMatchesRegularExpression('/GROUP BY dimension/i', $normalized);
            self::assertMatchesRegularExpression('/LIMIT (?:10|100)/i', $normalized);
            $isPluginFiltered = str_contains($normalized, "source_plugin = 'woocommerce'");

            return [
                'shape'        => $isPluginFiltered ? 'plugin dimension aggregate' : 'dimension aggregate',
                'index'        => $isPluginFiltered ? 'idx_source_created_utc' : 'idx_created_at_utc',
                'access'       => 'range',
                'maximum_rows' => str_contains($normalized, 'LIMIT 100') ? 100 : 10,
            ];
        }

        if (str_contains($normalized, 'COUNT(*) AS total')) {
            self::assertMatchesRegularExpression('/SUM\(/i', $normalized);
            self::assertDoesNotMatchRegularExpression('/\bGROUP BY\b/i', $normalized);

            return [
                'shape'        => 'summary aggregate',
                'index'        => null,
                'access'       => 'ALL',
                'maximum_rows' => 1,
            ];
        }

        self::fail("Analytics query has no recognized bounded aggregate contract:\n{$query}");
    }

    private function assertNoRawProjection(string $query): void
    {
        self::assertDoesNotMatchRegularExpression('/SELECT\s+\*/i', $query);
        self::assertMatchesRegularExpression('/\b(?:COUNT|SUM|MIN|MAX)\s*\(/i', $query);
        $projection = preg_replace('/^\s*SELECT\s+(.*?)\s+FROM\s+.*$/is', '$1', $query) ?? '';

        // Fields are permitted inside fixed aggregate expressions above. They must never appear
        // as a bare result column, which would let PHP hydrate retained rows to aggregate later.
        self::assertDoesNotMatchRegularExpression(
            '/(?:^|,)\s*`?(?:id|status|recipient(?:_count)?|to(?:_addr)?|subject|details|debug(?:_info)?|body|from|cc|bcc|attachments?|token|created_at(?:_utc)?|connection(?:_id)?|source_plugin|routing_type)`?(?:\s+AS\b|\s*,|\s*$)/i',
            $projection,
            "Analytics query must not project a raw retained-log field:\n{$query}"
        );
    }

    private function seedMaximumRetention(): void
    {
        global $wpdb;

        $utc       = new DateTimeZone('UTC');
        $site      = new DateTimeZone('Asia/Dhaka');
        $start     = new DateTimeImmutable('2026-01-01 00:00:00', $utc);
        $rows      = self::HOURLY_BUCKETS * self::ROWS_PER_HOUR;
        $batchSize = 200;

        for ($offset = 0; $offset < $rows; $offset += $batchSize) {
            $values       = [];
            $placeholders = [];
            $limit        = min($rows, $offset + $batchSize);
            for ($index = $offset; $index < $limit; ++$index) {
                $hour           = intdiv($index, self::ROWS_PER_HOUR);
                $createdUtc     = $start->add(new DateInterval('PT' . $hour . 'H'));
                $createdLocal   = $createdUtc->setTimezone($site);
                $source         = ['woocommerce', 'contact-form-7', 'unknown'][$index % 3];
                $connection     = $index % 2 === 0 ? 'conn_primary' : 'conn_secondary';
                $delivery       = ['delivered', 'accepted', 'pending', 'bounced', null][$index % 5];
                $placeholders[] = '(%d, %s, %s, %s, %s, %s, %s, %s, %d, %s, %s, %s)';
                array_push($values,
                    $index % 11 === 0 ? 0 : 1,
                    'Raw retained subject ' . $index,
                    wp_json_encode(['private-customer-' . $index . '@example.test']),
                    'raw details ' . $index,
                    'raw debug token ' . $index,
                    $connection,
                    $source,
                    $delivery,
                    1,
                    'Receipt <number>',
                    $createdLocal->format('Y-m-d H:i:s'),
                    $createdUtc->format('Y-m-d H:i:s')
                );
            }

            $sql = 'INSERT INTO `' . $this->table . '` '
                . '(`status`, `subject`, `to_addr`, `details`, `debug_info`, `connection_id`, `source_plugin`, `delivery_status`, `recipient_count`, `subject_pattern`, `created_at`, `created_at_utc`) VALUES '
                . implode(', ', $placeholders);
            self::assertNotFalse($wpdb->query($wpdb->prepare($sql, ...$values)));
        }
    }

    /**
     * @param mixed $value
     */
    private function restoreOption(string $option, $value): void
    {
        if ($value === null || $value === false) {
            delete_option($option);

            return;
        }

        update_option($option, $value);
    }
}
