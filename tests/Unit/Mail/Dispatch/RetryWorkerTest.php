<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Dispatch;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Mail\Dispatch\FailureCategory;
use BitApps\SMTP\Mail\Dispatch\MailEventLogger;
use BitApps\SMTP\Mail\Dispatch\RetryQueue;
use BitApps\SMTP\Mail\Dispatch\RetryWorker;
use BitApps\SMTP\Mail\Dispatch\WpMailBridge;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * @internal
 *
 * @coversNothing
 */
final class RetryWorkerTest extends BaseUnitTestCase
{
    private const CAP_SECONDS = 21600;

    private const JITTER_RATIO = 0.15;

    protected function setUp(): void
    {
        parent::setUp();
        // RetryWorker's constructor reads retry_backoff via PluginSettings::make(), which always
        // hits get_option() on construction; no preferences blob means schema defaults apply.
        Functions\when('get_option')->justReturn(false);
    }

    #[DataProvider('exponentialAttemptProvider')]
    public function testComputeDelayGrowsExponentiallyAndStaysWithinJitterBounds(int $attempt, int $expectedBase): void
    {
        $delay = RetryWorker::computeDelay($attempt, 'exponential');

        $this->assertWithinJitterBounds($expectedBase, $delay);
    }

    /**
     * @return array<string,array{0:int,1:int}>
     */
    public static function exponentialAttemptProvider(): array
    {
        return [
            'attempt 1 -> base 300s (5m)'    => [1, 300],
            'attempt 2 -> 600s (10m)'        => [2, 600],
            'attempt 3 -> 1200s (20m)'       => [3, 1200],
            'attempt 4 -> 2400s (40m)'       => [4, 2400],
            'attempt 8 -> capped at 21600s'  => [8, self::CAP_SECONDS],
            'attempt 20 -> still capped'     => [20, self::CAP_SECONDS],
        ];
    }

    public function testComputeDelayFixedModeIgnoresTheAttemptNumber(): void
    {
        foreach ([1, 2, 5, 10] as $attempt) {
            $this->assertWithinJitterBounds(300, RetryWorker::computeDelay($attempt, 'fixed'));
        }
    }

    public function testComputeDelayJitterNeverExceedsTheCapOrGoesBelowOneSecond(): void
    {
        for ($i = 0; $i < 25; ++$i) {
            $delay = RetryWorker::computeDelay(50, 'exponential');
            $this->assertLessThanOrEqual(self::CAP_SECONDS, $delay);
            $this->assertGreaterThanOrEqual(1, $delay);
        }
    }

    public function testProcessDeletesOnSuccessAndNeverReschedules(): void
    {
        $queue = Mockery::mock(RetryQueue::class);
        $queue->shouldReceive('claimDue')->once()->with(20)->andReturn([$this->row(['id' => 1])]);
        $queue->shouldReceive('renewClaim')->once()->with(1, 'tok')->andReturn(true);
        $queue->shouldReceive('delete')->once()->with(1);
        $queue->shouldNotReceive('reschedule');

        $bridge = $this->mockBridge();
        $bridge->shouldReceive('dispatchRetry')->once()->andReturn(['succeeded' => true, 'failure_class' => null]);

        (new RetryWorker($queue, $bridge))->process();
    }

    public function testProcessReschedulesARetryableFailureBelowMaxAttempts(): void
    {
        $queue = Mockery::mock(RetryQueue::class);
        $queue->shouldReceive('claimDue')->once()->with(20)->andReturn([$this->row(['id' => 2, 'attempts' => 1, 'max_attempts' => 3])]);
        $queue->shouldReceive('renewClaim')->once()->with(2, 'tok')->andReturn(true);
        $queue->shouldReceive('reschedule')->once()->with(2, 2, FailureCategory::TRANSIENT, Mockery::type('int'));
        $queue->shouldNotReceive('delete');

        $bridge = $this->mockBridge();
        $bridge->shouldReceive('dispatchRetry')->once()->andReturn(['succeeded' => false, 'failure_class' => FailureCategory::TRANSIENT]);

        (new RetryWorker($queue, $bridge))->process();
    }

