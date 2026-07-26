<?php

namespace BitApps\SMTP\Tests\Integration\Webhook;

use BitApps\SMTP\Deps\BitApps\WPDatabase\Collection;
use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Webhook\DeliveryEvent;
use BitApps\SMTP\Mail\Webhook\DeliveryEventRecorder;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Model\LogDeliveryEvent;
use BitApps\SMTP\Tests\Integration\IntegrationTestCase;

/**
 * Drives DeliveryEventRecorder against the real test DB: correlation by message-id and tracking-id,
 * connection scoping, empty-key rejection, replay dedup, and the uncorrelated-drop path.
 *
 * @internal
 *
 * @coversNothing
 */
final class DeliveryEventRecorderTest extends IntegrationTestCase
{
    private DeliveryEventRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncate((new Log())->getTable());
        $this->truncate((new LogDeliveryEvent())->getTable());
        $this->recorder = new DeliveryEventRecorder(new LogService());
    }

    public function testCorrelatesByMessageId(): void
    {
        $logId = $this->createLog('conn_a', 'msg-1', 'trk-1');

        $recorded = $this->recorder->record(
            $this->event(['message_id' => 'msg-1', 'status' => 'delivered', 'terminal' => true]),
            $this->connection('conn_a')
        );

        $this->assertTrue($recorded);
        $this->assertCount(1, $this->childRows($logId));
        $this->assertSame('delivered', $this->reloadLog($logId)->delivery_status);
    }

    public function testFallsBackToTrackingIdWhenMessageIdIsNull(): void
    {
        $logId = $this->createLog('conn_a', null, 'trk-2');

        $recorded = $this->recorder->record(
            $this->event(['tracking_id' => 'trk-2', 'status' => 'delivered', 'terminal' => true]),
            $this->connection('conn_a')
        );

        $this->assertTrue($recorded);
        $this->assertCount(1, $this->childRows($logId));
    }

    public function testEmptyKeysNeverMatchNullKeyedRow(): void
    {
        $logId = $this->createLog('conn_a', null, null);

        $recorded = $this->recorder->record(
            $this->event(['status' => 'delivered', 'terminal' => true]),
            $this->connection('conn_a')
        );

        $this->assertFalse($recorded);
        $this->assertCount(0, $this->childRows($logId));
    }

    public function testConnectionScopingRejectsForeignRow(): void
    {
        $logId = $this->createLog('conn_b', 'msg-shared', null);

        $recorded = $this->recorder->record(
            $this->event(['message_id' => 'msg-shared', 'status' => 'delivered', 'terminal' => true]),
            $this->connection('conn_a')
        );

        $this->assertFalse($recorded);
        $this->assertCount(0, $this->childRows($logId));
    }

    public function testReplayIsDedupedToOneChildRow(): void
    {
        $logId = $this->createLog('conn_a', 'msg-1', null);
        $event = $this->event(['message_id' => 'msg-1', 'status' => 'delivered', 'terminal' => true, 'occurred_at' => '2024-01-01 10:00:00']);

        $this->assertTrue($this->recorder->record($event, $this->connection('conn_a')));
        $this->assertTrue($this->recorder->record($event, $this->connection('conn_a')));

        $this->assertCount(1, $this->childRows($logId), 'a replayed event must not create a second child row');
        $this->assertSame('delivered', $this->reloadLog($logId)->delivery_status);
    }

    public function testUncorrelatedEventIsDropped(): void
    {
        $this->createLog('conn_a', 'msg-1', null);

        $recorded = $this->recorder->record(
            $this->event(['message_id' => 'no-such-id', 'status' => 'bounced', 'terminal' => true]),
            $this->connection('conn_a')
        );

        $this->assertFalse($recorded);
        $this->assertCount(0, LogDeliveryEvent::get());
    }

    /**
     * @return int inserted log id
     */
    private function createLog(string $connectionId, ?string $messageId, ?string $trackingId): int
    {
        $log                = new Log();
        $log->status        = Log::SUCCESS;
        $log->subject       = 'Subject';
        $log->to_addr       = ['recipient@example.com'];
        $log->connection    = $connectionId;
        $log->connection_id = $connectionId;
        $log->message_id    = $messageId;
        $log->tracking_id   = $trackingId;
        $log->save();

        return (int) $log->id;
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function event(array $overrides): DeliveryEvent
    {
        return DeliveryEvent::fromArray(array_merge([
            'recipient' => 'recipient@example.com',
            'status'    => 'delivered',
            'terminal'  => true,
        ], $overrides));
    }

    private function connection(string $id): Connection
    {
        // Correlation scopes on the connection id, so conn_a/conn_b stay distinct via getId() alone.
        return Connection::fromArray(['id' => $id, 'provider' => 'postmark', 'kind' => 'api']);
    }

    private function reloadLog(int $logId): Log
    {
        return Log::where('id', $logId)->first();
    }

    /**
     * @return array<int,LogDeliveryEvent>
     */
    private function childRows(int $logId): array
    {
        $rows = LogDeliveryEvent::where('log_id', $logId)->get();

        if ($rows instanceof Collection) {
            return $rows->all();
        }

        return \is_array($rows) ? $rows : [];
    }

    private function truncate(string $table): void
    {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$table}");
    }
}
