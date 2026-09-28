<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Integration\Golden;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Mail\Analytics\AnalyticsQuery;
use BitApps\SMTP\Mail\Analytics\EngagementRepository;
use BitApps\SMTP\Mail\Analytics\MailAnalyticsRepository;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Model\LogEngagementEvent;
use BitApps\SMTP\Tests\Golden\Support\MatchesGolden;
use BitApps\SMTP\Tests\Integration\IntegrationTestCase;
use BitSmtpEngagementTableMigration;
use DateTimeImmutable;
use DateTimeZone;

require_once \dirname(__DIR__, 3) . '/backend/db/Migrations/BitSmtpEngagementTableMigration.php';

/**
 * Freezes analytics/engagement aggregates over an edge-case-heavy dataset (must reproduce exactly).
 *
 * @internal
 *
 * @coversNothing
 */
final class AnalyticsQueryGoldenTest extends IntegrationTestCase
{
    use MatchesGolden;

    private const FILTERS = [
        'all'         => [null, null],
        'woocommerce' => ['woocommerce', null],
        'unknown'     => ['unknown', null],
        'connection'  => [null, 'conn_primary'],
        'combined'    => ['woocommerce', 'conn_backup'],
    ];

    /**
     * Resets the log and engagement tables and seeds the shared dataset.
     */
    protected function setUp(): void
    {
        parent::setUp();
        (new BitSmtpEngagementTableMigration())->up();
        $this->truncateTables((new LogEngagementEvent())->getTable(), (new Log())->getTable());
        $this->seedDataset();
    }

    /**
     * Snapshots every MailAnalyticsRepository aggregate across each filter combination.
     */
    public function testMailAnalyticsRepositoryAggregatesMatchGolden(): void
    {
        $repository = new MailAnalyticsRepository();
        $snapshot   = [
            'bounds'  => $repository->retainedRecordBounds(),
            'sources' => $repository->distinctSourcePlugins(),
        ];

        foreach (self::FILTERS as $name => [$plugin, $connectionId]) {
            $query           = $this->query($plugin, $connectionId);
            $snapshot[$name] = [
                'summary'      => $repository->summary($query),
                'time_series'  => $repository->timeSeries($query),
                'source'       => $repository->groups($query, 'source', 100),
                'connection'   => $repository->groups($query, 'connection', 2),
                'routing_type' => $repository->groups($query, 'routing_type', 0),
                'subjects'     => $repository->subjectCounts($query),
            ];
        }

        $this->assertMatchesGolden($snapshot, 'analytics_repository_aggregates');
    }

    /**
     * Snapshots every EngagementRepository aggregate across each filter combination.
     */
    public function testEngagementRepositoryAggregatesMatchGolden(): void
    {
        $repository = new EngagementRepository();
        $snapshot   = [];

        foreach (self::FILTERS as $name => [$plugin, $connectionId]) {
            $query           = $this->query($plugin, $connectionId);
            $snapshot[$name] = [
                'engagement' => $repository->engagement($query),
                'top_links'  => $repository->topClickedLinks($query, 3),
                'top_one'    => $repository->topClickedLinks($query, 0),
            ];
        }

        $this->assertMatchesGolden($snapshot, 'analytics_engagement_aggregates');
    }

    /**
     * A fixed March 2026 range narrowed by the given plugin and connection filters.
     */
    private function query(?string $plugin, ?string $connectionId): AnalyticsQuery
    {
        $utc = new DateTimeZone('UTC');

        return new AnalyticsQuery(
            new DateTimeImmutable('2026-03-01 00:00:00', $utc),
            new DateTimeImmutable('2026-03-05 00:00:00', $utc),
            new DateTimeZone('America/New_York'),
            'day',
            $plugin,
            $connectionId
        );
    }

    /**
     * Seeds logs covering null/empty/unknown dimensions, every delivery status and engagement mixes.
     */
    private function seedDataset(): void
    {
        $delivered = $this->seedLog('2026-03-01 05:10:00', 1, 'woocommerce', 'conn_primary', 'conn_primary', 'rule', 'delivered', 'Order <number>', 2);
        $this->seedLog('2026-03-01 05:40:00', 1, 'woocommerce', 'conn_primary', 'conn_primary', 'default', 'accepted', 'Order <number>', 1);
        $bounced = $this->seedLog('2026-03-01 07:00:00', 0, 'woocommerce', 'conn_backup', 'conn_backup', 'rule', 'bounced', 'Receipt <number>', 3);
        $this->seedLog('2026-03-02 09:00:00', 1, '', '', 'legacy_label', null, 'pending', '', null);
        $this->seedLog('2026-03-02 09:30:00', 1, null, '', '', '', 'deferred', null, 1);
        $spam = $this->seedLog('2026-03-03 12:00:00', 0, 'unknown', 'conn_backup', 'conn_backup', 'failover', 'spam', 'Welcome', 1);
        $this->seedLog('2026-03-03 12:15:00', 1, 'contact-form-7', 'conn_primary', 'conn_primary', 'default', 'blocked', 'Contact <text>', 1);
        $this->seedLog('2026-03-04 23:59:59', 1, 'contact-form-7', 'conn_primary', 'conn_primary', 'default', null, 'Contact <text>', 4);
        $this->seedLog('2026-03-05 00:00:00', 1, 'woocommerce', 'conn_primary', 'conn_primary', 'default', 'delivered', 'Out of range', 1);
        $this->seedLog('2026-02-28 23:59:59', 1, 'wpforms', 'conn_primary', 'conn_primary', 'default', 'delivered', 'Before range', 1);
        $this->seedLog(null, 1, 'woocommerce', 'conn_primary', 'conn_primary', 'default', 'delivered', 'Legacy', 1);

        $logs = new LogService();
        $logs->recordEngagement($delivered, 'open', '', false);
        $logs->recordEngagement($delivered, 'open', '', true);
        $logs->recordEngagement($delivered, 'click', 'https://example.test/a', false);
        $logs->recordEngagement($delivered, 'click', 'https://example.test/a', false);
        $logs->recordEngagement($delivered, 'click', 'https://example.test/b', true);
        $logs->recordEngagement($bounced, 'open', '', true);
        $logs->recordEngagement($bounced, 'click', 'https://example.test/b', false);
        $logs->recordEngagement($spam, 'click', 'https://example.test/c', false);
        $logs->recordEngagement($spam, 'click', '', false);
    }

    /**
     * Inserts one log row with the analytics-relevant columns and returns its id.
     */
    private function seedLog(?string $createdAtUtc, int $status, ?string $source, string $connectionId, string $connection, ?string $routingType, ?string $deliveryStatus, ?string $subjectPattern, ?int $recipientCount): int
    {
        global $wpdb;

        $createdAt = $createdAtUtc ?? '2026-03-02 00:00:00';
        $inserted  = $wpdb->insert(
            (new Log())->getTable(),
            [
                'status'          => $status,
                'subject'         => 'Golden seed',
                'to_addr'         => wp_json_encode(['customer@example.test']),
                'connection'      => $connection,
                'connection_id'   => $connectionId,
                'source_plugin'   => $source,
                'routing_type'    => $routingType,
                'delivery_status' => $deliveryStatus,
                'subject_pattern' => $subjectPattern,
                'recipient_count' => $recipientCount,
                'created_at'      => $createdAt,
                'created_at_utc'  => $createdAtUtc,
                'updated_at'      => $createdAt,
            ]
        );

        self::assertSame(1, $inserted);

        return (int) $wpdb->insert_id;
    }
}
