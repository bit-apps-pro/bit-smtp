<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Mail\Analytics\AnalyticsQuery;
use BitApps\SMTP\Mail\Analytics\AnalyticsQueryFactory;
use BitApps\SMTP\Mail\Analytics\MailAnalyticsRepository;
use BitApps\SMTP\Mail\Analytics\MailAnalyticsService;
use BitApps\SMTP\Mail\Analytics\SubjectPatternNormalizer;
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
        $this->seed('2026-03-01 05:00:00', 1, 'woocommerce', 'conn_primary', 'delivered', 'Order 123456');
        $this->seed('2026-03-03 05:00:00', 0, null, 'conn_primary', null, 'Failed 123456');
        $database = new RecordingDatabase($GLOBALS['wpdb']);
        $service  = new MailAnalyticsService(
            new MailAnalyticsRepository($database, (new Log())->getTable()),
            new SubjectPatternNormalizer()
        );

        $result = $service->overview($this->query());

        self::assertSame(2, $result['total']);
        self::assertCount(4, $database->queries);
        self::assertNotEmpty($database->templates);
        foreach ($database->queries as $sql) {
            self::assertDoesNotMatchRegularExpression('/SELECT\\s+\\*/i', $sql);
        }
        self::assertStringContainsString('%s', $database->templates[0]);
    }

    public function testDeliverabilityKeepsUnknownOutcomesOutOfTheVerifiedDenominator(): void
    {
        $this->seed('2026-03-01 05:00:00', 1, 'woocommerce', 'conn_primary', 'delivered', 'Order 123456');
        $this->seed('2026-03-02 05:00:00', 1, 'woocommerce', 'conn_primary', null, 'Order 234567');
        $this->seed('2026-03-03 05:00:00', 0, null, 'conn_primary', 'bounced', 'Failed 345678');
        $database = new RecordingDatabase($GLOBALS['wpdb']);
        $service  = new MailAnalyticsService(
            new MailAnalyticsRepository($database, (new Log())->getTable()),
            new SubjectPatternNormalizer()
        );

        $result = $service->deliverability($this->query());

        self::assertSame(3, $result['acceptance']['denominator']);
        self::assertSame(2, $result['delivery']['denominator']);
        self::assertSame(1, $result['delivery']['unknown']);
        self::assertLessThanOrEqual(3, \count($database->queries));
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

    private function seed(string $createdAt, int $status, ?string $source, string $connectionId, ?string $deliveryStatus, string $subject): void
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            (new Log())->getTable(),
            [
                'status'          => $status,
                'subject'         => $subject,
                'to_addr'         => wp_json_encode(['customer@example.test']),
                'connection'      => $connectionId,
                'connection_id'   => $connectionId,
                'source_plugin'   => $source,
                'delivery_status' => $deliveryStatus,
                'created_at'      => $createdAt,
                'updated_at'      => $createdAt,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
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
