<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Model\Log;

/**
 * Verifies LogService::all()'s server-side filter whitelist: each supported filter narrows both the
 * returned rows and the filtered `count` used for pagination.
 *
 * @internal
 *
 * @coversNothing
 */
final class LogsFilterTest extends IntegrationTestCase
{
    private LogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . (new Log())->getTable());
        $this->service = new LogService();

        $this->seed(['to_addr' => 'delivered@example.test', 'delivery_status' => 'delivered', 'connection_id' => 'conn-a', 'source_plugin' => 'woocommerce', 'created_at' => '2026-01-05 10:00:00']);
        $this->seed(['to_addr' => 'bounced-one@example.test', 'delivery_status' => 'bounced', 'connection_id' => 'conn-a', 'source_plugin' => 'contact-form-7', 'created_at' => '2026-01-10 10:00:00']);
        $this->seed(['to_addr' => 'bounced-two@example.test', 'delivery_status' => 'bounced', 'connection_id' => 'conn-b', 'source_plugin' => 'woocommerce', 'created_at' => '2026-01-15 10:00:00']);
        $this->seed(['to_addr' => 'pending@example.test', 'delivery_status' => 'pending', 'connection_id' => 'conn-b', 'source_plugin' => 'bit-form', 'created_at' => '2026-01-20 10:00:00']);
        $this->seed(['to_addr' => 'accepted@example.test', 'delivery_status' => 'accepted', 'connection_id' => 'conn-a', 'source_plugin' => 'woocommerce', 'created_at' => '2026-01-25 10:00:00']);
    }

    public function testNoFiltersReturnsAllSeededRowsAndAnUnfilteredCount(): void
    {
        $result = $this->service->all(0, 20, []);

        self::assertSame(5, $result['count']);
        self::assertCount(5, $result['logs']);
    }

    public function testDeliveryStatusFilterNarrowsRowsAndCount(): void
    {
        $result = $this->service->all(0, 20, ['delivery_status' => 'bounced']);

        self::assertSame(2, $result['count']);
        self::assertCount(2, $result['logs']);
        foreach ($result['logs'] as $log) {
            self::assertSame('bounced', $log->delivery_status);
        }
    }

    public function testDeliveryStatusOutsideTheWhitelistIsIgnoredAndReturnsAllRows(): void
    {
        $result = $this->service->all(0, 20, ['delivery_status' => 'not-a-real-status']);

        self::assertSame(5, $result['count']);
        self::assertCount(5, $result['logs']);
    }

    public function testConnectionIdFilterNarrowsRowsAndCount(): void
    {
        $result = $this->service->all(0, 20, ['connection_id' => 'conn-a']);

        self::assertSame(3, $result['count']);
        self::assertCount(3, $result['logs']);
        foreach ($result['logs'] as $log) {
            self::assertSame('conn-a', $log->connection_id);
        }
    }

    public function testSourcePluginFilterNarrowsRowsAndCount(): void
    {
        $result = $this->service->all(0, 20, ['source_plugin' => 'woocommerce']);

        self::assertSame(3, $result['count']);
        self::assertCount(3, $result['logs']);
        foreach ($result['logs'] as $log) {
            self::assertSame('woocommerce', $log->source_plugin);
        }
    }

    public function testDateRangeFilterNarrowsRowsAndCount(): void
    {
        $result = $this->service->all(0, 20, ['date_from' => '2026-01-10', 'date_to' => '2026-01-20']);

        self::assertSame(3, $result['count']);
        self::assertCount(3, $result['logs']);
    }

    public function testMalformedDateFilterIsIgnoredAndReturnsAllRows(): void
    {
        $result = $this->service->all(0, 20, ['date_from' => 'not-a-date']);

        self::assertSame(5, $result['count']);
        self::assertCount(5, $result['logs']);
    }

    public function testCombinedFiltersIntersectAndCountReflectsTheIntersection(): void
    {
        $result = $this->service->all(0, 20, ['connection_id' => 'conn-a', 'source_plugin' => 'woocommerce']);

        self::assertSame(2, $result['count']);
        self::assertCount(2, $result['logs']);
        foreach ($result['logs'] as $log) {
            self::assertSame('conn-a', $log->connection_id);
            self::assertSame('woocommerce', $log->source_plugin);
        }
    }

    public function testToAddrFilterCombinedWithDeliveryStatusStillNarrowsCorrectly(): void
    {
        $result = $this->service->all(0, 20, ['to_addr' => 'bounced', 'delivery_status' => 'bounced']);

        self::assertSame(2, $result['count']);
        self::assertCount(2, $result['logs']);
    }

    public function testFilteredCountDrivesPaginationAcrossPages(): void
    {
        $firstPage = $this->service->all(0, 1, ['delivery_status' => 'bounced']);

        self::assertSame(2, $firstPage['count']);
        self::assertCount(1, $firstPage['logs']);

        $secondPage = $this->service->all(1, 1, ['delivery_status' => 'bounced']);

        self::assertSame(2, $secondPage['count']);
        self::assertCount(1, $secondPage['logs']);
        self::assertNotSame($firstPage['logs'][0]->id, $secondPage['logs'][0]->id);
    }

    public function testSendStatusFilterNarrowsToSuccessOrFailure(): void
    {
        // The 5 seeded rows are all successes; add one failed send to prove the status split.
        global $wpdb;
        $wpdb->insert(
            (new Log())->getTable(),
            [
                'status'          => Log::ERROR,
                'subject'         => 'Failed send',
                'to_addr'         => wp_json_encode(['failed@example.test']),
                'delivery_status' => 'bounced',
                'connection_id'   => 'conn-a',
                'source_plugin'   => 'woocommerce',
                'created_at'      => '2026-01-28 10:00:00',
                'created_at_utc'  => '2026-01-28 10:00:00',
                'updated_at'      => '2026-01-28 10:00:00',
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        $failed = $this->service->all(0, 20, ['status' => 'failed']);
        self::assertSame(1, $failed['count']);
        self::assertCount(1, $failed['logs']);

        $sent = $this->service->all(0, 20, ['status' => 'sent']);
        self::assertSame(5, $sent['count']);

        // An unrecognized status string is ignored rather than applied.
        $bogus = $this->service->all(0, 20, ['status' => 'whatever']);
        self::assertSame(6, $bogus['count']);
    }

    public function testConnectionIdFilterAlsoMatchesLegacyConnectionLabelRows(): void
    {
        // Legacy row: no stable connection_id, only the mutable `connection` label — the same rows the
        // analytics COALESCE(connection_id, connection) grouping ranks by label. The connection_id
        // filter must still resolve them, or the "View in logs" deep-link lands on an empty list.
        global $wpdb;
        $wpdb->insert(
            (new Log())->getTable(),
            [
                'status'          => Log::SUCCESS,
                'subject'         => 'Legacy label row',
                'to_addr'         => wp_json_encode(['legacy@example.test']),
                'delivery_status' => 'delivered',
                'connection'      => 'Legacy Label',
                'connection_id'   => '',
                'source_plugin'   => 'woocommerce',
                'created_at'      => '2026-01-30 10:00:00',
                'created_at_utc'  => '2026-01-30 10:00:00',
                'updated_at'      => '2026-01-30 10:00:00',
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        $result = $this->service->all(0, 20, ['connection_id' => 'Legacy Label']);

        self::assertSame(1, $result['count']);
        self::assertCount(1, $result['logs']);
        self::assertSame('Legacy Label', $result['logs'][0]->connection);
    }

    /**
     * @param array<string,string> $overrides
     */
    private function seed(array $overrides): void
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            (new Log())->getTable(),
            [
                'status'           => Log::SUCCESS,
                'subject'          => 'Filter test subject',
                'to_addr'          => wp_json_encode([$overrides['to_addr']]),
                'delivery_status'  => $overrides['delivery_status'],
                'connection_id'    => $overrides['connection_id'],
                'source_plugin'    => $overrides['source_plugin'],
                'created_at'       => $overrides['created_at'],
                'created_at_utc'   => $overrides['created_at'],
                'updated_at'       => $overrides['created_at'],
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        self::assertSame(1, $inserted);
    }
}
