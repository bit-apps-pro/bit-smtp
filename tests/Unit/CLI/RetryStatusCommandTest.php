<?php

namespace BitApps\SMTP\Tests\Unit\CLI;

use BitApps\SMTP\CLI\RetryStatusCommand;
use BitApps\SMTP\Mail\Dispatch\RetryQueue;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use BitApps\SMTP\Tests\Fake\FakeCliReporter;
use Mockery;

/**
 * Covers the retry-status command logic: it prints the queue depth and renders the metadata-only
 * pending rows as a table, with a notice when nothing is pending.
 *
 * @internal
 *
 * @coversNothing
 */
final class RetryStatusCommandTest extends BaseUnitTestCase
{
    public function testPrintsDepthAndRendersPendingRows(): void
    {
        $pending = [
            ['id' => 5, 'attempts' => 1, 'max_attempts' => 3, 'failure_class' => 'transient', 'connection_chain' => 'a,b', 'next_attempt_at' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00', 'locked' => false],
        ];
        $queue = Mockery::mock(RetryQueue::class);
        $queue->shouldReceive('depth')->once()->andReturn(1);
        $queue->shouldReceive('pending')->once()->andReturn($pending);

        $reporter = new FakeCliReporter();
        (new RetryStatusCommand($queue))->run([], [], $reporter);

        $this->assertSame(['Retry queue depth: 1'], $reporter->lines);
        $this->assertCount(1, $reporter->rendered);
        $this->assertSame('table', $reporter->rendered[0]['format']);
        $this->assertSame($pending, $reporter->rendered[0]['items']);
        $this->assertContains('connection_chain', $reporter->rendered[0]['fields']);
    }

    public function testReportsNoPendingRetriesWhenTheQueueIsEmpty(): void
    {
        $queue = Mockery::mock(RetryQueue::class);
        $queue->shouldReceive('depth')->once()->andReturn(0);
        $queue->shouldReceive('pending')->once()->andReturn([]);

        $reporter = new FakeCliReporter();
        (new RetryStatusCommand($queue))->run([], [], $reporter);

        $this->assertSame(['Retry queue depth: 0', 'No pending retries.'], $reporter->lines);
        $this->assertSame([], $reporter->rendered);
    }
}
