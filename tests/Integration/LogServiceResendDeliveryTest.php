<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Mail\Webhook\DeliveryEvent;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Model\LogDeliveryEvent;

/**
 * Drives LogService::update() (the resend path) against the real test DB: a resend over a provider
 * with a send-accept delivery status re-stamps the row after resetDelivery clears the prior outcome,
 * and a resend that falls back to a no-capability provider leaves the row's delivery status null.
 *
 * @internal
 *
 * @coversNothing
 */
final class LogServiceResendDeliveryTest extends IntegrationTestCase
{
    private LogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncate((new Log())->getTable());
        $this->truncate((new LogDeliveryEvent())->getTable());
        $this->service = new LogService();
    }

    public function testResendReStampsSendDerivedDeliveryStatusAndDropsStaleChildRows(): void
    {
        $logId = $this->deliveredLogWithChildEvent();

        $this->service->update(
            $logId,
            Log::SUCCESS,
            ['subject' => 'Subject', 'to' => ['recipient@example.com']],
            null,
            'Amazon SES',
            null,
            null,
            'conn_ses',
            'delivered'
        );

        $reloaded = Log::where('id', $logId)->first();
        $this->assertSame('delivered', $reloaded->delivery_status);
        $this->assertNotNull($reloaded->delivery_updated_at);
        // resetDelivery drops the prior send's child events; a send-derived resend adds none back.
        $this->assertCount(0, LogDeliveryEvent::where('log_id', $logId)->get());
    }

    public function testResendToNoCapabilityProviderClearsDeliveryStatus(): void
    {
        $logId = $this->deliveredLogWithChildEvent();

        $this->service->update(
            $logId,
            Log::SUCCESS,
            ['subject' => 'Subject', 'to' => ['recipient@example.com']],
            null,
            'Primary SMTP',
            null,
            null,
            'conn_default',
            null
        );

        $reloaded = Log::where('id', $logId)->first();
        $this->assertNull($reloaded->delivery_status);
        $this->assertNull($reloaded->delivery_updated_at);
        $this->assertCount(0, LogDeliveryEvent::where('log_id', $logId)->get());
    }

    public function testCollectionResultsPreserveTheArrayServiceContract(): void
    {
        $logId = $this->deliveredLogWithChildEvent();

        $result = $this->service->all(0, 20);
        $this->assertIsArray($result['logs']);
        $this->assertCount(1, $result['logs']);

        $logs = $this->service->getBulk([$logId]);
        $this->assertIsArray($logs);
        $this->assertCount(1, $logs);
        $this->assertSame($logId, (int) $logs[0]->id);
    }

    /**
     * A prior send that already carried a delivery status plus a per-recipient child row, so a resend
     * must be seen to both clear the old outcome and re-stamp (or not) the new one.
     *
     * @return int inserted log id
     */
    private function deliveredLogWithChildEvent(): int
    {
        $log                      = new Log();
        $log->status              = Log::SUCCESS;
        $log->subject             = 'Subject';
        $log->to_addr             = ['recipient@example.com'];
        $log->connection          = 'Amazon SES';
        $log->connection_id       = 'conn_ses';
        $log->delivery_status     = 'delivered';
        $log->delivery_updated_at = gmdate('Y-m-d H:i:s');
        $log->save();
        $logId = (int) $log->id;

        $event = DeliveryEvent::fromArray([
            'recipient' => 'recipient@example.com',
            'status'    => 'delivered',
            'terminal'  => true,
        ]);
        $this->service->recordDeliveryEvent($logId, $event, str_repeat('a', 64));

        return $logId;
    }

    private function truncate(string $table): void
    {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$table}");
    }
}
