<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Dispatch;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Mail\Dispatch\MailEventLogger;
use BitApps\SMTP\Mail\Dispatch\SendContext;
use BitApps\SMTP\Mail\Routing\RoutingDecision;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;
use WP_Error;

/**
 * @internal
 *
 * @coversNothing
 */
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
            ->with([['status' => Log::SUCCESS, 'data' => $mailData, 'connection' => null, 'connection_id' => null, 'message_id' => null, 'tracking_id' => null, 'delivery_status' => null, 'delivery_updated_at' => null, 'failure_class' => null]]);

        $this->eventLogger->logMailSuccess($mailData, $this->context);

        $this->assertFalse($this->context->isFailed());
    }

    public function testFailureQueuedAndFlushedImmediatelyWhenNotBatching(): void
    {
        $error = new WP_Error('wp_mail_failed', 'boom', ['phpmailer_exception_code' => 0]);

        $this->logger->shouldReceive('bulkInsert')
            ->once()
            ->with([['status' => Log::ERROR, 'data' => $error, 'connection' => null, 'connection_id' => null, 'message_id' => null, 'tracking_id' => null, 'delivery_status' => null, 'delivery_updated_at' => null, 'failure_class' => null]]);

        $this->eventLogger->logMailFailed($error, $this->context);

        $this->assertTrue($this->context->isFailed());
    }

    public function testFailureQueuedCarriesTheClassifiedFailureCategory(): void
    {
        $error = new WP_Error('wp_mail_failed', 'boom', ['phpmailer_exception_code' => 0]);

        $this->logger->shouldReceive('bulkInsert')
            ->once()
            ->with([['status' => Log::ERROR, 'data' => $error, 'connection' => null, 'connection_id' => null, 'message_id' => null, 'tracking_id' => null, 'delivery_status' => null, 'delivery_updated_at' => null, 'failure_class' => 'transient']]);

        $this->eventLogger->logMailFailed($error, $this->context, null, null, null, null, 'transient');

        $this->assertTrue($this->context->isFailed());
    }

    public function testDebugFailureFromApiProviderDoesNotFatalOnUnloadedPhpMailer(): void
    {
        // Regression: on the pre_wp_mail API-provider path PHPMailer is never loaded, so touching
        // PHPMailer::STOP_CRITICAL in debug mode fataled ("Class not found"), killing the dispatch
        // (and the fallback chain) before any log row was written. The error must queue unchanged.
        $this->context->setDebug(true);
        $error = new WP_Error('wp_mail_failed', 'Unauthorized', ['phpmailer_exception_code' => 401]);

        $this->logger->shouldReceive('bulkInsert')
            ->once()
            ->with([['status' => Log::ERROR, 'data' => $error, 'connection' => 'postmark', 'connection_id' => null, 'message_id' => null, 'tracking_id' => null, 'delivery_status' => null, 'delivery_updated_at' => null, 'failure_class' => null]]);

        $this->eventLogger->logMailFailed($error, $this->context, 'postmark');

        $this->assertTrue($this->context->isFailed());
        $this->assertSame(['Unauthorized'], $error->get_error_messages());
    }

    public function testSuccessQueuedCarriesTheConnectionLabelWhenProvided(): void
    {
        $mailData = ['subject' => 'Hi', 'to' => ['a@example.org']];

        $this->logger->shouldReceive('bulkInsert')
            ->once()
            ->with([['status' => Log::SUCCESS, 'data' => $mailData, 'connection' => 'Primary SMTP', 'connection_id' => null, 'message_id' => null, 'tracking_id' => null, 'delivery_status' => null, 'delivery_updated_at' => null, 'failure_class' => null]]);

        $this->eventLogger->logMailSuccess($mailData, $this->context, 'Primary SMTP');
    }

    public function testFailureQueuedCarriesTheConnectionLabelWhenProvided(): void
    {
        $error = new WP_Error('wp_mail_failed', 'boom', ['phpmailer_exception_code' => 0]);

        $this->logger->shouldReceive('bulkInsert')
            ->once()
            ->with([['status' => Log::ERROR, 'data' => $error, 'connection' => 'brevo', 'connection_id' => null, 'message_id' => null, 'tracking_id' => null, 'delivery_status' => null, 'delivery_updated_at' => null, 'failure_class' => null]]);

        $this->eventLogger->logMailFailed($error, $this->context, 'brevo');
    }

    public function testRetrySuccessUpdatesLogInsteadOfQueueing(): void
    {
        $mailData = ['subject' => 'Hi'];
        $this->context->setRetrying(true)->setRetryLogId(99);

        $this->logger->shouldReceive('update')
            ->once()
            ->with(99, Log::SUCCESS, $mailData, null, 'Primary SMTP', null, null, null, null);
        $this->logger->shouldNotReceive('bulkInsert');

        $this->eventLogger->logMailSuccess($mailData, $this->context, 'Primary SMTP');

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
            ->with(77, Log::ERROR, $data, ['boom'], 'brevo', null, null, null, null, null, null, null, null);
        $this->logger->shouldNotReceive('bulkInsert');

        $this->eventLogger->logMailFailed($error, $this->context, 'brevo');

        $this->assertTrue($this->context->isFailed());
        $this->assertFalse($this->context->isRetrying());
    }

    public function testRetryFailureUpdatesWithTheClassifiedFailureCategory(): void
    {
        $data  = ['phpmailer_exception_code' => 0];
        $error = new WP_Error('wp_mail_failed', 'boom', $data);
        $this->context->setRetrying(true)->setRetryLogId(77);

        $this->logger->shouldReceive('update')
            ->once()
            ->with(77, Log::ERROR, $data, ['boom'], 'brevo', null, null, null, null, null, null, null, 'transient');
        $this->logger->shouldNotReceive('bulkInsert');

        $this->eventLogger->logMailFailed($error, $this->context, 'brevo', null, null, null, 'transient');

        $this->assertTrue($this->context->isFailed());
    }

    public function testManualResendInsertsChildRowCarryingTheParentId(): void
    {
        $mailData = ['subject' => 'Hi', 'to' => ['a@example.org']];
        $this->context->setResendParentId(5);

        $this->logger->shouldReceive('bulkInsert')
            ->once()
            ->with([[
                'status'              => Log::SUCCESS,
                'data'                => $mailData,
                'connection'          => 'Primary SMTP',
                'connection_id'       => null,
                'message_id'          => null,
                'tracking_id'         => null,
                'delivery_status'     => null,
                'delivery_updated_at' => null,
                'failure_class'       => null,
                'resend_parent_id'    => 5,
            ]]);
        $this->logger->shouldNotReceive('update');

        $this->eventLogger->logMailSuccess($mailData, $this->context, 'Primary SMTP');

        // The one-shot parent id is consumed so a later ordinary send is not tagged as its child.
        $this->assertNull($this->context->getResendParentId());
    }

    public function testOrdinaryAndWorkerInsertsOmitTheResendParentId(): void
    {
        $error = new WP_Error('wp_mail_failed', 'boom', ['phpmailer_exception_code' => 0]);
        // The retry worker re-dispatches with isRetrying set but no retry log id, so it INSERTs a
        // fresh row exactly like an ordinary send — and must never carry a resend_parent_id.
        $this->context->setRetrying(true);

        $this->logger->shouldReceive('bulkInsert')
            ->once()
            ->with([['status' => Log::ERROR, 'data' => $error, 'connection' => null, 'connection_id' => null, 'message_id' => null, 'tracking_id' => null, 'delivery_status' => null, 'delivery_updated_at' => null, 'failure_class' => null]]);

        $this->eventLogger->logMailFailed($error, $this->context);

        $this->assertTrue($this->context->isFailed());
    }

    public function testSuccessPersistsRoutingDecisionMetadata(): void
    {
        $mailData = ['subject' => 'Hi', 'to' => ['a@example.org']];
        $this->context->setRoutingDecision(new RoutingDecision('woocommerce', 'conn_primary', 'rule', 3));

        $this->logger->shouldReceive('bulkInsert')
            ->once()
            ->with([[
                'status'              => Log::SUCCESS,
                'data'                => $mailData,
                'connection'          => null,
                'connection_id'       => null,
                'message_id'          => null,
                'tracking_id'         => null,
                'delivery_status'     => null,
                'delivery_updated_at' => null,
                'failure_class'       => null,
                'source_plugin'       => 'woocommerce',
                'routing_type'        => 'rule',
                'routing_rule_index'  => 3,
            ]]);

        $this->eventLogger->logMailSuccess($mailData, $this->context);
    }

    public function testRetrySuccessUpdatesRoutingDecisionMetadata(): void
    {
        $mailData = ['subject' => 'Hi'];
        $this->context
            ->setRetrying(true)
            ->setRetryLogId(99)
            ->setRoutingDecision(new RoutingDecision('woocommerce', 'conn_fallback', 'fallback', null));

        $this->logger->shouldReceive('update')
            ->once()
            ->with(99, Log::SUCCESS, $mailData, null, 'Primary SMTP', null, null, null, null, 'woocommerce', 'fallback', null);

        $this->eventLogger->logMailSuccess($mailData, $this->context, 'Primary SMTP');
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
                    ['status' => Log::SUCCESS, 'data' => ['subject' => 'one'], 'connection' => null, 'connection_id' => null, 'message_id' => null, 'tracking_id' => null, 'delivery_status' => null, 'delivery_updated_at' => null, 'failure_class' => null],
                    ['status' => Log::SUCCESS, 'data' => ['subject' => 'two'], 'connection' => null, 'connection_id' => null, 'message_id' => null, 'tracking_id' => null, 'delivery_status' => null, 'delivery_updated_at' => null, 'failure_class' => null],
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
                ->with([['status' => Log::SUCCESS, 'data' => ['subject' => 'flush-me'], 'connection' => null, 'connection_id' => null, 'message_id' => null, 'tracking_id' => null, 'delivery_status' => null, 'delivery_updated_at' => null, 'failure_class' => null]]);

            $this->eventLogger->logMailSuccess(['subject' => 'flush-me'], $this->context);
        } finally {
            ini_set('memory_limit', $originalLimit);
        }
    }
}
