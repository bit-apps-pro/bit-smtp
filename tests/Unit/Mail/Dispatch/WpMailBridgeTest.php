<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Dispatch;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;
use BitApps\SMTP\Mail\Dispatch\MailEventLogger;
use BitApps\SMTP\Mail\Dispatch\SendContext;
use BitApps\SMTP\Mail\Dispatch\TrackingIdStamper;
use BitApps\SMTP\Mail\Dispatch\WpMailBridge;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\SendResult;
use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotifierInterface;
use BitApps\SMTP\Mail\Notifications\NotificationDispatchGuard;
use BitApps\SMTP\Mail\Providers\ProviderRegistry;
use BitApps\SMTP\Mail\Routing\MailSourceDetector;
use BitApps\SMTP\Mail\Routing\RoutingDecision;
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
                    && $logs[0]['routing_rule_index'] === null;
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

    public function testAcceptDeliveryProviderStampsDeliveryStatusOnSuccess(): void
    {
        $bridge = $this->bridgeWithTransport(new ScriptedTransport([SendResult::success()]), 'delivered');

        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('bulkInsert')
            ->once()
            ->with(Mockery::on(function (array $logs): bool {
                return $logs[0]['delivery_status'] === 'delivered'
                    && $logs[0]['delivery_updated_at'] !== null;
            }));
        $this->setEventLogger($bridge, $logService);
        $this->setContext($bridge, new SendContext());
        $this->setLoggingEnabled($bridge, true);

        $succeeded = $this->invokeDispatch($bridge, [$this->connection()], $this->message(), ['subject' => 'Hi', 'to' => ['a@example.org']]);

        $this->assertTrue($succeeded);
    }

    public function testNoWebhookProviderStampsDeliveredOnSuccess(): void
    {
        // A provider with no inbound delivery webhook can never receive a delivery event, so a
        // successful hand-off is stamped delivered rather than left pending forever.
        $bridge = $this->bridgeWithTransport(new ScriptedTransport([SendResult::success()]));

        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('bulkInsert')
            ->once()
            ->with(Mockery::on(function (array $logs): bool {
                return $logs[0]['delivery_status']     === 'delivered'
                    && $logs[0]['delivery_updated_at'] !== null;
            }));
        $this->setEventLogger($bridge, $logService);
        $this->setContext($bridge, new SendContext());
        $this->setLoggingEnabled($bridge, true);

        $this->invokeDispatch($bridge, [$this->connection()], $this->message(), ['subject' => 'Hi', 'to' => ['a@example.org']]);
    }

    public function testWebhookBackedProviderLeavesDeliveryPendingOnSuccess(): void
    {
        // A provider with an inbound adapter and the webhook enabled reports delivery asynchronously,
        // so the row is left unstamped for the inbound event to resolve.
        $bridge = $this->bridgeWithTransport(new ScriptedTransport([SendResult::success()]), null, 'postmark');

        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('bulkInsert')
            ->once()
            ->with(Mockery::on(function (array $logs): bool {
                return $logs[0]['delivery_status']     === null
                    && $logs[0]['delivery_updated_at'] === null;
            }));
        $this->setEventLogger($bridge, $logService);
        $this->setContext($bridge, new SendContext());
        $this->setLoggingEnabled($bridge, true);

        $this->invokeDispatch($bridge, [$this->connection(['provider' => 'postmark'])], $this->message(), ['subject' => 'Hi', 'to' => ['a@example.org']]);
    }

    public function testWebhookBackedProviderWithWebhookDisabledStampsDelivered(): void
    {
        // The adapter exists but the connection disabled the webhook, so nothing will ever report — the
        // accepted hand-off is the final signal and is stamped delivered.
        $bridge = $this->bridgeWithTransport(new ScriptedTransport([SendResult::success()]), null, 'postmark');

        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('bulkInsert')
            ->once()
            ->with(Mockery::on(function (array $logs): bool {
                return $logs[0]['delivery_status'] === 'delivered';
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
        $bridge = $this->bridgeWithTransport(new ScriptedTransport([SendResult::acceptedWithError('Recipient rejected', '200')]), 'delivered');

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
                return \array_key_exists('connection', $logs[0]) && $logs[0]['connection'] === null;
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

    private function invokeConnectionLabel(WpMailBridge $bridge, Connection $connection): string
    {
        $method = new ReflectionMethod(WpMailBridge::class, 'connectionLabel');
        $method->setAccessible(true);

        return $method->invoke($bridge, $connection);
    }

    /**
     * @param Connection[] $connections
     */
    private function invokeDispatch(WpMailBridge $bridge, array $connections, MailMessage $message, array $mailData): bool
    {
        $method = new ReflectionMethod(WpMailBridge::class, 'dispatch');
        $method->setAccessible(true);

        return $method->invoke($bridge, $connections, $message, $mailData);
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

    private function bridgeWithTransport(TransportInterface $transport, ?string $deliveryStatusOnAccept = null, string $providerKey = 'fake'): WpMailBridge
    {
        $registry = new ProviderRegistry();
        $registry->register(new FakeProvider($transport, $deliveryStatusOnAccept, $providerKey));

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

        $stamperProperty = $refClass->getProperty('stamper');
        $stamperProperty->setAccessible(true);
        $stamperProperty->setValue($bridge, new TrackingIdStamper());

        $sourceDetectorProperty = $refClass->getProperty('sourceDetector');
        $sourceDetectorProperty->setAccessible(true);
        $sourceDetectorProperty->setValue($bridge, new MailSourceDetector());

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

    private ?string $deliveryStatusOnAccept;

    private string $providerKey;

    public function __construct(TransportInterface $transport, ?string $deliveryStatusOnAccept = null, string $providerKey = 'fake')
    {
        $this->transport              = $transport;
        $this->deliveryStatusOnAccept = $deliveryStatusOnAccept;
        $this->providerKey            = $providerKey;
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

    public function deliveryStatusOnAccept(): ?string
    {
        return $this->deliveryStatusOnAccept;
    }
}
