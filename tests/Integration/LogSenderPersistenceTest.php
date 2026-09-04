<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Controllers\LogController;
use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Model\Log;

/**
 * Drives LogService::save()/update()/bulkInsert() against the real test DB: each write path must
 * capture the connection-applied From into the log row's `sender` column instead of merely
 * stripping it out of the `details` JSON blob.
 *
 * @internal
 *
 * @coversNothing
 */
final class LogSenderPersistenceTest extends IntegrationTestCase
{
    private LogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables((new Log())->getTable());
        $this->service = new LogService();
    }

    public function testSavePersistsSenderAndStripsFromOutOfDetails(): void
    {
        $this->service->save(Log::SUCCESS, [
            'subject' => 'Saved sender',
            'to'      => ['recipient@example.com'],
            'from'    => 'Store <a@x.test>',
        ]);

        $log = Log::where('subject', 'Saved sender')->first();
        $this->assertSame('Store <a@x.test>', $log->sender);
        $this->assertArrayNotHasKey('from', $log->details);
    }

    public function testBulkInsertPersistsSenderAndStripsFromOutOfDetails(): void
    {
        $this->assertTrue($this->service->bulkInsert([[
            'status' => Log::SUCCESS,
            'data'   => [
                'subject' => 'Bulk sender',
                'to'      => ['recipient@example.com'],
                'from'    => 'Store <a@x.test>',
            ],
        ]]));

        $log = Log::where('subject', 'Bulk sender')->first();
        $this->assertSame('Store <a@x.test>', $log->sender);
        $this->assertArrayNotHasKey('from', $log->details);
    }

    public function testUpdateRefreshesSenderOnResend(): void
    {
        $this->service->save(Log::SUCCESS, [
            'subject' => 'Resend sender',
            'to'      => ['recipient@example.com'],
            'from'    => 'Old <old@x.test>',
        ]);
        $saved = Log::where('subject', 'Resend sender')->first();

        $this->service->update(
            (int) $saved->id,
            Log::SUCCESS,
            ['subject' => 'Resend sender', 'to' => ['recipient@example.com'], 'from' => 'New <new@x.test>']
        );

        $updated = Log::where('id', $saved->id)->first();
        $this->assertSame('New <new@x.test>', $updated->sender);
        $this->assertArrayNotHasKey('from', $updated->details);
    }

    public function testControllerDetailsResponseCarriesTheSender(): void
    {
        $this->service->save(Log::SUCCESS, [
            'subject' => 'Detail sender',
            'to'      => ['recipient@example.com'],
            'from'    => 'Store <a@x.test>',
        ]);
        $saved = Log::where('subject', 'Detail sender')->first();

        $request     = new Request();
        $request->id = (int) $saved->id;
        (new LogController())->details($request);

        $this->assertSame('Store <a@x.test>', $this->responseData()['sender']);
    }

    /**
     * Controllers return the Response singleton; read its data via the static accessor.
     */
    private function responseData(): array
    {
        return (array) Response::getData();
    }
}
