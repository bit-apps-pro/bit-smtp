<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Controllers\RetryController;
use BitApps\SMTP\Mail\Dispatch\FailureCategory;
use BitApps\SMTP\Mail\Dispatch\RetryQueue;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitSmtpRetryQueueMigration;
use Mockery;

/**
 * Drives RetryController's status/flush against the real retry queue: the panel sees depth and
 * pending metadata (never the encrypted payload), and flush clears every queued row.
 *
 * @internal
 *
 * @coversNothing
 */
final class RetryControllerTest extends IntegrationTestCase
{
    private RetryQueue $queue;

    private string $table;

    protected function setUp(): void
    {
        parent::setUp();
        (new BitSmtpRetryQueueMigration())->up();
        $this->table = $GLOBALS['wpdb']->prefix . Config::VAR_PREFIX . 'mail_retry_queue';
        $GLOBALS['wpdb']->query("TRUNCATE TABLE `{$this->table}`");
        $this->queue = new RetryQueue();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testStatusReturnsDepthAndPendingMetadataWithoutThePayload(): void
    {
        $this->enqueue(FailureCategory::TRANSIENT);
        $this->enqueue(FailureCategory::RATE_LIMITED);

        (new RetryController())->status($this->request());
        $data = (array) Response::getData();

        $this->assertSame(Response::SUCCESS, Response::getStatus());
        $this->assertSame(2, $data['depth']);
        $this->assertCount(2, $data['items']);

        $item = $data['items'][0];
        $this->assertArrayNotHasKey('payload', $item, 'the panel payload must never carry the encrypted message');
        foreach (['id', 'attempts', 'max_attempts', 'failure_class', 'connection_chain', 'next_attempt_at', 'created_at', 'locked'] as $key) {
            $this->assertArrayHasKey($key, $item, "pending item must expose {$key}");
        }
    }

    public function testFlushDiscardsEveryQueuedRow(): void
    {
        $this->enqueue(FailureCategory::TRANSIENT);
        $this->enqueue(FailureCategory::TRANSIENT);
        $this->enqueue(FailureCategory::RATE_LIMITED);

        (new RetryController())->flush($this->request());
        $data = (array) Response::getData();

        $this->assertSame(Response::SUCCESS, Response::getStatus());
        $this->assertSame(3, $data['deleted']);
        $this->assertSame(0, $this->queue->depth());
    }

    private function enqueue(string $failureClass): void
    {
        $message = MailMessage::fromArray(['to' => ['a@example.com'], 'subject' => 'S', 'body' => 'B']);
        $this->queue->enqueue($message, [], ['conn_a'], $failureClass, null, 3, -60);
    }

    private function request(): Request
    {
        return Mockery::mock(Request::class);
    }
}
