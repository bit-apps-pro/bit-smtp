<?php

namespace BitApps\SMTP\Tests\Unit\CLI;

use BitApps\SMTP\CLI\RetryFlushCommand;
use BitApps\SMTP\Mail\Dispatch\RetryQueue;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use BitApps\SMTP\Tests\Fake\FakeCliReporter;
use Mockery;

/**
 * Covers the retry-flush command logic: it clears the queue via RetryQueue::clear() and reports the
 * count removed, distinguishing a DB error (false) from a legitimate zero-row clear.
 *
 * @internal
 *
 * @coversNothing
 */
final class RetryFlushCommandTest extends BaseUnitTestCase
{
    public function testReportsThePluralCountRemoved(): void
    {
        $queue = Mockery::mock(RetryQueue::class);
        $queue->shouldReceive('clear')->once()->andReturn(3);

        $reporter = new FakeCliReporter();
        (new RetryFlushCommand($queue))->run([], [], $reporter);

        $this->assertSame(['3 queued retries discarded.'], $reporter->success);
        $this->assertSame([], $reporter->error);
    }

    public function testUsesTheSingularNounForOneRow(): void
    {
        $queue = Mockery::mock(RetryQueue::class);
        $queue->shouldReceive('clear')->once()->andReturn(1);

        $reporter = new FakeCliReporter();
        (new RetryFlushCommand($queue))->run([], [], $reporter);

        $this->assertSame(['1 queued retry discarded.'], $reporter->success);
    }

    public function testReportsZeroWhenTheQueueWasAlreadyEmpty(): void
    {
        $queue = Mockery::mock(RetryQueue::class);
        $queue->shouldReceive('clear')->once()->andReturn(0);

        $reporter = new FakeCliReporter();
        (new RetryFlushCommand($queue))->run([], [], $reporter);

        $this->assertSame(['0 queued retries discarded.'], $reporter->success);
    }

    public function testReportsAnErrorWhenClearFails(): void
    {
        $queue = Mockery::mock(RetryQueue::class);
        $queue->shouldReceive('clear')->once()->andReturn(false);

        $reporter = new FakeCliReporter();
        (new RetryFlushCommand($queue))->run([], [], $reporter);

        $this->assertSame(['Failed to clear the retry queue.'], $reporter->error);
        $this->assertSame([], $reporter->success);
    }
}
