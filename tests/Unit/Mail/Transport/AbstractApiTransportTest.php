<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Transport;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Transport\AbstractApiTransport;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;
use RuntimeException;

class AbstractApiTransportTest extends BaseUnitTestCase
{
    private ApiClient $apiClient;

    private FakeApiTransport $transport;

    private MailMessage $message;

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiClient = Mockery::mock(ApiClient::class);
        $this->transport = new FakeApiTransport($this->apiClient);
        $this->message    = MailMessage::fromArray(['to' => ['user@example.com'], 'subject' => 'Hi', 'body' => 'Body']);
        $this->connection = Connection::fromArray(['id' => 'conn_1', 'provider' => 'fake', 'kind' => 'api']);
    }

    public function testSendSetsAuthAndContentTypeHeadersThenPostsEndpointAndBody(): void
    {
        $this->apiClient->shouldReceive('setHeaders')
            ->once()
            ->with(['Authorization' => 'Bearer conn_1', 'Content-Type' => 'application/json'])
            ->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.example.com/v1/send', ['subject' => 'Hi'])
            ->andReturn(new ApiResponse(202, ['id' => 'abc']));

        $result = $this->transport->send($this->message, $this->connection);

        $this->assertTrue($result->isOk());
    }

    public function testSendReturnsSuccessResultOn2xxWithDebugPayload(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(202, ['id' => 'abc']));

        $result = $this->transport->send($this->message, $this->connection);

        $this->assertTrue($result->isOk());
        $this->assertNull($result->getError());
        $this->assertSame(['status' => 202, 'body' => ['id' => 'abc']], $result->getDebug());
    }

    public function testSendReturnsFailureResultOn4xxWithParsedError(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(400, ['message' => 'Invalid recipient']));

        $result = $this->transport->send($this->message, $this->connection);

        $this->assertFalse($result->isOk());
        $this->assertSame('Invalid recipient', $result->getError());
        $this->assertSame('400', $result->getCode());
    }

    public function testSendReturnsFailureResultOn5xx(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(500, ['message' => 'Provider outage']));

        $result = $this->transport->send($this->message, $this->connection);

        $this->assertFalse($result->isOk());
        $this->assertSame('Provider outage', $result->getError());
        $this->assertSame('500', $result->getCode());
    }

    public function testSendReturnsFailureResultWhenClientThrows(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andThrow(new RuntimeException('Connection timed out'));

        $result = $this->transport->send($this->message, $this->connection);

        $this->assertFalse($result->isOk());
        $this->assertSame('Connection timed out', $result->getError());
    }
}

/**
 * Minimal concrete transport exercising AbstractApiTransport's orchestration only —
 * endpoint/body/headers/success/error rules are fixed stand-ins, not a real provider.
 */
class FakeApiTransport extends AbstractApiTransport
{
    protected function endpoint(Connection $connection): string
    {
        return 'https://api.example.com/v1/send';
    }

    /**
     * @return array|string
     */
    protected function buildBody(MailMessage $message, Connection $connection)
    {
        return ['subject' => $message->getSubject()];
    }

    protected function authHeaders(Connection $connection): array
    {
        return ['Authorization' => 'Bearer ' . $connection->getId()];
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
        return \is_array($body) && isset($body['message']) ? $body['message'] : 'Unknown error';
    }
}
