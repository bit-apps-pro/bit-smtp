<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Dispatch;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;
use BitApps\SMTP\Mail\Dispatch\FailureCategory;
use BitApps\SMTP\Mail\Dispatch\FailureClassifier;
use BitApps\SMTP\Mail\Dispatch\MailEventLogger;
use BitApps\SMTP\Mail\Dispatch\RetryQueue;
use BitApps\SMTP\Mail\Dispatch\SendContext;
use BitApps\SMTP\Mail\Dispatch\TrackingIdStamper;
use BitApps\SMTP\Mail\Dispatch\WpMailBridge;
use BitApps\SMTP\Mail\Health\HealthRecorder;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\MailMessageFactory;
use BitApps\SMTP\Mail\Message\SendResult;
use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotifierInterface;
use BitApps\SMTP\Mail\Notifications\NotificationDispatchGuard;
use BitApps\SMTP\Mail\Providers\ProviderRegistry;
use BitApps\SMTP\Mail\Routing\MailSourceDetector;
use BitApps\SMTP\Mail\Routing\RoutingDecision;
use BitApps\SMTP\Mail\Tracking\TokenSigner;
use BitApps\SMTP\Mail\Tracking\TrackingBodyRewriter;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;
use ReflectionClass;
use ReflectionMethod;
use WP_Error;

/**
 * @internal
 *
 * @coversNothing
 */
class WpMailBridgeTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_generate_uuid4')->justReturn('track-uuid');
        // dispatch()'s retry-enqueue guard reads PluginSettings::make(), which always hits
        // get_option() on construction; no preferences blob means retry_enabled defaults to false,
        // so every pre-existing dispatch() test below is unaffected unless it overrides this stub.
        Functions\when('get_option')->justReturn(false);
        // The native-path log listeners now parse the raw headers via MailMessageFactory, which applies
        // the wp_mail_from/from_name/content_type filters and resolves the default sender host.
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('network_home_url')->justReturn('https://www.example.com');
    }

    public function testSendViaOverridesMessageFromWithTheConnectionsFromEmailAndName(): void
    {
        $spy = new SpyTransport();

        $bridge = $this->bridgeWithTransport($spy);

        $connection = $this->connection([
            'fromEmail' => 'verified@example.com',
            'fromName'  => 'Verified Sender',
        ]);
        $message = $this->message([
            'from'     => 'wordpress@example.org',
            'fromName' => 'WordPress Default',
        ]);

        $this->invokeSendVia($bridge, $connection, $message);

        $this->assertNotNull($spy->received);
        $this->assertSame('verified@example.com', $spy->received->getFrom());
        $this->assertSame('Verified Sender', $spy->received->getFromName());
        // Everything else about the message must pass through untouched.
        $this->assertSame(['to@example.com'], $spy->received->getTo());
        $this->assertSame('Subject', $spy->received->getSubject());
    }

    public function testSendViaLeavesMessageFromUnchangedWhenConnectionFromEmailIsEmpty(): void
    {
        $spy = new SpyTransport();

        $bridge = $this->bridgeWithTransport($spy);

        $connection = $this->connection(['fromEmail' => '', 'fromName' => '']);
        $message    = $this->message([
            'from'     => 'wordpress@example.org',
            'fromName' => 'WordPress Default',
        ]);

        $this->invokeSendVia($bridge, $connection, $message);

        $this->assertNotNull($spy->received);
        $this->assertSame('wordpress@example.org', $spy->received->getFrom());
        $this->assertSame('WordPress Default', $spy->received->getFromName());
    }

    public function testConnectionLabelUsesTheConnectionNameWhenPresent(): void
    {
        $bridge     = $this->bridgeWithTransport(new SpyTransport());
        $connection = $this->connection(['name' => 'Primary SMTP', 'provider' => 'smtp']);

        $this->assertSame('Primary SMTP', $this->invokeConnectionLabel($bridge, $connection));
    }

    public function testConnectionLabelFallsBackToTheProviderKeyWhenNameIsEmpty(): void
    {
        $bridge     = $this->bridgeWithTransport(new SpyTransport());
        $connection = $this->connection(['name' => '', 'provider' => 'brevo']);

        $this->assertSame('brevo', $this->invokeConnectionLabel($bridge, $connection));
    }

    public function testDispatchLogsTheConnectionLabelForEachAttempt(): void
    {
        $spy    = new SpyTransport();
        $bridge = $this->bridgeWithTransport($spy);

        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('bulkInsert')
            ->once()
            ->with(Mockery::on(function (array $logs): bool {
                return $logs[0]['connection'] === 'Primary SMTP';
            }));
        $this->setEventLogger($bridge, $logService);
        $this->setContext($bridge, new SendContext());
        $this->setLoggingEnabled($bridge, true);

        $connection = $this->connection(['name' => 'Primary SMTP']);
        $mailData   = ['subject' => 'Hi', 'to' => ['a@example.org']];

        $succeeded = $this->invokeDispatch($bridge, [$connection], $this->message(), $mailData);

        $this->assertTrue($succeeded);
    }

    public function testAcceptedWithErrorStopsTheFallbackAndReportsTheSendAsFailed(): void
    {
        // The regression this guards: a provider that ACCEPTED the message but reported a
        // partial/soft error (2xx-with-error body) must never trigger a fallback re-send —
        // that would duplicate-deliver to the recipients the first provider already accepted.
        $transport = new ScriptedTransport([
            SendResult::acceptedWithError('Recipient rejected', '200'),
        ]);
        $bridge = $this->dispatchableBridge($transport);

        $succeeded = $this->invokeDispatch($bridge, [
            $this->connection(['id' => 'conn_1']),
            $this->connection(['id' => 'conn_2']),
        ], $this->message(), []);

        $this->assertFalse($succeeded);
        $this->assertSame(1, $transport->callCount, 'the second connection must never be tried');
    }

    public function testNotAcceptedFailureFallsBackToTheNextConnection(): void
    {
        // A genuine non-acceptance (connection refused, 4xx, etc.) is the real fallback case.
        $transport = new ScriptedTransport([
            SendResult::failure('Connection refused'),
            SendResult::success(),
        ]);
        $bridge = $this->dispatchableBridge($transport);

        $succeeded = $this->invokeDispatch($bridge, [
            $this->connection(['id' => 'conn_1']),
            $this->connection(['id' => 'conn_2']),
        ], $this->message(), []);

        $this->assertTrue($succeeded);
        $this->assertSame(2, $transport->callCount, 'the second connection must be tried as fallback');
    }

    public function testFallbackOutcomeIsPersistedAsFallbackRouting(): void
    {
        $transport = new ScriptedTransport([
            SendResult::failure('Primary unavailable'),
            SendResult::success(),
        ]);
        $bridge = $this->bridgeWithTransport($transport);

        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('bulkInsert')
            ->once()
            ->with(Mockery::on(static function (array $logs): bool {
                return $logs[0]['source_plugin']      === 'woocommerce'
                    && $logs[0]['routing_type']       === 'fallback'
                    && $logs[0]['routing_rule_index'] === null
                    && $logs[0]['delivery_status']    === 'accepted';
            }));
        $this->setEventLogger($bridge, $logService);
        $this->setContext($bridge, (new SendContext())->setRoutingDecision(
            new RoutingDecision('woocommerce', 'conn_1', 'default', null)
        ));
        $this->setLoggingEnabled($bridge, true);

        $this->invokeDispatch($bridge, [
            $this->connection(['id' => 'conn_1']),
            $this->connection(['id' => 'conn_2']),
        ], $this->message(), ['subject' => 'Hi', 'to' => ['a@example.org']]);
    }

    public function testFinalFailureNotifiesOnceAfterAllFallbacksFail(): void
    {
        $transport = new ScriptedTransport([
            SendResult::failure('Primary unavailable'),
            SendResult::failure('Fallback unavailable'),
        ]);
        $bridge   = $this->dispatchableBridge($transport);
        $notifier = Mockery::mock(FailureNotifierInterface::class);
        $notifier->shouldReceive('notifyFailure')
            ->once()
            ->with(
                Mockery::on(static fn (WP_Error $error): bool => $error->get_error_messages() === ['Fallback unavailable']),
                Mockery::on(static fn (Connection $connection): bool => $connection->getId() === 'conn_2')
            );
        $notifier->shouldNotReceive('notifySuccess');
        $this->setFailureNotifier($bridge, $notifier);

        $succeeded = $this->invokeDispatch($bridge, [
            $this->connection(['id' => 'conn_1']),
            $this->connection(['id' => 'conn_2']),
        ], $this->message(), []);

        $this->assertFalse($succeeded);
        $this->assertSame(2, $transport->callCount);
    }

    public function testFallbackSuccessResetsFailureNotificationEligibility(): void
    {
        $transport = new ScriptedTransport([
            SendResult::failure('Primary unavailable'),
            SendResult::success(),
        ]);
        $bridge   = $this->dispatchableBridge($transport);
        $notifier = Mockery::mock(FailureNotifierInterface::class);
        $notifier->shouldReceive('notifySuccess')->once();
        $notifier->shouldNotReceive('notifyFailure');
        $this->setFailureNotifier($bridge, $notifier);

        $succeeded = $this->invokeDispatch($bridge, [
            $this->connection(['id' => 'conn_1']),
            $this->connection(['id' => 'conn_2']),
        ], $this->message(), []);

        $this->assertTrue($succeeded);
        $this->assertSame(2, $transport->callCount);
    }

    public function testSuccessDoesNotFallBackToTheNextConnection(): void
    {
        $transport = new ScriptedTransport([SendResult::success()]);
        $bridge    = $this->dispatchableBridge($transport);

        $succeeded = $this->invokeDispatch($bridge, [
            $this->connection(['id' => 'conn_1']),
            $this->connection(['id' => 'conn_2']),
        ], $this->message(), []);

        $this->assertTrue($succeeded);
        $this->assertSame(1, $transport->callCount, 'a full success must never try the next connection');
    }

    public function testPermanentFailureStillFallsBackToTheNextConnection(): void
    {
        // A permanent (content/reputation/policy) rejection is connection-scoped — a different,
        // clean-reputation connection may still deliver — so failover must still try it
        // (FailureCategory::STOPS_FAILOVER is INVALID_RECIPIENT only).
        $transport = new ScriptedTransport([
            SendResult::failure('Message blocked due to policy'),
            SendResult::success(),
        ]);
        $bridge = $this->dispatchableBridge($transport);

        $succeeded = $this->invokeDispatch($bridge, [
            $this->connection(['id' => 'conn_1']),
            $this->connection(['id' => 'conn_2']),
        ], $this->message(), []);

        $this->assertTrue($succeeded);
        $this->assertSame(2, $transport->callCount, 'a permanent failure must still try the next connection');
    }

    public function testAllConnectionsPermanentlyFailingRecordsThePermanentFailureClass(): void
    {
        // When every connection exhausts with a PERMANENT rejection, the final (non-stopping)
        // outcome must still surface as PERMANENT on the log row.
        $transport = new ScriptedTransport([
            SendResult::failure('Message blocked due to policy'),
            SendResult::failure('554 blocked as spam'),
        ]);
        $bridge = $this->bridgeWithTransport($transport);

        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('bulkInsert')
            ->once()
            ->with(Mockery::on(function (array $logs): bool {
                return $logs[0]['failure_class'] === FailureCategory::PERMANENT;
            }));
        $this->setEventLogger($bridge, $logService);
        $this->setContext($bridge, new SendContext());
        $this->setLoggingEnabled($bridge, true);

        $succeeded = $this->invokeDispatch($bridge, [
            $this->connection(['id' => 'conn_1']),
            $this->connection(['id' => 'conn_2']),
        ], $this->message(), []);

        $this->assertFalse($succeeded);
        $this->assertSame(2, $transport->callCount, 'a permanent failure must still try every connection');
    }

    public function testInvalidRecipientFailureStopsTheFallbackChain(): void
    {
        $transport = new ScriptedTransport([
            SendResult::failure('No such user here'),
            SendResult::success(),
        ]);
        $bridge = $this->dispatchableBridge($transport);

        $succeeded = $this->invokeDispatch($bridge, [
            $this->connection(['id' => 'conn_1']),
            $this->connection(['id' => 'conn_2']),
        ], $this->message(), []);

        $this->assertFalse($succeeded);
        $this->assertSame(1, $transport->callCount, 'an invalid-recipient failure must never try the next connection');
    }

    public function testAuthFailureStillFallsBackToTheNextConnection(): void
    {
        // Bad credentials on one connection say nothing about an independent connection, so
        // failover must still try it (FailureCategory::STOPS_FAILOVER deliberately excludes AUTH).
        $transport = new ScriptedTransport([
            SendResult::failure('Invalid username or password'),
            SendResult::success(),
        ]);
        $bridge = $this->dispatchableBridge($transport);

        $succeeded = $this->invokeDispatch($bridge, [
            $this->connection(['id' => 'conn_1']),
            $this->connection(['id' => 'conn_2']),
        ], $this->message(), []);

        $this->assertTrue($succeeded);
        $this->assertSame(2, $transport->callCount, 'an auth failure must still try the next connection');
    }

    public function testAllRetryableFailuresRecordTheFinalFailureClassOnTheLog(): void
    {
        $transport = new ScriptedTransport([
            SendResult::failure('Connection refused'),
            SendResult::failure('Too many requests, please try again'),
        ]);
        $bridge = $this->bridgeWithTransport($transport);

        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('bulkInsert')
            ->once()
            ->with(Mockery::on(function (array $logs): bool {
                return $logs[0]['failure_class'] === FailureCategory::RATE_LIMITED;
            }));
        $this->setEventLogger($bridge, $logService);
        $this->setContext($bridge, new SendContext());
        $this->setLoggingEnabled($bridge, true);

        $succeeded = $this->invokeDispatch($bridge, [
            $this->connection(['id' => 'conn_1']),
            $this->connection(['id' => 'conn_2']),
        ], $this->message(), []);

        $this->assertFalse($succeeded);
        $this->assertSame(2, $transport->callCount, 'a retryable failure must still try the next connection');
    }

    public function testDispatchDoesNotEnqueueRetryWhenPreferenceIsDisabled(): void
    {
        // setUp()'s default get_option stub yields retry_enabled = false (the schema default).
        $transport = new ScriptedTransport([SendResult::failure('Connection refused')]);
        $bridge    = $this->dispatchableBridge($transport);

        $retryQueue = Mockery::mock(RetryQueue::class);
        $retryQueue->shouldNotReceive('enqueue');
        $this->setRetryQueue($bridge, $retryQueue);

        $succeeded = $this->invokeDispatch($bridge, [$this->connection(['id' => 'conn_1'])], $this->message(), []);

        $this->assertFalse($succeeded);
    }

    public function testDispatchDoesNotEnqueueRetryOnWorkerRedispatch(): void
    {
        // retry_enabled is true specifically so this proves the worker-redispatch param blocks the
        // enqueue -- not merely that the preference happens to be off. The param (not
        // SendContext::isRetrying, which logOutcome resets) is the authoritative guard: a worker's
        // own re-dispatch must never enqueue a second, orphaned queue row.
        Functions\when('get_option')->justReturn([
            'retry_enabled'      => true,
            'retry_max_attempts' => 5,
            'retry_backoff'      => 'exponential',
        ]);

        $transport = new ScriptedTransport([SendResult::failure('Connection refused')]);
        $bridge    = $this->dispatchableBridge($transport);

        $retryQueue = Mockery::mock(RetryQueue::class);
        $retryQueue->shouldNotReceive('enqueue');
        $this->setRetryQueue($bridge, $retryQueue);

        $result = $this->invokeDispatchFull($bridge, [$this->connection(['id' => 'conn_1'])], $this->message(), [], true);

        $this->assertFalse($result['succeeded']);
    }

    public function testDispatchEnqueuesRetryWhenEnabledAndFailureIsRetryable(): void
    {
        Functions\when('get_option')->justReturn([
            'retry_enabled'      => true,
            'retry_max_attempts' => 5,
            'retry_backoff'      => 'exponential',
        ]);

        $transport = new ScriptedTransport([SendResult::failure('Connection refused')]);
        $bridge    = $this->dispatchableBridge($transport);

        $retryQueue = Mockery::mock(RetryQueue::class);
        $retryQueue->shouldReceive('enqueue')
            ->once()
            ->with(
                Mockery::type(MailMessage::class),
                [],
                ['conn_1'],
                FailureCategory::TRANSIENT,
                null,
                5,
                Mockery::type('int')
            );
        $this->setRetryQueue($bridge, $retryQueue);

        $outcome = $this->invokeDispatchFull($bridge, [$this->connection(['id' => 'conn_1'])], $this->message(), []);

        $this->assertFalse($outcome['succeeded']);
        $this->assertSame(FailureCategory::TRANSIENT, $outcome['failure_class']);
    }

    public function testSuccessfulSendRecordsNoFailureClass(): void
    {
        $transport = new ScriptedTransport([
            SendResult::failure('Connection refused'),
            SendResult::success(),
        ]);
        $bridge = $this->bridgeWithTransport($transport);

        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('bulkInsert')
            ->once()
            ->with(Mockery::on(function (array $logs): bool {
                return \array_key_exists('failure_class', $logs[0]) && $logs[0]['failure_class'] === null;
            }));
        $this->setEventLogger($bridge, $logService);
        $this->setContext($bridge, new SendContext());
        $this->setLoggingEnabled($bridge, true);

        $succeeded = $this->invokeDispatch($bridge, [
            $this->connection(['id' => 'conn_1']),
            $this->connection(['id' => 'conn_2']),
        ], $this->message(), []);

        $this->assertTrue($succeeded);
    }

    public function testWebhookEnabledSendThreadsMessageIdAndTrackingIdOntoTheLog(): void
    {
        $bridge = $this->bridgeWithTransport(new ScriptedTransport([SendResult::success()->withMessageId('pm-1')]));

        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('bulkInsert')
            ->once()
            ->with(Mockery::on(function (array $logs): bool {
                return $logs[0]['message_id']    === 'pm-1'
                    && $logs[0]['tracking_id']   === 'track-uuid'
                    && $logs[0]['connection_id'] === 'conn_1';
            }));
        $this->setEventLogger($bridge, $logService);
        $this->setContext($bridge, new SendContext());
        $this->setLoggingEnabled($bridge, true);

        // api-kind connection with webhook enabled by default.
        $connection = $this->connection(['name' => 'Postmark']);

        $succeeded = $this->invokeDispatch($bridge, [$connection], $this->message(), ['subject' => 'Hi', 'to' => ['a@example.org']]);

        $this->assertTrue($succeeded);
    }

    public function testWebhookDisabledSendStoresProviderMessageIdWithoutTrackingId(): void
    {
        $bridge = $this->bridgeWithTransport(new ScriptedTransport([SendResult::success()->withMessageId('pm-1')]));

        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('bulkInsert')
            ->once()
            ->with(Mockery::on(function (array $logs): bool {
                return $logs[0]['message_id'] === 'pm-1' && $logs[0]['tracking_id'] === null;
            }));
        $this->setEventLogger($bridge, $logService);
        $this->setContext($bridge, new SendContext());
        $this->setLoggingEnabled($bridge, true);

        $connection = $this->connection(['name' => 'Postmark', 'settings' => ['webhook_enabled' => false]]);

        $this->invokeDispatch($bridge, [$connection], $this->message(), ['subject' => 'Hi', 'to' => ['a@example.org']]);
    }

    public function testAcceptedSendNeverPromotesAProviderHandoffToDelivered(): void
    {
        $bridge = $this->bridgeWithTransport(new ScriptedTransport([SendResult::success()]));

        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('bulkInsert')
            ->once()
            ->with(Mockery::on(function (array $logs): bool {
                return $logs[0]['delivery_status'] === 'accepted'
                    && $logs[0]['delivery_updated_at'] !== null;
            }));
        $this->setEventLogger($bridge, $logService);
        $this->setContext($bridge, new SendContext());
        $this->setLoggingEnabled($bridge, true);

        $succeeded = $this->invokeDispatch($bridge, [$this->connection()], $this->message(), ['subject' => 'Hi', 'to' => ['a@example.org']]);

        $this->assertTrue($succeeded);
    }

    public function testSmtpAcceptedSendDoesNotEnterAVerifiedDeliveryOutcome(): void
    {
        // SMTP has no provider-verified delivery callback. A successful hand-off is accepted, not
        // delivered; delivery analytics must therefore exclude it from the verified denominator.
        $bridge = $this->bridgeWithTransport(new ScriptedTransport([SendResult::success()]));

        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('bulkInsert')
            ->once()
            ->with(Mockery::on(function (array $logs): bool {
                return $logs[0]['delivery_status']     === 'accepted'
                    && $logs[0]['delivery_updated_at'] !== null;
            }));
        $this->setEventLogger($bridge, $logService);
        $this->setContext($bridge, new SendContext());
        $this->setLoggingEnabled($bridge, true);

        $this->invokeDispatch($bridge, [$this->connection()], $this->message(), ['subject' => 'Hi', 'to' => ['a@example.org']]);
    }

    public function testWebhookBackedProviderIsStampedAcceptedAtSendTimeAsAFloor(): void
    {
        // A provider with an inbound adapter and the webhook enabled still gets stamped accepted at
        // hand-off time: a non-public install may never receive the webhook, so a floor the rollup can
        // later overwrite is stamped instead of leaving the row permanently null.
        $bridge = $this->bridgeWithTransport(new ScriptedTransport([SendResult::success()]), 'postmark');

        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('bulkInsert')
            ->once()
            ->with(Mockery::on(function (array $logs): bool {
                return $logs[0]['delivery_status']     === 'accepted'
                    && $logs[0]['delivery_updated_at'] !== null;
            }));
        $this->setEventLogger($bridge, $logService);
        $this->setContext($bridge, new SendContext());
        $this->setLoggingEnabled($bridge, true);

        $this->invokeDispatch($bridge, [$this->connection(['provider' => 'postmark'])], $this->message(), ['subject' => 'Hi', 'to' => ['a@example.org']]);
    }

    public function testWebhookDisabledApiAcceptedSendDoesNotEnterAVerifiedDeliveryOutcome(): void
    {
        // The adapter exists but this connection disabled callbacks. The API hand-off is still only
        // accepted; no terminal delivery value may be persisted until a verified callback arrives.
        $bridge = $this->bridgeWithTransport(new ScriptedTransport([SendResult::success()]), 'postmark');

        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('bulkInsert')
            ->once()
            ->with(Mockery::on(function (array $logs): bool {
                return $logs[0]['delivery_status'] === 'accepted';
            }));
        $this->setEventLogger($bridge, $logService);
        $this->setContext($bridge, new SendContext());
        $this->setLoggingEnabled($bridge, true);

        $this->invokeDispatch(
            $bridge,
            [$this->connection(['provider' => 'postmark', 'settings' => ['webhook_enabled' => false]])],
            $this->message(),
            ['subject' => 'Hi', 'to' => ['a@example.org']]
        );
    }

    public function testAcceptedWithErrorNeverStampsDeliveryStatus(): void
    {
        // An accepted-but-partial send (2xx carrying a per-message error) is a failure row, so a
        // provider's send-accept delivery status must not be stamped onto it.
        $bridge = $this->bridgeWithTransport(new ScriptedTransport([SendResult::acceptedWithError('Recipient rejected', '200')]));

        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('bulkInsert')
            ->once()
            ->with(Mockery::on(function (array $logs): bool {
                return $logs[0]['delivery_status'] === null;
            }));
        $this->setEventLogger($bridge, $logService);
        $this->setContext($bridge, new SendContext());
        $this->setLoggingEnabled($bridge, true);

        $succeeded = $this->invokeDispatch($bridge, [$this->connection()], $this->message(), ['subject' => 'Hi', 'to' => ['a@example.org']]);

        $this->assertFalse($succeeded);
    }

    public function testNativeMailSucceededLogsWithoutAConnection(): void
    {
        $bridge = $this->bridgeWithTransport(new SpyTransport());

        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('bulkInsert')
            ->once()
            ->with(Mockery::on(function (array $logs): bool {
                return \array_key_exists('connection', $logs[0])
                    && $logs[0]['connection']          === null
                    && $logs[0]['delivery_status']     === null
                    && $logs[0]['delivery_updated_at'] === null;
            }));
        $this->setEventLogger($bridge, $logService);
        $this->setContext($bridge, new SendContext());
        $this->setLoggingEnabled($bridge, true);

        $bridge->onNativeMailSucceeded(['subject' => 'Hi', 'to' => ['a@example.org']]);
    }

    public function testNativeMailSuccessCapturesSourceOnceWithNativeRoutingType(): void
    {
        $bridge   = $this->bridgeWithTransport(new SpyTransport());
        $detector = Mockery::mock(MailSourceDetector::class);
        $detector->shouldReceive('detect')->once()->andReturn('woocommerce');

        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('bulkInsert')
            ->once()
            ->with(Mockery::on(static function (array $logs): bool {
                return $logs[0]['source_plugin']      === 'woocommerce'
                    && $logs[0]['routing_type']       === 'native'
                    && $logs[0]['routing_rule_index'] === null;
            }));
        $this->setEventLogger($bridge, $logService);
        $this->setSourceDetector($bridge, $detector);
        $this->setContext($bridge, new SendContext());
        $this->setLoggingEnabled($bridge, true);

        $bridge->onNativeMailSucceeded(['subject' => 'Hi', 'to' => ['a@example.org']]);
    }

    public function testNativeMailSuccessLogsTheSenderAndCcBccParsedFromHeaders(): void
    {
        $bridge = $this->bridgeWithTransport(new SpyTransport());

        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('bulkInsert')
            ->once()
            ->with(Mockery::on(static function (array $logs): bool {
                $data = $logs[0]['data'];

                return $data['from'] === 'Sender <sender@example.org>'
                    && $data['cc']   === ['cc@example.org']
                    && $data['bcc']  === ['bcc@example.org'];
            }));
        $this->setEventLogger($bridge, $logService);
        $this->setContext($bridge, new SendContext());
        $this->setLoggingEnabled($bridge, true);

        $bridge->onNativeMailSucceeded([
            'to'      => ['a@example.org'],
            'subject' => 'Hi',
            'message' => 'Body',
            'headers' => [
                'From: Sender <sender@example.org>',
                'Cc: cc@example.org',
                'Bcc: bcc@example.org',
            ],
        ]);
    }

    public function testNativeMailSuccessWithoutAFromHeaderStillLogsANonEmptySender(): void
    {
        $bridge = $this->bridgeWithTransport(new SpyTransport());

        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('bulkInsert')
            ->once()
            ->with(Mockery::on(static function (array $logs): bool {
                return $logs[0]['data']['from'] !== '';
            }));
        $this->setEventLogger($bridge, $logService);
        $this->setContext($bridge, new SendContext());
        $this->setLoggingEnabled($bridge, true);

        $bridge->onNativeMailSucceeded(['to' => ['a@example.org'], 'subject' => 'Hi', 'message' => 'Body']);
    }

    public function testNativeMailWithLoggingDisabledKeepsLegacyNoLoggingBehavior(): void
    {
        $bridge   = $this->bridgeWithTransport(new SpyTransport());
        $detector = Mockery::mock(MailSourceDetector::class);
        $detector->shouldNotReceive('detect');

        $logService = Mockery::mock(LogService::class);
        $logService->shouldNotReceive('bulkInsert');
        $this->setEventLogger($bridge, $logService);
        $this->setSourceDetector($bridge, $detector);
        $this->setContext($bridge, new SendContext());
        $this->setLoggingEnabled($bridge, false);

        $bridge->onNativeMailSucceeded(['subject' => 'Hi', 'to' => ['a@example.org']]);
    }

    public function testNativeMailFailureIsForwardedToTheNotifier(): void
    {
        $bridge   = $this->bridgeWithTransport(new SpyTransport());
        $notifier = Mockery::mock(FailureNotifierInterface::class);
        $error    = new WP_Error('wp_mail_failed', 'Native mail failed');
        $notifier->shouldReceive('notifyFailure')->once()->with($error);
        $notifier->shouldNotReceive('notifySuccess');
        $this->setFailureNotifier($bridge, $notifier);

        $bridge->onNativeMailFailed($error);
    }

    public function testNotificationEmailBypassesDispatchAndNativeOutcomeHandling(): void
    {
        $bridge   = $this->bridgeWithTransport(new SpyTransport());
        $notifier = Mockery::mock(FailureNotifierInterface::class);
        $notifier->shouldNotReceive('notifyFailure');
        $notifier->shouldNotReceive('notifySuccess');
        $this->setFailureNotifier($bridge, $notifier);

        NotificationDispatchGuard::run(static function () use ($bridge): void {
            $thisResult = $bridge->onPreWpMail(null, [
                'to'      => ['ops@example.org'],
                'subject' => 'Failure notification',
                'message' => 'Body',
            ]);
            self::assertNull($thisResult);

            $bridge->onNativeMailSucceeded([]);
            $bridge->onNativeMailFailed(new WP_Error('wp_mail_failed', 'Notification mail failed'));
        });
    }

    public function testDispatchRecordsHealthForTheWinnerAndEachFailedAttemptWhenEnabled(): void
    {
        $transport = new ScriptedTransport([
            SendResult::failure('Connection refused'),
            SendResult::success(),
        ]);
        $bridge = $this->dispatchableBridge($transport);
        $this->setHealthEnabled($bridge, true);

        $recorder = Mockery::mock(HealthRecorder::class);
        $recorder->shouldReceive('record')
            ->once()
            ->with(Mockery::on(static fn (Connection $c): bool => $c->getId() === 'conn_1'), FailureCategory::TRANSIENT)
            ->andReturnNull();
        $recorder->shouldReceive('record')
            ->once()
            ->with(Mockery::on(static fn (Connection $c): bool => $c->getId() === 'conn_2'), FailureCategory::OK)
            ->andReturnNull();
        // Alerts are flushed once after the failover loop, not inside it.
        $recorder->shouldReceive('notify')->once()->with(Mockery::type('array'));
        $this->setHealthRecorder($bridge, $recorder);

        $succeeded = $this->invokeDispatch($bridge, [
            $this->connection(['id' => 'conn_1']),
            $this->connection(['id' => 'conn_2']),
        ], $this->message(), []);

        $this->assertTrue($succeeded);
        $this->assertSame(2, $transport->callCount);
    }

    public function testDispatchRecordsNoHealthWhenTheFeatureIsDisabled(): void
    {
        // health_check_enabled defaults to false on the bridge, so the recorder must never be touched.
        $transport = new ScriptedTransport([
            SendResult::failure('Connection refused'),
            SendResult::success(),
        ]);
        $bridge = $this->dispatchableBridge($transport);

        $recorder = Mockery::mock(HealthRecorder::class);
        $recorder->shouldNotReceive('record');
        $this->setHealthRecorder($bridge, $recorder);

        $succeeded = $this->invokeDispatch($bridge, [
            $this->connection(['id' => 'conn_1']),
            $this->connection(['id' => 'conn_2']),
        ], $this->message(), []);

        $this->assertTrue($succeeded);
    }

    public function testWorkerRedispatchStillRecordsHealth(): void
    {
        // dispatchRetry() re-enters dispatch() with $isWorkerRedispatch = true; the health signal must
        // still be recorded on that path (it feeds the same per-attempt loop).
        $bridge = $this->dispatchableBridge(new ScriptedTransport([SendResult::success()]));
        $this->setHealthEnabled($bridge, true);

        $recorder = Mockery::mock(HealthRecorder::class);
        $recorder->shouldReceive('record')
            ->once()
            ->with(Mockery::on(static fn (Connection $c): bool => $c->getId() === 'conn_1'), FailureCategory::OK)
            ->andReturnNull();
        $recorder->shouldReceive('notify')->once()->with(Mockery::type('array'));
        $this->setHealthRecorder($bridge, $recorder);

        $outcome = $this->invokeDispatchFull($bridge, [$this->connection(['id' => 'conn_1'])], $this->message(), [], true);

        $this->assertTrue($outcome['succeeded']);
    }

    public function testTrackingDisabledSendsTheHtmlBodyByteIdenticalAndKeepsTrackingIdBehavior(): void
    {
        // The OFF invariant: trackingEnabled defaults false, so the body must reach the transport
        // byte-identical and the webhook tracking_id must be minted exactly as it is today.
        $spy    = new SpyTransport();
        $bridge = $this->bridgeWithTransport($spy);

        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('bulkInsert')
            ->once()
            ->with(Mockery::on(static function (array $logs): bool {
                return $logs[0]['tracking_id'] === 'track-uuid';
            }));
        $this->setEventLogger($bridge, $logService);
        $this->setContext($bridge, new SendContext());
        $this->setLoggingEnabled($bridge, true);

        $body       = '<html><body><a href="https://example.com/x">x</a></body></html>';
        $connection = $this->connection(['name' => 'Postmark']); // api-kind, webhook enabled by default
        $message    = $this->message(['body' => $body, 'contentType' => 'text/html']);

        $succeeded = $this->invokeDispatch($bridge, [$connection], $message, ['subject' => 'Hi', 'to' => ['a@example.org']]);

        $this->assertTrue($succeeded);
        $this->assertSame($body, $spy->received->getBody(), 'tracking off must send the HTML body byte-identical');
        $this->assertStringNotContainsString('track/open', $spy->received->getBody());
    }

    public function testTrackingEnabledHtmlSendInjectsPixelRewritesLinksAndStampsTheToken(): void
    {
        Functions\when('wp_generate_uuid4')->justReturn('inject-token');
        Functions\when('wp_salt')->justReturn('unit-test-tracking-salt');
        Functions\when('home_url')->alias(static fn ($path = '') => 'https://site.test/' . ltrim((string) $path, '/'));

        $spy    = new SpyTransport();
        $bridge = $this->bridgeWithTransport($spy);
        $this->setTrackingEnabled($bridge, true);

        $capturedTrackingId = null;
        $logService         = Mockery::mock(LogService::class);
        $logService->shouldReceive('bulkInsert')
            ->once()
            ->with(Mockery::on(static function (array $logs) use (&$capturedTrackingId): bool {
                $capturedTrackingId = $logs[0]['tracking_id'] ?? null;

                return true;
            }));
        $this->setEventLogger($bridge, $logService);
        $this->setContext($bridge, new SendContext());
        $this->setLoggingEnabled($bridge, true);

        // Webhook disabled so the log's tracking_id can only be the injected token, never the stamper.
        $connection = $this->connection(['name' => 'Postmark', 'settings' => ['webhook_enabled' => false]]);
        $message    = $this->message([
            'body'        => '<html><body><p>Visit <a href="https://example.com/order/42">here</a>.</p></body></html>',
            'contentType' => 'text/html',
        ]);

        $succeeded = $this->invokeDispatch($bridge, [$connection], $message, ['subject' => 'Hi', 'to' => ['a@example.org']]);

        $this->assertTrue($succeeded);

        $sentBody = $spy->received->getBody();
        $this->assertStringContainsString('<img src="https://site.test/bit-smtp/track/open/', $sentBody);
        $this->assertStringContainsString('width="1" height="1"', $sentBody);
        $this->assertStringContainsString('href="https://site.test/bit-smtp/track/click/', $sentBody);
        $this->assertStringNotContainsString('href="https://example.com/order/42"', $sentBody);

        // The log's tracking_id is the injected token, and both embedded tokens verify back to it.
        $this->assertSame('inject-token', $capturedTrackingId);

        preg_match('#track/open/([A-Za-z0-9\-_]+\.[a-f0-9]{64})#', $sentBody, $open);
        $this->assertSame(['t' => 'inject-token'], TokenSigner::verify($open[1]));

        preg_match('#track/click/([A-Za-z0-9\-_]+\.[a-f0-9]{64})#', $sentBody, $click);
        $this->assertSame(['t' => 'inject-token', 'u' => 'https://example.com/order/42'], TokenSigner::verify($click[1]));
    }

    public function testTrackingEnabledPlainTextSendIsNotRewritten(): void
    {
        $spy    = new SpyTransport();
        $bridge = $this->bridgeWithTransport($spy);
        $this->setTrackingEnabled($bridge, true);

        $logService = Mockery::mock(LogService::class)->shouldIgnoreMissing();
        $this->setEventLogger($bridge, $logService);
        $this->setContext($bridge, new SendContext());
        $this->setLoggingEnabled($bridge, true);

        $body       = 'Plain body linking to https://example.com/x';
        $connection = $this->connection(['settings' => ['webhook_enabled' => false]]);
        $message    = $this->message(['body' => $body, 'contentType' => 'text/plain']);

        $this->invokeDispatch($bridge, [$connection], $message, ['subject' => 'Hi', 'to' => ['a@example.org']]);

        $this->assertSame($body, $spy->received->getBody(), 'a non-HTML body must never be rewritten');
    }

    public function testTrackingEnabledButLoggingDisabledDoesNotRewrite(): void
    {
        // Tracking requires a persisted log row to attribute a hit to; with logging off it is a no-op.
        $spy    = new SpyTransport();
        $bridge = $this->bridgeWithTransport($spy);
        $this->setTrackingEnabled($bridge, true);
        $this->setContext($bridge, new SendContext());
        $this->setLoggingEnabled($bridge, false);

        $body       = '<html><body><a href="https://example.com/x">x</a></body></html>';
        $connection = $this->connection(['settings' => ['webhook_enabled' => false]]);
        $message    = $this->message(['body' => $body, 'contentType' => 'text/html']);

        $this->invokeDispatch($bridge, [$connection], $message, ['subject' => 'Hi', 'to' => ['a@example.org']]);

        $this->assertSame($body, $spy->received->getBody(), 'logging off must block tracking injection');
    }

    public function testRetryEnqueuesTheOriginalUninjectedMessage(): void
    {
        // A retry must re-run the gate on the pristine message, never a body with a baked-in pixel.
        Functions\when('wp_generate_uuid4')->justReturn('inject-token');
        Functions\when('wp_salt')->justReturn('unit-test-tracking-salt');
        Functions\when('home_url')->alias(static fn ($path = '') => 'https://site.test/' . ltrim((string) $path, '/'));
        Functions\when('get_option')->justReturn([
            'retry_enabled'      => true,
            'retry_max_attempts' => 5,
            'retry_backoff'      => 'exponential',
        ]);

        $transport = new ScriptedTransport([SendResult::failure('Connection refused')]);
        $bridge    = $this->dispatchableBridge($transport);
        $this->setTrackingEnabled($bridge, true);
        $this->setLoggingEnabled($bridge, true);
        $this->setEventLogger($bridge, Mockery::mock(LogService::class)->shouldIgnoreMissing());

        $originalBody = '<html><body><a href="https://example.com/x">x</a></body></html>';
        $message      = $this->message(['body' => $originalBody, 'contentType' => 'text/html']);

        $retryQueue = Mockery::mock(RetryQueue::class);
        $retryQueue->shouldReceive('enqueue')
            ->once()
            ->with(
                Mockery::on(static function (MailMessage $enqueued) use ($originalBody): bool {
                    return $enqueued->getBody() === $originalBody;
                }),
                Mockery::any(),
                Mockery::any(),
                Mockery::any(),
                Mockery::any(),
                Mockery::any(),
                Mockery::any()
            );
        $this->setRetryQueue($bridge, $retryQueue);

        $outcome = $this->invokeDispatchFull(
            $bridge,
            [$this->connection(['id' => 'conn_1', 'settings' => ['webhook_enabled' => false]])],
            $message,
            []
        );

        $this->assertFalse($outcome['succeeded']);
    }

    private function invokeConnectionLabel(WpMailBridge $bridge, Connection $connection): string
    {
        $method = new ReflectionMethod(WpMailBridge::class, 'connectionLabel');
        $method->setAccessible(true);

        return $method->invoke($bridge, $connection);
    }

    /**
     * dispatch() now returns array{succeeded: bool, failure_class: ?string}; every pre-existing
     * caller here only ever cared about the bool, so that shape is unwrapped in one place rather
     * than touching each call site. testDispatchEnqueuesRetryWhenEnabledAndFailureIsRetryable below
     * invokes dispatch() directly (via invokeDispatchFull()) when it needs the failure_class too.
     *
     * @param Connection[] $connections
     */
    private function invokeDispatch(WpMailBridge $bridge, array $connections, MailMessage $message, array $mailData): bool
    {
        return $this->invokeDispatchFull($bridge, $connections, $message, $mailData)['succeeded'];
    }

    /**
     * @param Connection[] $connections
     *
     * @return array{succeeded: bool, failure_class: ?string}
     */
    private function invokeDispatchFull(WpMailBridge $bridge, array $connections, MailMessage $message, array $mailData, bool $isWorkerRedispatch = false): array
    {
        $method = new ReflectionMethod(WpMailBridge::class, 'dispatch');
        $method->setAccessible(true);

        return $method->invoke($bridge, $connections, $message, $mailData, $isWorkerRedispatch);
    }

    private function setEventLogger(WpMailBridge $bridge, LogService $logService): void
    {
        $property = (new ReflectionClass(WpMailBridge::class))->getProperty('eventLogger');
        $property->setAccessible(true);
        $property->setValue($bridge, new MailEventLogger($logService));
    }

    private function setContext(WpMailBridge $bridge, SendContext $context): void
    {
        $property = (new ReflectionClass(WpMailBridge::class))->getProperty('context');
        $property->setAccessible(true);
        $property->setValue($bridge, $context);
    }

    private function setLoggingEnabled(WpMailBridge $bridge, bool $enabled): void
    {
        $property = (new ReflectionClass(WpMailBridge::class))->getProperty('loggingEnabled');
        $property->setAccessible(true);
        $property->setValue($bridge, $enabled);
    }

    private function setSourceDetector(WpMailBridge $bridge, MailSourceDetector $detector): void
    {
        $property = (new ReflectionClass(WpMailBridge::class))->getProperty('sourceDetector');
        $property->setAccessible(true);
        $property->setValue($bridge, $detector);
    }

    private function setFailureNotifier(WpMailBridge $bridge, FailureNotifierInterface $notifier): void
    {
        $property = (new ReflectionClass(WpMailBridge::class))->getProperty('failureNotifier');
        $property->setAccessible(true);
        $property->setValue($bridge, $notifier);
    }

    private function setRetryQueue(WpMailBridge $bridge, RetryQueue $retryQueue): void
    {
        $property = (new ReflectionClass(WpMailBridge::class))->getProperty('retryQueue');
        $property->setAccessible(true);
        $property->setValue($bridge, $retryQueue);
    }

    private function setHealthEnabled(WpMailBridge $bridge, bool $enabled): void
    {
        $property = (new ReflectionClass(WpMailBridge::class))->getProperty('healthEnabled');
        $property->setAccessible(true);
        $property->setValue($bridge, $enabled);
    }

    private function setTrackingEnabled(WpMailBridge $bridge, bool $enabled): void
    {
        $property = (new ReflectionClass(WpMailBridge::class))->getProperty('trackingEnabled');
        $property->setAccessible(true);
        $property->setValue($bridge, $enabled);
    }

    private function setHealthRecorder(WpMailBridge $bridge, HealthRecorder $recorder): void
    {
        $property = (new ReflectionClass(WpMailBridge::class))->getProperty('healthRecorder');
        $property->setAccessible(true);
        $property->setValue($bridge, $recorder);
    }

    /**
     * A bridge wired to also run dispatch() (not just sendVia()): dispatch touches $context and
     * $loggingEnabled, which bridgeWithTransport() leaves uninitialized.
     */
    private function dispatchableBridge(TransportInterface $transport): WpMailBridge
    {
        $bridge = $this->bridgeWithTransport($transport);
        $this->setContext($bridge, new SendContext());
        $this->setLoggingEnabled($bridge, false);

        return $bridge;
    }

    private function bridgeWithTransport(TransportInterface $transport, string $providerKey = 'fake'): WpMailBridge
    {
        $registry = new ProviderRegistry();
        $registry->register(new FakeProvider($transport, $providerKey));

        $refClass = new ReflectionClass(WpMailBridge::class);
        $bridge   = $refClass->newInstanceWithoutConstructor();

        $registryProperty = $refClass->getProperty('registry');
        $registryProperty->setAccessible(true);
        $registryProperty->setValue($bridge, $registry);

        // sendVia() never touches eventLogger, but __destruct() unconditionally flushes it; give it a
        // harmless instance so bypassing the real constructor doesn't blow up on teardown.
        $eventLoggerProperty = $refClass->getProperty('eventLogger');
        $eventLoggerProperty->setAccessible(true);
        $eventLoggerProperty->setValue($bridge, new MailEventLogger(Mockery::mock(LogService::class)));

        $classifierProperty = $refClass->getProperty('classifier');
        $classifierProperty->setAccessible(true);
        $classifierProperty->setValue($bridge, new FailureClassifier());

        $retryQueueProperty = $refClass->getProperty('retryQueue');
        $retryQueueProperty->setAccessible(true);
        $retryQueueProperty->setValue($bridge, new RetryQueue());

        $stamperProperty = $refClass->getProperty('stamper');
        $stamperProperty->setAccessible(true);
        $stamperProperty->setValue($bridge, new TrackingIdStamper());

        // dispatch() reads trackingBodyRewriter only when tracking is enabled, but the property is
        // non-nullable, so give the constructor-bypassed instance a real one to reflect production.
        $trackingRewriterProperty = $refClass->getProperty('trackingBodyRewriter');
        $trackingRewriterProperty->setAccessible(true);
        $trackingRewriterProperty->setValue($bridge, new TrackingBodyRewriter());

        $sourceDetectorProperty = $refClass->getProperty('sourceDetector');
        $sourceDetectorProperty->setAccessible(true);
        $sourceDetectorProperty->setValue($bridge, new MailSourceDetector());

        // The native-path listeners enrich the log with the sender/cc/bcc parsed from the raw headers.
        $messageFactoryProperty = $refClass->getProperty('messageFactory');
        $messageFactoryProperty->setAccessible(true);
        $messageFactoryProperty->setValue($bridge, new MailMessageFactory());

        return $bridge;
    }

    private function invokeSendVia(WpMailBridge $bridge, Connection $connection, MailMessage $message): SendResult
    {
        $resolve = new ReflectionMethod(WpMailBridge::class, 'resolveProvider');
        $resolve->setAccessible(true);
        $provider = $resolve->invoke($bridge, $connection);

        $method = new ReflectionMethod(WpMailBridge::class, 'sendVia');
        $method->setAccessible(true);

        return $method->invoke($bridge, $provider, $connection, $message, [], null);
    }

    private function connection(array $overrides = []): Connection
    {
        return Connection::fromArray(array_merge([
            'id' => 'conn_1', 'provider' => 'fake', 'kind' => 'api',
        ], $overrides));
    }

    private function message(array $overrides = []): MailMessage
    {
        return MailMessage::fromArray(array_merge([
            'to' => ['to@example.com'], 'subject' => 'Subject', 'body' => 'Body',
        ], $overrides));
    }
}