    public function testProcessDeletesWhenAttemptsAreExhausted(): void
    {
        $queue = Mockery::mock(RetryQueue::class);
        $queue->shouldReceive('claimDue')->once()->with(20)->andReturn([$this->row(['id' => 3, 'attempts' => 2, 'max_attempts' => 3])]);
        $queue->shouldReceive('renewClaim')->once()->with(3, 'tok')->andReturn(true);
        $queue->shouldReceive('delete')->once()->with(3);
        $queue->shouldNotReceive('reschedule');

        $bridge = $this->mockBridge();
        $bridge->shouldReceive('dispatchRetry')->once()->andReturn(['succeeded' => false, 'failure_class' => FailureCategory::TRANSIENT]);

        (new RetryWorker($queue, $bridge))->process();
    }

    public function testProcessDeletesWhenTheNewFailureIsNoLongerRetryable(): void
    {
        $queue = Mockery::mock(RetryQueue::class);
        $queue->shouldReceive('claimDue')->once()->with(20)->andReturn([$this->row(['id' => 4])]);
        $queue->shouldReceive('renewClaim')->once()->with(4, 'tok')->andReturn(true);
        $queue->shouldReceive('delete')->once()->with(4);
        $queue->shouldNotReceive('reschedule');

        $bridge = $this->mockBridge();
        $bridge->shouldReceive('dispatchRetry')->once()->andReturn(['succeeded' => false, 'failure_class' => FailureCategory::AUTH]);

        (new RetryWorker($queue, $bridge))->process();
    }

    public function testProcessSkipsARowWhoseClaimWasLostToAnOverlappingWorker(): void
    {
        $queue = Mockery::mock(RetryQueue::class);
        $queue->shouldReceive('claimDue')->once()->with(20)->andReturn([$this->row(['id' => 9])]);
        // Lost the lock (a concurrent worker reclaimed the stale row): the message must NOT be sent
        // again, and the row is left untouched for its new owner to resolve.
        $queue->shouldReceive('renewClaim')->once()->with(9, 'tok')->andReturn(false);
        $queue->shouldNotReceive('delete');
        $queue->shouldNotReceive('reschedule');

        $bridge = $this->mockBridge();
        $bridge->shouldNotReceive('dispatchRetry');

        (new RetryWorker($queue, $bridge))->process();
    }

    private function assertWithinJitterBounds(int $expectedBase, int $delay): void
    {
        $spread = (int) round($expectedBase * self::JITTER_RATIO);
        $this->assertGreaterThanOrEqual(max(1, $expectedBase - $spread), $delay);
        $this->assertLessThanOrEqual(min(self::CAP_SECONDS, $expectedBase + $spread), $delay);
    }

    /**
     * @param array<string,mixed> $overrides
     *
     * @return array{id:int,log_id:?int,message:MailMessage,mail_data:array,connection_ids:string[],attempts:int,max_attempts:int,claim_token:string}
     */
    private function row(array $overrides): array
    {
        return array_merge([
            'id'             => 1,
            'log_id'         => null,
            'message'        => MailMessage::fromArray(['to' => ['a@example.com'], 'subject' => 'Subj', 'body' => 'Body']),
            'mail_data'      => [],
            'connection_ids' => ['conn_1'],
            'attempts'       => 0,
            'max_attempts'   => 3,
            'claim_token'    => 'tok',
        ], $overrides);
    }

    /**
     * A Mockery mock of the concrete WpMailBridge bypasses its constructor, leaving eventLogger
     * uninitialized; WpMailBridge::__destruct() unconditionally flushes it, so a harmless instance
     * is wired in the same way WpMailBridgeTest's bridgeWithTransport() helper does.
     */
    private function mockBridge(): WpMailBridge
    {
        $bridge   = Mockery::mock(WpMailBridge::class);
        $property = (new ReflectionClass(WpMailBridge::class))->getProperty('eventLogger');
        $property->setAccessible(true);
        $property->setValue($bridge, new MailEventLogger(Mockery::mock(LogService::class)));

        return $bridge;
    }
}
