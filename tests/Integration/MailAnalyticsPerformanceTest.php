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

    private const ROWS_PER_DAY = 24;

    private const MAX_RESPONSE_BYTES = 131072;

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

        global $wpdb;
        $this->table              = (new Log())->getTable();
        $this->previousTimezone   = get_option('timezone_string', null);
        $this->previousRetention  = Config::getOption('log_retention', null);
        $this->previousContinuity = Config::getOption(Config::LOGGING_CONTINUITY_FROM_OPTION, null);
        $wpdb->query('TRUNCATE TABLE ' . $this->table);

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
        $response = $this->recordAbilityExecution('bit-smtp/get-email-analytics', $this->recentRange());

        self::assertSame(200, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame('Asia/Dhaka', $data['timezone']);
        self::assertSame(self::ROWS_PER_DAY * 30, $data['total']);
        self::assertCount(30, $data['series']);
        self::assertLessThanOrEqual(self::MAX_RESPONSE_BYTES, \strlen((string) wp_json_encode($data)));
        $this->assertBoundedAggregateQueries(5);
        $this->assertRawLogContentIsAbsent($data);
        $this->assertPlansUseIndexes($this->analyticsQueries, 'idx_created_at_utc');
    }

    public function testPluginDeliverabilityAndAnomalyRequestsRemainBoundedOverMaximumRetention(): void
    {
        $plugin = $this->recordAbilityExecution('bit-smtp/analyze-plugin-email', array_merge($this->recentRange(), [
            'plugin' => 'woocommerce',
        ]));
        self::assertSame(200, $plugin->get_status());
        self::assertLessThanOrEqual(self::MAX_RESPONSE_BYTES, \strlen((string) wp_json_encode($plugin->get_data())));
        $this->assertBoundedAggregateQueries(6);
        $this->assertRawLogContentIsAbsent($plugin->get_data());
        $this->assertPlansUseIndexes($this->analyticsQueries, 'idx_source_created_utc');

        $deliverability = $this->recordAbilityExecution('bit-smtp/analyze-deliverability', array_merge($this->recentRange(), [
            'connection_id' => 'conn_primary',
        ]));
        self::assertSame(200, $deliverability->get_status());
        self::assertLessThanOrEqual(self::MAX_RESPONSE_BYTES, \strlen((string) wp_json_encode($deliverability->get_data())));
        $this->assertBoundedAggregateQueries(3);
        $this->assertRawLogContentIsAbsent($deliverability->get_data());
        $this->assertPlansUseIndexes($this->analyticsQueries, 'idx_connection_id_created_utc');

        $anomalies = $this->recordAbilityExecution('bit-smtp/detect-email-anomalies', [
            'start'  => '2026-05-20T00:00:00+06:00',
            'end'    => '2026-06-19T00:00:00+06:00',
            'bucket' => 'day',
        ]);
        self::assertSame(200, $anomalies->get_status());
        self::assertLessThanOrEqual(self::MAX_RESPONSE_BYTES, \strlen((string) wp_json_encode($anomalies->get_data())));
        $this->assertBoundedAggregateQueries(9);
        $this->assertRawLogContentIsAbsent($anomalies->get_data());
        $this->assertPlansUseIndexes($this->analyticsQueries, 'idx_created_at_utc');
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
    private function recentRange(): array
    {
        return [
            'start'  => '2026-06-19T00:00:00+06:00',
            'end'    => '2026-07-19T00:00:00+06:00',
            'bucket' => 'day',
        ];
    }

    private function assertBoundedAggregateQueries(int $maximum): void
    {
        self::assertNotEmpty($this->analyticsQueries);
        self::assertLessThanOrEqual($maximum, \count($this->analyticsQueries));

        foreach ($this->analyticsQueries as $query) {
            self::assertDoesNotMatchRegularExpression('/SELECT\s+\*/i', $query);
            self::assertDoesNotMatchRegularExpression('/\b(?:subject|to_addr|details|debug_info)\b/i', $query);
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
    private function assertPlansUseIndexes(array $queries, string $requiredIndex): void
    {
        global $wpdb;

        $selectedIndexes = [];
        foreach ($queries as $query) {
            $plan = $wpdb->get_results('EXPLAIN ' . $query, ARRAY_A);
            self::assertIsArray($plan);
            self::assertNotEmpty($plan);
            self::assertNotEmpty($plan[0]['key'], "Aggregate query must use a selected index:\n{$query}");
            $selectedIndexes[] = (string) $plan[0]['key'];
        }

        self::assertContains($requiredIndex, $selectedIndexes);
    }

    private function seedMaximumRetention(): void
    {
        global $wpdb;

        $utc       = new DateTimeZone('UTC');
        $site      = new DateTimeZone('Asia/Dhaka');
        $start     = new DateTimeImmutable('2026-01-01 00:00:00', $utc);
        $rows      = self::RETENTION_DAYS * self::ROWS_PER_DAY;
        $batchSize = 200;

        for ($offset = 0; $offset < $rows; $offset += $batchSize) {
            $values       = [];
            $placeholders = [];
            $limit        = min($rows, $offset + $batchSize);
            for ($index = $offset; $index < $limit; ++$index) {
                $createdUtc     = $start->add(new DateInterval('PT' . $index . 'H'));
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
