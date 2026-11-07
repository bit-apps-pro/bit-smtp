<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Transport;

use BitApps\SMTP\Mail\Auth\BearerTokenStrategy;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\AuthStrategyInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\SendResult;
use BitApps\SMTP\Mail\Support\EncoderInterface;
use BitApps\SMTP\Mail\Support\JsonEncoder;
use BitApps\SMTP\Mail\Transport\AbstractApiTransport;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;
use RuntimeException;

/**
 * @internal
 *
 * @coversNothing
 */
class AbstractApiTransportTest extends BaseUnitTestCase
{
    private ApiClient $apiClient;

    private FakeApiTransport $transport;

    private MailMessage $message;

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiClient  = Mockery::mock(ApiClient::class);
        $this->transport  = new FakeApiTransport($this->apiClient);
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

    public function testMultipartMessageReachesBuildBodyAsSinglePart(): void
    {
        $this->expectPostedBody(['type' => 'text/html', 'body' => '<p>html</p>']);

        (new BodyEchoApiTransport($this->apiClient))->send($this->multipartMessage(), $this->connection);
    }

    public function testUnreadableMultipartFailsWithoutPosting(): void
    {
        $this->apiClient->shouldNotReceive('post');

        $message = MailMessage::fromArray([
            'to'          => ['user@example.com'],
            'subject'     => 'Hi',
            'body'        => "--b1\r\nContent-Type: text/calendar\r\n\r\nBEGIN:VCALENDAR\r\n--b1--",
            'contentType' => 'multipart/alternative; boundary="b1"',
        ]);

        $result = (new BodyEchoApiTransport($this->apiClient))->send($message, $this->connection);

        $this->assertFalse($result->isOk());
        $this->assertFalse($result->isAccepted());
        $this->assertNotEmpty($result->getError());
        $this->assertSame(SendResult::CODE_UNSENDABLE_BODY, $result->getCode());
        $this->assertStringContainsString('text/calendar', implode(' ', $result->getDebug()));
    }

    public function testPartsLeftOutOfAFlattenedSendReachTheDebugLines(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(202, ['id' => 'abc']));

        $result = (new BodyEchoApiTransport($this->apiClient))->send($this->multipartMessage(), $this->connection);

        $this->assertTrue($result->isOk());
        $debug = $result->getDebug();
        $this->assertStringContainsString('text/plain', (string) $debug[0]);
        $this->assertStringNotContainsString('plain' . "\r\n", (string) $debug[0]);
        $this->assertSame(202, $debug['status']);
    }

    public function testSinglePartSendAddsNoDebugLines(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(202, ['id' => 'abc']));

        $result = (new BodyEchoApiTransport($this->apiClient))->send($this->message, $this->connection);

        $this->assertSame(['status' => 202, 'body' => ['id' => 'abc']], $result->getDebug());
    }

    public function testRawMimeTransportKeepsTheMultipartBody(): void
    {
        $message = $this->multipartMessage();
        $this->expectPostedBody(['type' => 'multipart/alternative; boundary="b1"', 'body' => $message->getBody()]);

        (new RawMimeApiTransport($this->apiClient))->send($message, $this->connection);
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

    public function testOptInTransportEncodesBodyAppliesStrategyLastAndPostsAsIs(): void
    {
        $transport  = new OptInApiTransport($this->apiClient, new BearerTokenStrategy(['credentialKey' => 'api_key']), new JsonEncoder());
        $connection = Connection::fromArray([
            'id'          => 'conn_2', 'provider' => 'fake', 'kind' => 'api',
            'credentials' => ['api_key' => ['source' => 'database', 'value' => 'secret-token']],
        ]);

        $this->apiClient->shouldReceive('setHeaders')
            ->once()
            ->with(['Content-Type' => 'application/json', 'Authorization' => 'Bearer secret-token'])
            ->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.example.com/v1/send', '{"subject":"Hi"}')
            ->andReturn(new ApiResponse(202, ['id' => 'abc']));

        $result = $transport->send($this->message, $connection);

        $this->assertTrue($result->isOk());
    }

    /**
     * Multipart message whose HTML part is base64 encoded.
     */
    private function multipartMessage(): MailMessage
    {
        return MailMessage::fromArray([
            'to'          => ['user@example.com'],
            'subject'     => 'Hi',
            'body'        => "--b1\r\nContent-Type: text/plain\r\n\r\nplain\r\n--b1\r\nContent-Type: text/html\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode('<p>html</p>') . "\r\n--b1--",
            'contentType' => 'multipart/alternative; boundary="b1"',
        ]);
    }

    /**
     * Expect one post whose body equals the given array.
     *
     * @param array<string,string> $expected
     */
    private function expectPostedBody(array $expected): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), $expected)
            ->andReturn(new ApiResponse(202, []));
    }
}

/**
 * Minimal concrete transport for AbstractApiTransport testing; fixed stand-in rules, not real provider.
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
    protected function acceptedFrom(int $status, $body): bool
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

class BodyEchoApiTransport extends FakeApiTransport
{
    /**
     * @return array|string
     */
    protected function buildBody(MailMessage $message, Connection $connection)
    {
        return ['type' => $message->getContentType(), 'body' => $message->getBody()];
    }
}

class RawMimeApiTransport extends BodyEchoApiTransport
{
    protected function sendsRawMime(): bool
    {
        return true;
    }
}

/**
 * Concrete transport that opts into the strategy+encoder path — exercises the additive ApiBase seam.
 */
class OptInApiTransport extends AbstractApiTransport
{
    public function __construct(ApiClient $client, AuthStrategyInterface $strategy, EncoderInterface $encoder)
    {
        parent::__construct($client);
        $this->useStrategy($strategy, $encoder);
    }

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
    protected function acceptedFrom(int $status, $body): bool
    {
        return $status >= 200 && $status < 300;
    }

    /**
     * @param array|string $body
     */
    protected function errorFrom(int $status, $body): string
    {
        return 'Unknown error';
    }
}
