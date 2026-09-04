<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\CLI\RetryFlushCommand;
use BitApps\SMTP\CLI\RetryStatusCommand;
use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Dispatch\FailureCategory;
use BitApps\SMTP\Mail\Dispatch\RetryQueue;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Tests\Fake\FakeCliReporter;
use BitSmtpRetryQueueMigration;

/**
 * Drives the retry CLI commands against the real retry-queue table: retry:status reports the true
 * depth/pending metadata, and retry:flush actually clears every row and returns the count removed.
 *
 * @internal
 *
 * @coversNothing
 */
final class CliRetryCommandsTest extends IntegrationTestCase
{
    private RetryQueue $queue;

    private string $table;

    protected function setUp(): void
    {
        parent::setUp();
        (new BitSmtpRetryQueueMigration())->up();
        $this->table = $GLOBALS['wpdb']->prefix . Config::VAR_PREFIX . 'mail_retry_queue';
        $this->truncateTables($this->table);
        $this->queue = new RetryQueue();
    }

    public function testStatusReportsDepthAndPendingRows(): void
    {
        $this->enqueue(2);

        $reporter = new FakeCliReporter();
        (new RetryStatusCommand($this->queue))->run([], [], $reporter);

        $this->assertSame(['Retry queue depth: 2'], $reporter->lines);
        $this->assertCount(1, $reporter->rendered);
        $this->assertCount(2, $reporter->rendered[0]['items']);
        // Metadata only: the encrypted payload must never surface in the status output.
        $this->assertArrayNotHasKey('payload', $reporter->rendered[0]['items'][0]);
    }

    public function testStatusReportsAnEmptyQueue(): void
    {
        $reporter = new FakeCliReporter();
        (new RetryStatusCommand($this->queue))->run([], [], $reporter);

        $this->assertSame(['Retry queue depth: 0', 'No pending retries.'], $reporter->lines);
        $this->assertSame([], $reporter->rendered);
    }

    public function testFlushClearsEveryRowAndReportsTheCount(): void
    {
        $this->enqueue(3);
        $this->assertSame(3, $this->queue->depth());

        $reporter = new FakeCliReporter();
        (new RetryFlushCommand($this->queue))->run([], [], $reporter);

        $this->assertSame(['3 queued retries discarded.'], $reporter->success);
        $this->assertSame(0, $this->queue->depth());
        $this->assertSame(0, (int) $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM `{$this->table}`"));
    }

    private function enqueue(int $count): void
    {
        $message = MailMessage::fromArray(['to' => ['a@example.com'], 'subject' => 'S', 'body' => 'B']);
        for ($i = 0; $i < $count; ++$i) {
            $this->queue->enqueue($message, [], ['conn_a'], FailureCategory::TRANSIENT, null, 5, -60);
        }
    }
}
