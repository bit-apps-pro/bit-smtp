<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Dispatch;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Mail\Dispatch\MailEventLogger;
use BitApps\SMTP\Mail\Dispatch\SendContext;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;
use WP_Error;

class MailEventLoggerTest extends BaseUnitTestCase
{
    private $logger;

    private MailEventLogger $eventLogger;

    private SendContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('__')->returnArg();
        $this->logger      = Mockery::mock(LogService::class);
        $this->eventLogger = new MailEventLogger($this->logger);
        $this->context     = new SendContext();
    }

    public function testSuccessQueuedAndFlushedImmediatelyWhenNotBatching(): void
    {
        $mailData = ['subject' => 'Hi', 'to' => ['a@example.org']];

        $this->logger->shouldReceive('bulkInsert')
            ->once()
            ->with([['status' => Log::SUCCESS, 'data' => $mailData]]);

        $this->eventLogger->logMailSuccess($mailData, $this->context);

        $this->assertFalse($this->context->isFailed());
    }

    public function testFailureQueuedAndFlushedImmediatelyWhenNotBatching(): void
    {
        $error = new WP_Error('wp_mail_failed', 'boom', ['phpmailer_exception_code' => 0]);

        $this->logger->shouldReceive('bulkInsert')
            ->once()
            ->with([['status' => Log::ERROR, 'data' => $error]]);

        $this->eventLogger->logMailFailed($error, $this->context);

        $this->assertTrue($this->context->isFailed());
    }

    public function testRetrySuccessUpdatesLogInsteadOfQueueing(): void
    {
        $mailData = ['subject' => 'Hi'];
        $this->context->setRetrying(true)->setRetryLogId(99);

        $this->logger->shouldReceive('update')
            ->once()
            ->with(99, Log::SUCCESS, $mailData);
        $this->logger->shouldNotReceive('bulkInsert');

        $this->eventLogger->logMailSuccess($mailData, $this->context);

        // Retry flag is consumed after the send.
        $this->assertFalse($this->context->isRetrying());
    }

    public function testRetryFailureUpdatesLogInsteadOfQueueing(): void
    {
        $data  = ['phpmailer_exception_code' => 0];
        $error = new WP_Error('wp_mail_failed', 'boom', $data);
        $this->context->setRetrying(true)->setRetryLogId(77);

        $this->logger->shouldReceive('update')
            ->once()
            ->with(77, Log::ERROR, $data, ['boom']);
        $this->logger->shouldNotReceive('bulkInsert');

        $this->eventLogger->logMailFailed($error, $this->context);

        $this->assertTrue($this->context->isFailed());
        $this->assertFalse($this->context->isRetrying());
    }

    public function testBatchingDefersFlushUntilThresholdThenFlushesAll(): void
    {
        $this->context->setBatch(true);

        $originalLimit = \ini_get('memory_limit');
        // Huge headroom keeps free memory above threshold, so batching buffers without flushing.
        ini_set('memory_limit', '-1');

        try {
            $this->logger->shouldNotReceive('bulkInsert');

            $this->eventLogger->logMailSuccess(['subject' => 'one'], $this->context);
            $this->eventLogger->logMailSuccess(['subject' => 'two'], $this->context);

            // A manual flush persists everything buffered so far in one bulk insert.
            $this->logger->shouldReceive('bulkInsert')
                ->once()
                ->with([
                    ['status' => Log::SUCCESS, 'data' => ['subject' => 'one']],
                    ['status' => Log::SUCCESS, 'data' => ['subject' => 'two']],
                ]);

            $this->eventLogger->flushPendingLogs();
        } finally {
            ini_set('memory_limit', $originalLimit);
        }
    }

    public function testBatchingFlushesWhenLimitIsBelowThreshold(): void
    {
        $this->context->setBatch(true);

        $originalLimit = \ini_get('memory_limit');
        // A limit under the 100MB threshold forces a flush even while batching (self::MEMORY_THRESHOLD > $limit).
        ini_set('memory_limit', '64M');

        try {
            $this->logger->shouldReceive('bulkInsert')
                ->once()
                ->with([['status' => Log::SUCCESS, 'data' => ['subject' => 'flush-me']]]);

            $this->eventLogger->logMailSuccess(['subject' => 'flush-me'], $this->context);
        } finally {
            ini_set('memory_limit', $originalLimit);
        }
    }
}
