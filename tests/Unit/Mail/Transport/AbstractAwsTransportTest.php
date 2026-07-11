<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Transport;

use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Transport\AbstractAwsTransport;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
class AbstractAwsTransportTest extends BaseUnitTestCase
{
    private ApiClient $apiClient;

    private FakeAwsTransport $transport;

    private MailMessage $message;

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiClient = Mockery::mock(ApiClient::class);
        $this->transport = new FakeAwsTransport($this->apiClient, new SigV4Signer());
        $this->message    = MailMessage::fromArray(['to' => ['user@example.com'], 'subject' => 'Hi', 'body' => 'Body']);
        $this->connection = Connection::fromArray(['id' => 'conn_1', 'provider' => 'fake', 'kind' => 'api']);
    }

    public function testSendSignsRequestWithAws4Hmac256Authorization(): void
    {
        $captured = null;
        $this->apiClient->shouldReceive('setHeaders')
            ->once()
            ->with(Mockery::on(static function (array $headers) use (&$captured) {
                $captured = $headers;

                return true;
            }))
            ->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, []));

        $this->transport->send($this->message, $this->connection);

        $this->assertStringStartsWith('AWS4-HMAC-SHA256 Credential=fake-access-key/', $captured['Authorization']);
    }

    public function testSendSignsContentHashMatchingThePostedBody(): void
    {
        $capturedHeaders = null;
        $capturedBody    = null;
        $this->apiClient->shouldReceive('setHeaders')
            ->once()
            ->with(Mockery::on(static function (array $headers) use (&$capturedHeaders) {
                $capturedHeaders = $headers;

                return true;
            }))
            ->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(static function ($body) use (&$capturedBody) {
                $capturedBody = $body;

                return true;
            }))
            ->andReturn(new ApiResponse(200, []));

        $this->transport->send($this->message, $this->connection);

        $this->assertSame(hash('sha256', $capturedBody), $capturedHeaders['X-Amz-Content-Sha256']);
    }

    public function testSendPostsTheExactBodyStringThatWasSigned(): void
    {
        $capturedBody = null;
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://fake.example.com/send', Mockery::on(static function ($body) use (&$capturedBody) {
                $capturedBody = $body;

                return true;
            }))
            ->andReturn(new ApiResponse(200, []));

        $this->transport->send($this->message, $this->connection);

        $this->assertSame('{"fixture":"body"}', $capturedBody);
    }

    public function testSendReturnsSuccessResultOn200(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, ['id' => 'abc']));

        $result = $this->transport->send($this->message, $this->connection);

        $this->assertTrue($result->isOk());
    }

    public function testSendReturnsFailureResultOnNon200WithParsedError(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(400, ['message' => 'Bad request']));

        $result = $this->transport->send($this->message, $this->connection);

        $this->assertFalse($result->isOk());
        $this->assertSame('Bad request', $result->getError());
        $this->assertSame('400', $result->getCode());
    }

    public function testSendReturnsFailureResultWhenClientThrows(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andThrow(new \RuntimeException('Connection timed out'));

        $result = $this->transport->send($this->message, $this->connection);

        $this->assertFalse($result->isOk());
        $this->assertSame('Connection timed out', $result->getError());
    }
}

/**
 * Minimal concrete transport exercising only AbstractAwsTransport's sign-then-post orchestration —
 * endpoint/body/success/error rules are fixed stand-ins, not a real provider.
 */
class FakeAwsTransport extends AbstractAwsTransport
{
    protected function service(): string
    {
        return 'fakeservice';
    }

    protected function region(Connection $connection): string
    {
        return 'us-east-1';
    }

    protected function accessKey(Connection $connection): string
    {
        return 'fake-access-key';
    }

    protected function secretKey(Connection $connection): string
    {
        return 'fake-secret-key';
    }

    protected function endpoint(Connection $connection): string
    {
        return 'https://fake.example.com/send';
    }

    /**
     * @return string
     */
    protected function buildBody(MailMessage $message, Connection $connection)
    {
        return '{"fixture":"body"}';
    }

    /**
     * @param array|string $body
     */
    protected function successFrom(int $status, $body): bool
    {
        return $status === 200;
    }

    /**
     * @param array|string $body
     */
    protected function errorFrom(int $status, $body): string
    {
        return \is_array($body) && isset($body['message']) ? $body['message'] : 'Unknown error';
    }
}
