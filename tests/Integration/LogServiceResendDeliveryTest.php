<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Mail\Webhook\DeliveryEvent;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Model\LogDeliveryEvent;
use DateTimeImmutable;
use DateTimeZone;

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

    public function testPersistenceMethodsRetainRoutingAttribution(): void
    {
        $this->service->save(
            Log::SUCCESS,
            ['subject' => 'Saved', 'to' => ['recipient@example.com']],
            null,
            'Primary',
            null,
            null,
            'conn_primary',
            'woocommerce',
            'rule',
            2
        );

        $saved = Log::where('subject', 'Saved')->first();
        $this->assertSame('woocommerce', $saved->source_plugin);
        $this->assertSame('rule', $saved->routing_type);
        $this->assertSame(2, $saved->routing_rule_index);
        $this->assertSame('Saved', $saved->subject_pattern);
        $this->assertSame(1, $saved->recipient_count);
        $this->assertNotEmpty($saved->created_at_utc);
        $savedAnalyticsTimestamp = $saved->created_at_utc;

        $this->service->update(
            (int) $saved->id,
            Log::SUCCESS,
            ['subject' => 'Saved', 'to' => ['recipient@example.com']],
            null,
            'Fallback',
            null,
            null,
            'conn_fallback',
            null,
            'woocommerce',
            'fallback',
            null
        );

        $updated = Log::where('id', $saved->id)->first();
        $this->assertSame('woocommerce', $updated->source_plugin);
        $this->assertSame('fallback', $updated->routing_type);
        $this->assertNull($updated->routing_rule_index);
        $this->assertSame($savedAnalyticsTimestamp, $updated->created_at_utc);

        $this->service->bulkInsert([[
            'status'              => Log::SUCCESS,
            'data'                => ['subject' => 'Bulk', 'to' => ['recipient@example.com']],
            'source_plugin'       => 'edd',
            'routing_type'        => 'native',
            'routing_rule_index'  => null,
        ]]);

        $bulk = Log::where('subject', 'Bulk')->first();
        $this->assertSame('edd', $bulk->source_plugin);
        $this->assertSame('native', $bulk->routing_type);
        $this->assertNull($bulk->routing_rule_index);
        $this->assertSame('Bulk', $bulk->subject_pattern);
        $this->assertSame(1, $bulk->recipient_count);
    }

    public function testPersistenceStoresOnlyTheRedactedSubjectPatternForAnalytics(): void
    {
        $this->service->save(
            Log::SUCCESS,
            [
                'subject' => 'Receipt 123456 for Jane Example https://example.test/orders/123456',
                'to'      => ['jane@example.test', 'john@example.test'],
            ]
        );

        $log = Log::where('connection_id', null)->first();

        $this->assertSame('Receipt <number> for <text> <text> <url>', $log->subject_pattern);
        $this->assertSame(2, $log->recipient_count);
        $this->assertStringNotContainsString('jane@example.test', $log->subject_pattern);
        $this->assertStringNotContainsString('Jane', $log->subject_pattern);
        $this->assertStringNotContainsString('123456', $log->subject_pattern);
    }

    public function testSaveCountsMissingRecipientsAsZero(): void
    {
        $this->service->save(Log::SUCCESS, ['subject' => 'No recipients']);

        $log = Log::where('subject', 'No recipients')->first();

        $this->assertSame([], $log->to_addr);
        $this->assertSame(0, $log->recipient_count);
    }

    public function testNewSaveBulkAndNativeLogsPersistIndependentUtcAnalyticsTimestamps(): void
    {
        $previousTimezone = get_option('timezone_string');
        update_option('timezone_string', 'Asia/Dhaka');
        $before = time();

        try {
            $this->service->save(Log::SUCCESS, ['subject' => 'Saved UTC', 'to' => ['recipient@example.com']]);
            $this->service->bulkInsert([[
                'status' => Log::SUCCESS,
                'data'   => ['subject' => 'Bulk UTC', 'to' => ['recipient@example.com']],
            ]]);
            $native          = new Log();
            $native->status  = Log::SUCCESS;
            $native->subject = 'Native UTC';
            $native->to_addr = ['recipient@example.com'];
            $native->save();

            $logs = Log::where('subject', ['Saved UTC', 'Bulk UTC', 'Native UTC'])->orderBy('subject')->get();
            $this->assertCount(3, $logs);
            foreach ($logs as $log) {
                $stored = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $log->created_at_utc, new DateTimeZone('UTC'));
                $this->assertNotFalse($stored);
                $this->assertLessThanOrEqual(2, abs($stored->getTimestamp() - $before));
            }
        } finally {
            update_option('timezone_string', $previousTimezone);
        }
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
