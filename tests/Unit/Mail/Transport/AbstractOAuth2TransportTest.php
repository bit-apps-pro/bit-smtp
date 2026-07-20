<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Transport;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\OAuth2ProviderInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Transport\AbstractOAuth2Transport;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
class AbstractOAuth2TransportTest extends BaseUnitTestCase
{
    public function testImplementsOAuth2ProviderInterface(): void
    {
        $transport = new FakeOAuth2Transport(Mockery::mock(ApiClient::class));

        $this->assertInstanceOf(OAuth2ProviderInterface::class, $transport);
    }
}

/**
 * Minimal concrete transport exercising only AbstractOAuth2Transport's auth-header behavior.
 */
class FakeOAuth2Transport extends AbstractOAuth2Transport
{
    public function authUrl(): string
    {
        return 'https://example.com/auth';
    }

    public function tokenUrl(): string
    {
        return 'https://example.com/token';
    }

    public function scopes(): array
    {
        return ['scope'];
    }

    public function sendEndpoint(): string
    {
        return 'https://example.com/send';
    }

    protected function endpoint(Connection $connection): string
    {
        return $this->sendEndpoint();
    }

    /**
     * @return array|string
     */
    protected function buildBody(MailMessage $message, Connection $connection)
    {
        return [];
    }

    /**
     * @param array|string $body
     */
    protected function successFrom(int $status, $body): bool
    {
        return $status >= 200 && $status < 300;
    }

    /**
     * @param array|string $body
     */
    protected function errorFrom(int $status, $body): string
    {
        return 'error';
    }
}
