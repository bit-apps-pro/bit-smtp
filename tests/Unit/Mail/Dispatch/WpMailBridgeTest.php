<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Dispatch;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;
use BitApps\SMTP\Mail\Dispatch\MailEventLogger;
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
