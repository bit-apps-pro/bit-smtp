<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Dispatch;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;
use BitApps\SMTP\Mail\Dispatch\MailEventLogger;
use BitApps\SMTP\Mail\Dispatch\SendContext;
use BitApps\SMTP\Mail\Dispatch\WpMailBridge;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\SendResult;
use BitApps\SMTP\Mail\Providers\ProviderRegistry;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;
use ReflectionClass;
use ReflectionMethod;

/**
 * @internal
 *
 * @coversNothing
 */
class WpMailBridgeTest extends BaseUnitTestCase
{
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

        $bridge->onNativeMailSucceeded(['subject' => 'Hi', 'to' => ['a@example.org']]);
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

    private function bridgeWithTransport(TransportInterface $transport): WpMailBridge
    {
        $registry = new ProviderRegistry();
        $registry->register(new FakeProvider($transport));

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

        return $bridge;
    }

    private function invokeSendVia(WpMailBridge $bridge, Connection $connection, MailMessage $message): SendResult
    {
        $method = new ReflectionMethod(WpMailBridge::class, 'sendVia');
        $method->setAccessible(true);

        return $method->invoke($bridge, $connection, $message);
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

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    public function key(): string
    {
        return 'fake';
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
}
