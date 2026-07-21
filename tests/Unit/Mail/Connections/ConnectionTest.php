<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Connections;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use InvalidArgumentException;

/**
 * @internal
 *
 * @coversNothing
 */
class ConnectionTest extends BaseUnitTestCase
{
    public function testFromArrayAndGetters(): void
    {
        $data       = $this->sampleData();
        $connection = Connection::fromArray($data);

        $this->assertSame($data['id'], $connection->getId());
        $this->assertSame($data['provider'], $connection->getProvider());
        $this->assertSame($data['kind'], $connection->getKind());
        $this->assertSame($data['name'], $connection->getName());
        $this->assertTrue($connection->isEnabled());
        $this->assertSame($data['fromEmail'], $connection->getFromEmail());
        $this->assertSame($data['fromName'], $connection->getFromName());
        $this->assertSame($data['replyToEmail'], $connection->getReplyToEmail());
        $this->assertSame($data['settings'], $connection->getSettings());
        $this->assertSame($data['credentials'], $connection->getCredentials());
    }

    public function testToArrayRoundTrip(): void
    {
        $data       = $this->sampleData();
        $connection = Connection::fromArray($data);
        $result     = $connection->toArray();

        $this->assertSame($data, $result);
    }

    public function testSettingConvenienceMethod(): void
    {
        $connection = Connection::fromArray($this->sampleData());

        $this->assertSame('smtp.example.com', $connection->setting('host'));
        $this->assertSame(587, $connection->setting('port'));
        $this->assertSame('default_value', $connection->setting('missing', 'default_value'));
        $this->assertNull($connection->setting('nonexistent'));
    }

    public function testFromArrayThrowsWhenIdMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/id/');

        $data = $this->sampleData();
        unset($data['id']);
        Connection::fromArray($data);
    }

    public function testFromArrayThrowsWhenProviderMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/provider/');

        $data = $this->sampleData();
        unset($data['provider']);
        Connection::fromArray($data);
    }

    public function testFromArrayThrowsWhenKindMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/kind/');

        $data = $this->sampleData();
        unset($data['kind']);
        Connection::fromArray($data);
    }

    public function testFromArrayUsesDefaultsForOptionalFields(): void
    {
        $connection = Connection::fromArray([
            'id'       => 'conn-minimal',
            'provider' => 'other_smtp',
            'kind'     => 'smtp',
        ]);

        $this->assertSame('', $connection->getName());
        $this->assertFalse($connection->isEnabled());
        $this->assertSame('', $connection->getFromEmail());
        $this->assertSame('', $connection->getFromName());
        $this->assertSame('', $connection->getReplyToEmail());
        $this->assertSame([], $connection->getSettings());
        $this->assertSame([], $connection->getCredentials());
    }

    public function testWebhookIsEnabledByDefaultForApiConnections(): void
    {
        $connection = Connection::fromArray([
            'id' => 'conn-api', 'provider' => 'postmark', 'kind' => 'api',
        ]);

        $this->assertTrue($connection->isWebhookEnabled());
    }

    public function testWebhookCanBeDisabledOnApiConnections(): void
    {
        $connection = Connection::fromArray([
            'id'       => 'conn-api', 'provider' => 'postmark', 'kind' => 'api',
            'settings' => ['webhook_enabled' => false],
        ]);

        $this->assertFalse($connection->isWebhookEnabled());
    }

    public function testWebhookExplicitlyEnabledOnApiConnections(): void
    {
        $connection = Connection::fromArray([
            'id'       => 'conn-api', 'provider' => 'postmark', 'kind' => 'api',
            'settings' => ['webhook_enabled' => true],
        ]);

        $this->assertTrue($connection->isWebhookEnabled());
    }

    public function testWebhookNeverEnabledForSmtpConnectionsEvenWhenSet(): void
    {
        $connection = Connection::fromArray([
            'id'       => 'conn-smtp', 'provider' => 'other_smtp', 'kind' => 'smtp',
            'settings' => ['webhook_enabled' => true],
        ]);

        $this->assertFalse($connection->isWebhookEnabled());
    }

    private function sampleData(): array
    {
        return [
            'id'           => 'conn-1',
            'provider'     => 'other_smtp',
            'kind'         => 'smtp',
            'name'         => 'My SMTP',
            'enabled'      => true,
            'fromEmail'    => 'hello@example.com',
            'fromName'     => 'Hello',
            'replyToEmail' => 'reply@example.com',
            'settings'     => ['host' => 'smtp.example.com', 'port' => 587],
            'credentials'  => ['password' => ['source' => 'db', 'value' => 'secret']],
        ];
    }
}
