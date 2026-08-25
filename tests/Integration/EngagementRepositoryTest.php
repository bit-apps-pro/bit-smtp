<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Mail\Analytics\AnalyticsQuery;
use BitApps\SMTP\Mail\Analytics\AnalyticsQueryFactory;
use BitApps\SMTP\Mail\Analytics\EngagementRepository;
use BitApps\SMTP\Mail\Analytics\MailAnalyticsRepository;
use BitApps\SMTP\Mail\Analytics\MailAnalyticsService;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Model\LogEngagementEvent;
use BitSmtpEngagementTableMigration;
use DateTimeImmutable;
use DateTimeZone;

// Global-namespace migration class, included directly (migrations are not PSR-4 autoloaded).
require_once \dirname(__DIR__, 2) . '/backend/db/Migrations/BitSmtpEngagementTableMigration.php';

/**
 * Exercises EngagementRepository + MailAnalyticsService::engagement() against the real test DB:
 * the log-joined aggregate, the human-vs-automated split, the delivered-or-accepted open-rate
 * denominator, and range exclusion.
 *
 * @internal
 *
 * @coversNothing
 */
final class EngagementRepositoryTest extends IntegrationTestCase
{
    private LogService $logs;

    protected function setUp(): void
    {
        parent::setUp();
        (new BitSmtpEngagementTableMigration())->up();
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . (new LogEngagementEvent())->getTable());
        $wpdb->query('TRUNCATE TABLE ' . (new Log())->getTable());
        $this->logs = new LogService();
    }

    public function testAggregatesOpensAndClicksForInRangeLogsWithTheHumanAutomatedSplit(): void
    {
        $delivered = $this->seedLog('2026-03-02 05:00:00', 'delivered');
        $this->logs->recordEngagement($delivered, 'open', '', false);
        $this->logs->recordEngagement($delivered, 'open', '', false);
        $this->logs->recordEngagement($delivered, 'open', '', true);
        $this->logs->recordEngagement($delivered, 'click', 'https://example.test/a', false);

        $accepted = $this->seedLog('2026-03-02 06:00:00', 'accepted');
        $this->logs->recordEngagement($accepted, 'open', '', true);

        $raw = (new EngagementRepository($GLOBALS['wpdb']))->engagement($this->query());

        self::assertSame(4, $raw['open_hits']);
        self::assertSame(2, $raw['open_automated_hits']);
        self::assertSame(2, $raw['open_rows']);
        self::assertSame(1, $raw['open_human_logs']);
        self::assertSame(1, $raw['click_hits']);
        self::assertSame(0, $raw['click_automated_hits']);
        self::assertSame(1, $raw['click_rows']);
        self::assertSame(1, $raw['click_human_logs']);
    }

    public function testServiceShapesTheHonestSplitAndDeliveredOrAcceptedOpenRate(): void
    {
        $delivered = $this->seedLog('2026-03-02 05:00:00', 'delivered');
        $this->logs->recordEngagement($delivered, 'open', '', false);
        $this->logs->recordEngagement($delivered, 'open', '', false);
        $this->logs->recordEngagement($delivered, 'open', '', true);
        $this->logs->recordEngagement($delivered, 'click', 'https://example.test/a', false);

        $accepted = $this->seedLog('2026-03-02 06:00:00', 'accepted');
        $this->logs->recordEngagement($accepted, 'open', '', true);

        // A pending in-range log stays out of the delivered-or-accepted denominator.
        $this->seedLog('2026-03-02 07:00:00', 'pending');

        $result = $this->service()->engagement($this->query());

        self::assertSame(['total' => 4, 'automated' => 2, 'human' => 2, 'unique' => 2], $result['opens']);
        self::assertSame(['total' => 1, 'automated' => 0, 'human' => 1, 'unique' => 1], $result['clicks']);
        self::assertSame(['engaged_logs' => 1, 'denominator' => 2, 'rate' => 50.0], $result['open_rate']);
        self::assertSame(['engaged_logs' => 1, 'denominator' => 2, 'rate' => 50.0], $result['click_rate']);
        self::assertStringContainsString('Automated', $result['engagement_interpretation']);
    }

    public function testExcludesEngagementOnOutOfRangeLogs(): void
    {
        $inRange = $this->seedLog('2026-03-02 05:00:00', 'delivered');
        $this->logs->recordEngagement($inRange, 'open', '', false);

        $outOfRange = $this->seedLog('2026-02-01 05:00:00', 'delivered');
        $this->logs->recordEngagement($outOfRange, 'open', '', false);
        $this->logs->recordEngagement($outOfRange, 'click', 'https://example.test/old', false);

        $raw = (new EngagementRepository($GLOBALS['wpdb']))->engagement($this->query());

        self::assertSame(1, $raw['open_hits']);
        self::assertSame(1, $raw['open_rows']);
        self::assertSame(0, $raw['click_hits']);
    }

    public function testEmptyRangeReturnsStableZeroAggregates(): void
    {
        $raw = (new EngagementRepository($GLOBALS['wpdb']))->engagement($this->query());

        self::assertSame([
            'open_hits'            => 0,
            'open_automated_hits'  => 0,
            'open_rows'            => 0,
            'open_human_logs'      => 0,
            'click_hits'           => 0,
            'click_automated_hits' => 0,
            'click_rows'           => 0,
            'click_human_logs'     => 0,
        ], $raw);
    }

    private function service(): MailAnalyticsService
    {
        return new MailAnalyticsService(
            new MailAnalyticsRepository($GLOBALS['wpdb'], (new Log())->getTable()),
            null,
            new EngagementRepository($GLOBALS['wpdb'])
        );
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

    private function seedLog(string $createdAtUtc, string $deliveryStatus): int
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            (new Log())->getTable(),
            [
                'status'          => 1,
                'subject'         => 'Engagement seed',
                'to_addr'         => wp_json_encode(['customer@example.test']),
                'connection_id'   => 'conn_primary',
                'source_plugin'   => 'woocommerce',
                'delivery_status' => $deliveryStatus,
                'recipient_count' => 1,
                'created_at'      => $createdAtUtc,
                'created_at_utc'  => $createdAtUtc,
                'updated_at'      => $createdAtUtc,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
        );

        self::assertSame(1, $inserted);

        return (int) $wpdb->insert_id;
    }
}