/**
 * Captures the message a transport received so the test can assert on the applied From/From Name.
 */
final class SpyTransport implements TransportInterface
{
    public ?MailMessage $received = null;

    public function send(MailMessage $message, Connection $connection): SendResult
    {
        $this->received = $message;

        return SendResult::success();
    }
}

/**
 * Returns the queued SendResult for each successive send() call, so a test can assert both the
 * dispatch outcome and how many connections were actually attempted.
 */
final class ScriptedTransport implements TransportInterface
{
    public int $callCount = 0;

    /**
     * @var SendResult[]
     */
    private array $results;

    /**
     * @param SendResult[] $results
     */
    public function __construct(array $results)
    {
        $this->results = $results;
    }

    public function send(MailMessage $message, Connection $connection): SendResult
    {
        return $this->results[$this->callCount++];
    }
}

final class FakeProvider implements ProviderInterface
{
    private TransportInterface $transport;

    private string $providerKey;

    public function __construct(TransportInterface $transport, string $providerKey = 'fake')
    {
        $this->transport   = $transport;
        $this->providerKey = $providerKey;
    }

    public function key(): string
    {
        return $this->providerKey;
    }

    public function label(): string
    {
        return 'Fake';
    }

    public function kind(): string
    {
        return 'api';
    }

    public function fields(): array
    {
        return [];
    }

    public function defaults(): array
    {
        return [];
    }

    public function validator(): ValidatorInterface
    {
        return new class() implements ValidatorInterface {
            public function validate(array $settings, array $credentials): array
            {
                return [];
            }
        };
    }

    public function transport(): TransportInterface
    {
        return $this->transport;
    }

    public function authConfig(): array
    {
        return [];
    }

    public function tracking(): array
    {
        return ['channel' => 'metadata', 'key' => 'bit_tracking_id'];
    }
}
