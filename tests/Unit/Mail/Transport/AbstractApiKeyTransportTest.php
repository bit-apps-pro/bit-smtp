<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Transport;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Transport\AbstractApiKeyTransport;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
class AbstractApiKeyTransportTest extends BaseUnitTestCase
{
    private ApiClient $apiClient;

    private MailMessage $message;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiClient = Mockery::mock(ApiClient::class);
        $this->message   = MailMessage::fromArray(['to' => ['user@example.com'], 'subject' => 'Hi', 'body' => 'Body']);
    }

    public function testAuthHeadersBuildsBearerTokenFromApiKeyCredential(): void
    {
        $transport  = new FakeApiKeyTransport($this->apiClient);
        $connection = Connection::fromArray([
            'id'          => 'conn_1', 'provider' => 'fake', 'kind' => 'api',
            'credentials' => ['api_key' => ['source' => 'database', 'value' => 'secret-key']],
        ]);

        $this->apiClient->shouldReceive('setHeaders')
            ->once()
            ->with(['Authorization' => 'Bearer secret-key', 'Content-Type' => 'application/json'])
            ->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, []));

        $this->transportSend($transport, $connection);
    }

    public function testAuthHeadersFallsBackToEmptyStringWhenCredentialMissing(): void
    {
        $transport  = new FakeApiKeyTransport($this->apiClient);
        $connection = Connection::fromArray(['id' => 'conn_1', 'provider' => 'fake', 'kind' => 'api']);

        $this->apiClient->shouldReceive('setHeaders')
            ->once()
            ->with(['Authorization' => 'Bearer ', 'Content-Type' => 'application/json'])
            ->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, []));

        $this->transportSend($transport, $connection);
    }

    public function testCredentialKeyIsOverridableByConcreteTransport(): void
    {
        $transport  = new FakeCustomCredentialKeyTransport($this->apiClient);
        $connection = Connection::fromArray([
            'id'          => 'conn_1', 'provider' => 'fake', 'kind' => 'api',
            'credentials' => ['token' => ['source' => 'database', 'value' => 'custom-token']],
        ]);

        $this->apiClient->shouldReceive('setHeaders')
            ->once()
            ->with(['Authorization' => 'Bearer custom-token', 'Content-Type' => 'application/json'])
            ->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, []));

        $this->transportSend($transport, $connection);
    }

    private function transportSend(AbstractApiKeyTransport $transport, Connection $connection): void
    {
        $transport->send($this->message, $connection);
    }
}

/**
 * Minimal concrete transport exercising only AbstractApiKeyTransport's auth-header behavior.
 */
class FakeApiKeyTransport extends AbstractApiKeyTransport
{
    protected function endpoint(Connection $connection): string
    {
        return 'https://api.example.com/send';
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

class FakeCustomCredentialKeyTransport extends FakeApiKeyTransport
{
    protected function credentialKey(): string
    {
        return 'token';
    }
}
