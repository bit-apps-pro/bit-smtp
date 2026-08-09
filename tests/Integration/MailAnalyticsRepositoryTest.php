<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Mail\Analytics\AnalyticsQuery;
use BitApps\SMTP\Mail\Analytics\AnalyticsQueryFactory;
use BitApps\SMTP\Mail\Analytics\MailAnalyticsRepository;
use BitApps\SMTP\Mail\Analytics\MailAnalyticsService;
use BitApps\SMTP\Model\Log;
use DateTimeImmutable;
use DateTimeZone;

/**
 * @internal
 *
 * @coversNothing
 */
final class MailAnalyticsRepositoryTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . (new Log())->getTable());
    }

    public function testOverviewUsesOnlyBoundedAggregateQueriesAndDoesNotHydrateRawLogRows(): void
    {
        $this->seed('2026-03-01 05:00:00', 1, 'woocommerce', 'conn_primary', 'delivered', 'Order <number>', 2);
        $this->seed('2026-03-03 05:00:00', 0, null, 'conn_primary', null, null, null);
        $database = new RecordingDatabase($GLOBALS['wpdb']);
        $service  = new MailAnalyticsService(
            new MailAnalyticsRepository($database, (new Log())->getTable())
        );

        $result = $service->overview($this->query());

        self::assertSame(2, $result['total']);
        self::assertSame(2, $result['recipients']);
        self::assertSame(1, $result['unknown_recipient_count']);
        self::assertTrue($result['logging_enabled']);
        self::assertSame([
            'earliest' => '2026-03-01T05:00:00+00:00',
            'latest'   => '2026-03-03T05:00:00+00:00',
        ], $result['retained_records']);
        self::assertSame([['hour' => 0, 'label' => '00:00', 'total' => 2]], $result['busiest_hours']);
        self::assertCount(5, $database->queries);
        self::assertNotEmpty($database->templates);
        foreach ($database->queries as $sql) {
            self::assertDoesNotMatchRegularExpression('/SELECT\\s+\\*/i', $sql);
            self::assertStringNotContainsString('CONVERT_TZ', $sql);
            self::assertStringNotContainsString('JSON_VALID', $sql);
            self::assertStringNotContainsString('JSON_LENGTH', $sql);
        }
        self::assertStringContainsString('%s', $database->templates[0]);
        self::assertStringContainsString('MIN(created_at_utc) AS earliest', $database->queries[4]);
    }

    public function testEmptyRetainedLogsReturnStableZeroTimingAndNullableRecordBounds(): void
    {
        $database = new RecordingDatabase($GLOBALS['wpdb']);
        $service  = new MailAnalyticsService(
            new MailAnalyticsRepository($database, (new Log())->getTable())
        );

        $result = $service->overview($this->query());

        self::assertSame(0, $result['total']);
        self::assertSame(0, $result['recipients']);
        self::assertSame(['earliest' => null, 'latest' => null], $result['retained_records']);
        self::assertSame([], $result['busiest_hours']);
        self::assertSame([], $result['busiest_weekdays']);
        self::assertSame([0, 0, 0], array_column($result['series'], 'total'));
        self::assertCount(5, $database->queries);
    }

    public function testPluginSubjectsUseOnlyPersistedRedactedPatternsAndIdentifyLegacyUnknowns(): void
    {
        $this->seed('2026-03-01 05:00:00', 1, 'woocommerce', 'conn_primary', null, 'Order <number>', 1);
        $this->seed('2026-03-02 05:00:00', 1, 'woocommerce', 'conn_primary', null, null, 1);
        $database = new RecordingDatabase($GLOBALS['wpdb']);
        $service  = new MailAnalyticsService(
            new MailAnalyticsRepository($database, (new Log())->getTable())
        );
        $query = (new AnalyticsQueryFactory(
            new DateTimeImmutable('2026-03-04T05:00:00+00:00'),
            new DateTimeZone('America/New_York'),
            30
        ))->fromInput([
            'start'  => '2026-03-01T00:00:00-05:00',
            'end'    => '2026-03-04T00:00:00-05:00',
            'bucket' => 'day',
            'plugin' => 'woocommerce',
        ]);
        self::assertInstanceOf(AnalyticsQuery::class, $query);

        $result = $service->plugin($query);

        self::assertSame([['pattern' => 'Order <number>', 'total' => 1]], $result['subject_patterns']);
        self::assertSame(1, $result['subject_pattern_unknown_count']);
        foreach ($database->queries as $sql) {
            self::assertDoesNotMatchRegularExpression('/SELECT\\s+subject(?:\\s|,)/i', $sql);
        }
    }

    public function testDeliverabilityKeepsUnknownOutcomesOutOfTheVerifiedDenominator(): void
    {
        $this->seed('2026-03-01 05:00:00', 1, 'woocommerce', 'conn_primary', 'delivered', 'Order <number>', 1);
        $this->seed('2026-03-02 05:00:00', 1, 'woocommerce', 'conn_primary', 'accepted', 'Order <number>', 1);
        $this->seed('2026-03-02 06:00:00', 1, 'woocommerce', 'conn_primary', 'pending', 'Order <number>', 1);
        $this->seed('2026-03-03 05:00:00', 0, null, 'conn_primary', 'bounced', null, null);
        $this->seed('2026-03-03 06:00:00', 1, 'woocommerce', 'conn_primary', 'unknown', 'Order <number>', 1);
        $database = new RecordingDatabase($GLOBALS['wpdb']);
        $service  = new MailAnalyticsService(
            new MailAnalyticsRepository($database, (new Log())->getTable())
        );

        $result = $service->deliverability($this->query());

        self::assertSame(5, $result['acceptance']['denominator']);
        self::assertSame(2, $result['delivery']['denominator']);
        self::assertSame(1, $result['delivery']['unknown']);
        self::assertSame(1, $result['delivery']['accepted']);
        self::assertSame(1, $result['delivery']['pending']);
        self::assertLessThanOrEqual(3, \count($database->queries));
    }

    public function testRepositoryReturnsUtcHoursThatTheServiceConvertsWithoutTimezoneTables(): void
    {
        $this->seed('2026-11-01 05:00:00', 1, 'woocommerce', 'conn_primary', null, 'Receipt <number>', 1);
        $this->seed('2026-11-01 06:00:00', 1, 'woocommerce', 'conn_primary', null, 'Receipt <number>', 1);
        $database = new RecordingDatabase($GLOBALS['wpdb']);
        $service  = new MailAnalyticsService(
            new MailAnalyticsRepository($database, (new Log())->getTable())
        );

        $query = (new AnalyticsQueryFactory(
            new DateTimeImmutable('2026-11-01T08:00:00+00:00'),
            new DateTimeZone('America/New_York'),
            30
        ))->fromInput([
            'start'  => '2026-11-01T00:00:00-04:00',
            'end'    => '2026-11-01T03:00:00-05:00',
            'bucket' => 'hour',
        ]);
        self::assertInstanceOf(AnalyticsQuery::class, $query);

        $result = $service->overview($query);

        self::assertSame('2026-11-01T01:00:00-04:00', $result['series'][1]['bucket']);
        self::assertSame('2026-11-01T01:00:00-05:00', $result['series'][2]['bucket']);
        self::assertSame(1, $result['series'][1]['total']);
        self::assertSame(1, $result['series'][2]['total']);
        self::assertStringNotContainsString('CONVERT_TZ', $database->queries[1]);
    }

    public function testAnalyticsUseUtcTimestampsForRangesBoundsAndLocalBusyTimesAcrossNonUtcSites(): void
    {
        // created_at remains a site-local display field. Deliberately use values that would be
        // excluded if analytics compared its UTC filters against that display field.
        $this->seed('2026-03-01 00:30:00', 1, 'woocommerce', 'conn_primary', null, 'Order <number>', 1, '2026-03-01 05:30:00');
        $this->seed('2026-03-01 01:15:00', 1, 'woocommerce', 'conn_primary', null, 'Order <number>', 1, '2026-03-01 06:15:00');
        $this->seed('2026-03-01 01:45:00', 1, 'woocommerce', 'conn_primary', null, 'Order <number>', 1, '2026-03-01 06:45:00');
        $this->seed('2026-03-01 23:30:00', 1, 'woocommerce', 'conn_primary', null, 'Order <number>', 1, '2026-03-01 04:30:00');
        $this->seed('2026-03-01 18:10:00', 1, 'woocommerce', 'conn_primary', null, 'Order <number>', 1, '2026-03-01 12:10:00');

        $service = new MailAnalyticsService(new MailAnalyticsRepository($GLOBALS['wpdb'], (new Log())->getTable()));
        $newYork = (new AnalyticsQueryFactory(
            new DateTimeImmutable('2026-03-02T00:00:00+00:00'),
            new DateTimeZone('America/New_York'),
            30
        ))->fromInput([
            'start'  => '2026-03-01T00:00:00-05:00',
            'end'    => '2026-03-01T02:00:00-05:00',
            'bucket' => 'hour',
        ]);
        self::assertInstanceOf(AnalyticsQuery::class, $newYork);

        $newYorkResult = $service->overview($newYork);
        self::assertSame(3, $newYorkResult['total']);
        self::assertSame('2026-03-01T04:30:00+00:00', $newYorkResult['retained_records']['earliest']);
        self::assertSame('2026-03-01T12:10:00+00:00', $newYorkResult['retained_records']['latest']);
        self::assertSame(['hour' => 1, 'label' => '01:00', 'total' => 2], $newYorkResult['busiest_hours'][0]);

        $dhaka = (new AnalyticsQueryFactory(
            new DateTimeImmutable('2026-03-02T00:00:00+00:00'),
            new DateTimeZone('Asia/Dhaka'),
            30
        ))->fromInput([
            'start'  => '2026-03-01T17:00:00+06:00',
            'end'    => '2026-03-01T19:00:00+06:00',
            'bucket' => 'hour',
        ]);
        self::assertInstanceOf(AnalyticsQuery::class, $dhaka);

        $dhakaResult = $service->overview($dhaka);
        self::assertSame(1, $dhakaResult['total']);
        self::assertSame(['hour' => 18, 'label' => '18:00', 'total' => 1], $dhakaResult['busiest_hours'][0]);
    }

    private function query(): AnalyticsQuery
    {
        $query = (new AnalyticsQueryFactory(
            new DateTimeImmutable('2026-03-04T05:00:00+00:00'),
            new DateTimeZone('America/New_York'),
            30
        ))->fromInput([
            'start'  => '2026-03-01T00:00:00-05:00',
            'end'    => '2026-03-04T00:00:00-05:00',
            'bucket' => 'day',
        ]);

        self::assertInstanceOf(AnalyticsQuery::class, $query);

        return $query;
    }

    private function seed(string $createdAt, int $status, ?string $source, string $connectionId, ?string $deliveryStatus, ?string $subjectPattern, ?int $recipientCount, ?string $createdAtUtc = null): void
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            (new Log())->getTable(),
            [
                'status'          => $status,
                'subject'         => 'Retained raw subject must not be selected',
                'to_addr'         => wp_json_encode(['customer@example.test']),
                'connection'      => $connectionId,
                'connection_id'   => $connectionId,
                'source_plugin'   => $source,
                'delivery_status' => $deliveryStatus,
                'subject_pattern' => $subjectPattern,
                'recipient_count' => $recipientCount,
                'created_at'      => $createdAt,
                'created_at_utc'  => $createdAtUtc ?? $createdAt,
                'updated_at'      => $createdAt,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
        );

        self::assertSame(1, $inserted);
    }
}

/**
 * @internal
 */
final class RecordingDatabase
{
    /**
     * @var array<int,string>
     */
    public array $queries = [];

    /**
     * @var array<int,string>
     */
    public array $templates = [];

    public string $last_error = '';

    private object $database;

    public function __construct(object $database)
    {
        $this->database = $database;
    }

    public function prepare(string $query, ...$args): string
    {
        $this->templates[] = $query;

        return $this->database->prepare($query, ...$args);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function get_results(string $query, string $output): array
    {
        $this->queries[]  = $query;
        $result           = $this->database->get_results($query, $output);
        $this->last_error = (string) $this->database->last_error;

        return \is_array($result) ? $result : [];
    }
}
