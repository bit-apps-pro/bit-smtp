<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Model\LogDeliveryEvent;

/**
 * Verifies that privacy-sensitive delivery-event children follow their parent log through both
 * administrator deletion and automatic retention cleanup.
 *
 * @internal
 *
 * @coversNothing
 */
final class LogServiceDeliveryCleanupTest extends IntegrationTestCase
{
    private LogService $service;

    private string $logsTable;

    private string $eventsTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logsTable   = (new Log())->getTable();
        $this->eventsTable = (new LogDeliveryEvent())->getTable();
        $this->truncate($this->eventsTable);
        $this->truncate($this->logsTable);
        Config::deleteOption('log_retention');
        Config::deleteOption('log_deleted_at');
        $this->service = new LogService();
    }

    public function testManualDeleteRemovesOnlyTheSelectedParentsAndTheirDeliveryEventChildren(): void
    {
        $deletedLogId   = $this->createLog('Delete this log');
        $preservedLogId = $this->createLog('Preserve this log');
        $this->createEvent($deletedLogId, 'delete@example.test');
        $this->createEvent($preservedLogId, 'preserve@example.test');

        self::assertTrue($this->service->delete([$deletedLogId, '0 OR 1 = 1']));

        self::assertEmpty(Log::where('id', $deletedLogId)->first());
        self::assertSame(0, LogDeliveryEvent::where('log_id', $deletedLogId)->count());
        self::assertNotNull(Log::where('id', $preservedLogId)->first());
        self::assertSame(1, LogDeliveryEvent::where('log_id', $preservedLogId)->count());
    }

    public function testRetentionRemovesExpiredParentsAndChildrenButPreservesCurrentRows(): void
    {
        $expiredLogId = $this->createLog('Expired log', gmdate('Y-m-d H:i:s', time() - (DAY_IN_SECONDS * 3)));
        $currentLogId = $this->createLog('Current log', gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS));
        $this->createEvent($expiredLogId, 'expired@example.test');
        $this->createEvent($currentLogId, 'current@example.test');
        Config::updateOption('log_retention', 1, true);

        self::assertNotFalse($this->service->deleteOlder());

        self::assertEmpty(Log::where('id', $expiredLogId)->first());
        self::assertSame(0, LogDeliveryEvent::where('log_id', $expiredLogId)->count());
        self::assertNotNull(Log::where('id', $currentLogId)->first());
        self::assertSame(1, LogDeliveryEvent::where('log_id', $currentLogId)->count());
    }

    public function testManualDeleteKeepsTheParentWhenChildCleanupFails(): void
    {
        $logId = $this->createLog('Do not delete after child cleanup failure');
        $this->createEvent($logId, 'failure@example.test');

        $offlineTable           = $this->eventsTable . '_offline';
        global $wpdb;
        self::assertNotFalse($wpdb->query("RENAME TABLE `{$this->eventsTable}` TO `{$offlineTable}`"));
        $previousSuppressErrors = $wpdb->suppress_errors(true);

        try {
            self::assertFalse($this->service->delete([$logId]));
            self::assertNotNull(Log::where('id', $logId)->first());
        } finally {
            $wpdb->suppress_errors($previousSuppressErrors);
            self::assertNotFalse($wpdb->query("RENAME TABLE `{$offlineTable}` TO `{$this->eventsTable}`"));
        }
    }

    public function testRetentionKeepsExpiredParentWhenChildCleanupFails(): void
    {
        $logId = $this->createLog('Retain after child cleanup failure', gmdate('Y-m-d H:i:s', time() - (DAY_IN_SECONDS * 3)));
        $this->createEvent($logId, 'retention-failure@example.test');
        Config::updateOption('log_retention', 1, true);

        $offlineTable           = $this->eventsTable . '_offline';
        global $wpdb;
        self::assertNotFalse($wpdb->query("RENAME TABLE `{$this->eventsTable}` TO `{$offlineTable}`"));
        $previousSuppressErrors = $wpdb->suppress_errors(true);

        try {
            self::assertFalse($this->service->deleteOlder());
            self::assertNotNull(Log::where('id', $logId)->first());
        } finally {
            $wpdb->suppress_errors($previousSuppressErrors);
            self::assertNotFalse($wpdb->query("RENAME TABLE `{$offlineTable}` TO `{$this->eventsTable}`"));
        }
    }

    private function createLog(string $subject, ?string $createdAt = null): int
    {
        $log          = new Log();
        $log->status  = Log::SUCCESS;
        $log->subject = $subject;
        $log->to_addr = ['recipient@example.test'];
        $log->save();

        if ($createdAt !== null) {
            global $wpdb;
            self::assertSame(1, $wpdb->update($this->logsTable, ['created_at' => $createdAt], ['id' => $log->id]));
        }

        return (int) $log->id;
    }

    private function createEvent(int $logId, string $recipient): void
    {
        $event             = new LogDeliveryEvent();
        $event->log_id     = $logId;
        $event->recipient  = $recipient;
        $event->status     = 'delivered';
        $event->terminal   = 1;
        $event->detail     = 'Provider detail containing recipient PII';
        $event->event_hash = hash('sha256', $logId . ':' . $recipient);

        self::assertTrue((bool) $event->save());
    }

    private function truncate(string $table): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->query("TRUNCATE TABLE `{$table}`"));
    }
}
