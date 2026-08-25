<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Model\LogEngagementEvent;
use BitApps\SMTP\Settings\PluginSettings;
use BitSmtpEngagementTableMigration;

// Global-namespace migration class, included directly (migrations are not PSR-4 autoloaded).
require_once \dirname(__DIR__, 2) . '/backend/db/Migrations/BitSmtpEngagementTableMigration.php';

/**
 * Exercises the Phase 8 tracking foundation against the real WordPress test DB: the
 * log_engagement_events schema, the ON DUPLICATE KEY fold in LogService::recordEngagement(),
 * tracking-id resolution, migration idempotency, and the purge-gated table drop.
 *
 * @internal
 *
 * @coversNothing
 */
final class EngagementFoundationTest extends IntegrationTestCase
{
    private LogService $service;

    private string $engagementTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engagementTable = (new LogEngagementEvent())->getTable();
        $this->migrate();
        $this->truncate($this->engagementTable);
        $this->truncate((new Log())->getTable());
        $this->service = new LogService();
    }

    public function testMigrationCreatesTheEngagementTableWithColumnsAndUniqueKey(): void
    {
        global $wpdb;

        $table = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($this->engagementTable))
        );
        self::assertSame($this->engagementTable, $table);

        $columns = [];
        foreach ($wpdb->get_results("SHOW COLUMNS FROM `{$this->engagementTable}`") as $column) {
            $columns[$column->Field] = $column;
        }
        foreach (['id', 'log_id', 'type', 'target', 'hits', 'automated_hits', 'first_at', 'last_at', 'event_key', 'created_at', 'updated_at'] as $expected) {
            self::assertArrayHasKey($expected, $columns, "{$expected} column should exist");
        }

        $indexes = $this->indexes();
        self::assertArrayHasKey('event_key_UNIQUE', $indexes, 'event_key must carry a UNIQUE index');
        self::assertSame(0, (int) $indexes['event_key_UNIQUE']->Non_unique, 'event_key index must be unique');
        self::assertArrayHasKey('log_id_INDEX', $indexes, 'log_id must be indexed for per-log lookups');
    }

    public function testMigrationIsIdempotentWhenReRun(): void
    {
        // Activation invokes the migration list on every request; a second up() must not error on a
        // duplicate table or index.
        $this->migrate();
        $this->migrate();

        global $wpdb;
        $table = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($this->engagementTable))
        );
        self::assertSame($this->engagementTable, $table);
    }

    public function testRecordEngagementFoldsARefireOnTheUniqueKeyInsteadOfDuplicating(): void
    {
        $logId = $this->seedLog();

        $this->service->recordEngagement($logId, 'open', '', false);
        $this->service->recordEngagement($logId, 'open', '', false);

        $rows = LogEngagementEvent::where('log_id', $logId)->get();
        self::assertCount(1, $rows, 'a re-fired open must fold onto the same row');
        self::assertSame(2, (int) $rows[0]->hits);
        self::assertSame(0, (int) $rows[0]->automated_hits);
    }

    public function testAutomatedFireIncrementsOnlyTheAutomatedCounter(): void
    {
        $logId = $this->seedLog();

        $this->service->recordEngagement($logId, 'open', '', false);
        $this->service->recordEngagement($logId, 'open', '', true);

        $row = LogEngagementEvent::where('log_id', $logId)->first();
        self::assertSame(2, (int) $row->hits);
        self::assertSame(1, (int) $row->automated_hits, 'a machine-fired hit increments automated_hits');
    }

    public function testDistinctClickTargetsProduceSeparateRows(): void
    {
        $logId = $this->seedLog();

        $this->service->recordEngagement($logId, 'click', 'https://example.test/a', false);
        $this->service->recordEngagement($logId, 'click', 'https://example.test/b', false);
        $this->service->recordEngagement($logId, 'click', 'https://example.test/a', false);

        $rows = $this->service->engagementFor($logId);
        self::assertCount(2, $rows, 'each distinct URL keeps its own folded row');

        $byTarget = [];
        foreach ($rows as $row) {
            $byTarget[$row['target']] = $row;
        }
        self::assertSame(2, $byTarget['https://example.test/a']['hits']);
        self::assertSame(1, $byTarget['https://example.test/b']['hits']);
    }

    public function testEngagementForReturnsAllRowsForALog(): void
    {
        $logId = $this->seedLog();

        $this->service->recordEngagement($logId, 'open', '', false);
        $this->service->recordEngagement($logId, 'click', 'https://example.test/x', true);

        $rows = $this->service->engagementFor($logId);
        self::assertCount(2, $rows);
        self::assertContainsEquals('open', array_column($rows, 'type'));
        self::assertContainsEquals('click', array_column($rows, 'type'));
    }

    public function testFindLogIdByTrackingIdRoundTrips(): void
    {
        $trackingId = 'a1b2c3d4-e5f6-7890-abcd-ef0123456789';
        $logId      = $this->seedLog($trackingId);

        self::assertSame($logId, $this->service->findLogIdByTrackingId($trackingId));
        self::assertNull($this->service->findLogIdByTrackingId('no-such-token'));
        self::assertNull($this->service->findLogIdByTrackingId(''));
    }

    public function testDownDropsTheTableWhenPurgeIsEnabled(): void
    {
        // Purge defaults to true (schema default) with no preferences blob written.
        try {
            (new BitSmtpEngagementTableMigration())->down();

            global $wpdb;
            $table = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($this->engagementTable))
            );
            self::assertNull($table, 'the engagement table must be dropped on purge-enabled uninstall');
        } finally {
            $this->migrate();
        }
    }

    public function testDownPreservesTheTableWhenPurgeIsDisabled(): void
    {
        PluginSettings::make()->set('uninstall_purge', false)->save();

        try {
            (new BitSmtpEngagementTableMigration())->down();

            global $wpdb;
            $table = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($this->engagementTable))
            );
            self::assertSame($this->engagementTable, $table, 'a purge opt-out must retain the table');
        } finally {
            delete_option(PluginSettings::OPTION_NAME);
            $this->migrate();
        }
    }

    private function migrate(): void
    {
        (new BitSmtpEngagementTableMigration())->up();
    }

    /**
     * Persist a bare log row and return its id, optionally stamping a tracking token.
     */
    private function seedLog(?string $trackingId = null): int
    {
        $log              = new Log();
        $log->status      = Log::SUCCESS;
        $log->subject     = 'Subject';
        $log->to_addr     = ['recipient@example.com'];
        $log->tracking_id = $trackingId;
        $log->save();

        return (int) $log->id;
    }

    /**
     * @return array<string,object> SHOW INDEX rows keyed by index name
     */
    private function indexes(): array
    {
        global $wpdb;

        $indexes = [];
        foreach ($wpdb->get_results("SHOW INDEX FROM `{$this->engagementTable}`") as $row) {
            $indexes[$row->Key_name] = $row;
        }

        return $indexes;
    }

    private function truncate(string $table): void
    {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE `{$table}`");
    }
}
