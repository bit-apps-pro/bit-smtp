<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Mail\Webhook\DeliveryEvent;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Model\LogDeliveryEvent;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Drives LogService::update() (the resend path) against the real test DB: a resend over a provider
 * clears the previous verified outcome before re-stamping only the new hand-off state, never a
 * terminal result that no verified webhook confirmed.
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
        $this->truncateTables((new Log())->getTable(), (new LogDeliveryEvent())->getTable());
        $this->service = new LogService();
    }

    public function testResendRejectsAnUnverifiedTerminalDeliveryStatusAndDropsStaleChildRows(): void
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
        $this->assertNull($reloaded->delivery_status);
        $this->assertNull($reloaded->delivery_updated_at);
        // resetDelivery drops the prior send's child events; an unverified hand-off adds none back.
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

    public function testResendDoesNotBlankAnExistingSenderWhenFromIsAbsentFromDetails(): void
    {
        $this->service->save(
            Log::SUCCESS,
            ['subject' => 'Subject', 'to' => ['recipient@example.com'], 'from' => 'Jane <jane@example.test>']
        );
        $logId = (int) Log::where('connection_id', null)->first()->id;

        // A retry/resend detail payload that carries no 'from' key at all (not merely an empty one).
        $this->service->update(
            $logId,
            Log::SUCCESS,
            ['subject' => 'Subject', 'to' => ['recipient@example.com']]
        );

        $reloaded = Log::where('id', $logId)->first();
        $this->assertSame('Jane <jane@example.test>', $reloaded->sender);
    }

    public function testBulkLoggingRejectsUnverifiedTerminalDeliveryStatus(): void
    {
        $this->assertTrue($this->service->bulkInsert([[
            'status'              => Log::SUCCESS,
            'data'                => ['subject' => 'Handoff', 'to' => ['recipient@example.com']],
            'connection'          => 'Primary SMTP',
            'connection_id'       => 'conn_smtp',
            'delivery_status'     => 'delivered',
            'delivery_updated_at' => '2026-08-10 00:00:00',
        ]]));

        $log = Log::where('connection_id', 'conn_smtp')->first();
        $this->assertNull($log->delivery_status);
        $this->assertNull($log->delivery_updated_at);
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

    public function testLoggingContinuityStartsConservativelyAndOnlyChangesAcrossDisableResume(): void
    {
        Config::deleteOption(Config::LOGGING_CONTINUITY_FROM_OPTION);
        $firstBoot = new LogService();
        $initial   = Config::getOption(Config::LOGGING_CONTINUITY_FROM_OPTION, null);
        $this->assertIsString($initial);
        $this->assertSame($initial, $firstBoot->initializeLoggingContinuity());

        // Unchanged enabled saves and unrelated partial settings retain the continuity marker.
        $knownContinuous = '2000-01-01 00:00:00';
        Config::updateOption(Config::LOGGING_CONTINUITY_FROM_OPTION, $knownContinuous, true);
        $this->assertTrue($firstBoot->setEnabled(true));
        Config::updateOption('options', ['connections' => []]);
        $this->assertSame($knownContinuous, Config::getOption(Config::LOGGING_CONTINUITY_FROM_OPTION, null));

        $this->assertTrue($firstBoot->setEnabled(false));
        $this->assertNull(Config::getOption(Config::LOGGING_CONTINUITY_FROM_OPTION, null));
        $this->assertTrue($firstBoot->setEnabled(true));
        $resumed = Config::getOption(Config::LOGGING_CONTINUITY_FROM_OPTION, null);
        $this->assertIsString($resumed);
        $this->assertGreaterThan($knownContinuous, $resumed);
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

    public function testSavePersistsCcAndBccAsJsonArrays(): void
    {
        $this->service->save(
            Log::SUCCESS,
            [
                'subject' => 'Cc Bcc save',
                'to'      => ['to@example.test'],
                'cc'      => ['cc1@example.test', 'cc2@example.test'],
                'bcc'     => ['bcc@example.test'],
            ]
        );

        $log = Log::where('subject', 'Cc Bcc save')->first();
        $this->assertSame(['cc1@example.test', 'cc2@example.test'], $log->cc);
        $this->assertSame(['bcc@example.test'], $log->bcc);
        // recipient_count stays to-based: cc/bcc never inflate it.
        $this->assertSame(1, $log->recipient_count);
    }

    public function testBulkInsertPersistsCcAndBccAsJsonArrays(): void
    {
        $this->service->bulkInsert([[
            'status' => Log::SUCCESS,
            'data'   => [
                'subject' => 'Cc Bcc bulk',
                'to'      => ['to@example.test'],
                'cc'      => ['cc@example.test'],
                'bcc'     => ['bcc@example.test'],
            ],
        ]]);

        $log = Log::where('subject', 'Cc Bcc bulk')->first();
        $this->assertSame(['cc@example.test'], $log->cc);
        $this->assertSame(['bcc@example.test'], $log->bcc);
        $this->assertSame(1, $log->recipient_count);
    }

    public function testSaveStoresEmptyArraysWhenNoCcOrBcc(): void
    {
        $this->service->save(
            Log::SUCCESS,
            ['subject' => 'No cc bcc', 'to' => ['to@example.test']]
        );

        $log = Log::where('subject', 'No cc bcc')->first();
        $this->assertSame([], $log->cc);
        $this->assertSame([], $log->bcc);
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
}
