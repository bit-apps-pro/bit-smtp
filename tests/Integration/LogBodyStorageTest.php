<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Settings\PluginSettings;

/**
 * Drives LogService::save()/bulkInsert() against the real DB to prove the `log_store_body` privacy
 * preference is honored on the write path: the message body is stored, redacted, or dropped per mode,
 * while every other detail is untouched.
 *
 * @internal
 *
 * @coversNothing
 */
final class LogBodyStorageTest extends IntegrationTestCase
{
    private LogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncate((new Log())->getTable());
        $this->service = new LogService();
    }

    public function testFullModeStoresTheBodyVerbatim(): void
    {
        $this->setBodyMode('full');

        $log = $this->saveAndFetch('Full body row');

        $this->assertSame('<p>Secret body</p>', $log->details['message']);
    }

    public function testRedactedModeReplacesTheBodyButKeepsTheKey(): void
    {
        $this->setBodyMode('redacted');

        $log = $this->saveAndFetch('Redacted body row');

        $this->assertArrayHasKey('message', $log->details);
        $this->assertSame('[redacted]', $log->details['message']);
    }

    public function testMetadataModeDropsTheBodyKey(): void
    {
        $this->setBodyMode('metadata');

        $log = $this->saveAndFetch('Metadata body row');

        $this->assertArrayNotHasKey('message', $log->details);
        // Non-body detail survives.
        $this->assertSame([['connection' => 'Primary SMTP', 'status' => 'sent', 'error' => null]], $log->details['attempts']);
    }

    public function testBulkInsertHonorsTheRedactedMode(): void
    {
        $this->setBodyMode('redacted');

        $this->assertTrue($this->service->bulkInsert([[
            'status' => Log::SUCCESS,
            'data'   => [
                'subject' => 'Bulk redacted row',
                'to'      => ['recipient@example.test'],
                'from'    => 'From <from@example.test>',
                'message' => '<p>Secret body</p>',
            ],
        ]]));

        $log = Log::where('subject', 'Bulk redacted row')->first();
        $this->assertSame('[redacted]', $log->details['message']);
    }

    private function setBodyMode(string $mode): void
    {
        PluginSettings::make()->set('log_store_body', $mode)->save();
    }

    private function saveAndFetch(string $subject): Log
    {
        $this->service->save(Log::SUCCESS, [
            'subject'  => $subject,
            'to'       => ['recipient@example.test'],
            'from'     => 'From <from@example.test>',
            'message'  => '<p>Secret body</p>',
            'attempts' => [['connection' => 'Primary SMTP', 'status' => 'sent', 'error' => null]],
        ]);

        return Log::where('subject', $subject)->first();
    }

    private function truncate(string $table): void
    {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$table}");
    }
}
