<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Mail\Dispatch\FailureCategory;
use BitApps\SMTP\Model\Log;

/**
 * Drives LogService::save()/update()/bulkInsert() against the real test DB: each write path must
 * persist a passed failure_class onto the log row's own column, not merely into the details JSON.
 *
 * @internal
 *
 * @coversNothing
 */
final class LogFailureClassPersistenceTest extends IntegrationTestCase
{
    private LogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncate((new Log())->getTable());
        $this->service = new LogService();
    }

    public function testSavePersistsTheFailureClass(): void
    {
        $this->service->save(
            Log::ERROR,
            ['subject' => 'Saved failure', 'to' => ['recipient@example.com']],
            ['boom'],
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            FailureCategory::TRANSIENT
        );

        $log = Log::where('subject', 'Saved failure')->first();
        $this->assertSame(FailureCategory::TRANSIENT, $log->failure_class);
    }

    public function testSaveLeavesFailureClassNullWhenNotProvided(): void
    {
        $this->service->save(Log::SUCCESS, [
            'subject' => 'Saved success',
            'to'      => ['recipient@example.com'],
        ]);

        $log = Log::where('subject', 'Saved success')->first();
        $this->assertNull($log->failure_class);
    }

    public function testBulkInsertPersistsTheFailureClass(): void
    {
        $this->assertTrue($this->service->bulkInsert([[
            'status'        => Log::ERROR,
            'data'          => ['subject' => 'Bulk failure', 'to' => ['recipient@example.com']],
            'failure_class' => FailureCategory::PERMANENT,
        ]]));

        $log = Log::where('subject', 'Bulk failure')->first();
        $this->assertSame(FailureCategory::PERMANENT, $log->failure_class);
    }

    public function testBulkInsertLeavesFailureClassNullWhenAbsent(): void
    {
        $this->assertTrue($this->service->bulkInsert([[
            'status' => Log::SUCCESS,
            'data'   => ['subject' => 'Bulk success', 'to' => ['recipient@example.com']],
        ]]));

        $log = Log::where('subject', 'Bulk success')->first();
        $this->assertNull($log->failure_class);
    }

    public function testUpdatePersistsTheFailureClassOnARetriedFailure(): void
    {
        $this->service->save(Log::ERROR, [
            'subject' => 'Resend failure',
            'to'      => ['recipient@example.com'],
        ], ['boom']);
        $saved = Log::where('subject', 'Resend failure')->first();

        $this->service->update(
            (int) $saved->id,
            Log::ERROR,
            ['subject' => 'Resend failure', 'to' => ['recipient@example.com']],
            ['still failing'],
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            FailureCategory::AUTH
        );

        $updated = Log::where('id', $saved->id)->first();
        $this->assertSame(FailureCategory::AUTH, $updated->failure_class);
    }

    private function truncate(string $table): void
    {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$table}");
    }
}
