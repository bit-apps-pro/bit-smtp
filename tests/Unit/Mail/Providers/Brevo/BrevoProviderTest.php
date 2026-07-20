<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\Brevo;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use BitApps\SMTP\Mail\Providers\Brevo\BrevoProvider;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Drives BrevoProvider::transport()->send() over a mocked ApiClient, proving the shipped
 * descriptor engine renders Brevo's contract correctly (spec §5/§11).
 *
 * @internal
 *
 * @coversNothing
 */
class BrevoProviderTest extends BaseUnitTestCase
{
    private ApiClient $apiClient;

    private AuthorizationResolver $resolver;

    /**
     * @var string[]
     */
    private $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->apiClient = Mockery::mock(ApiClient::class);
        $this->resolver  = new AuthorizationResolver(Mockery::mock(OAuth2TokenProvider::class), new SigV4Signer());
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    public function testSendsSuccessfullyToTheBrevoEmailEndpointWithApiKeyHeaderAndJsonBody(): void
    {
        $this->apiClient->shouldReceive('setHeaders')
            ->once()
            ->with(['Content-Type' => 'application/json', 'api-key' => 'server-token-123'])
            ->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.brevo.com/v3/smtp/email', Mockery::on(function (string $json) {
                $body = json_decode($json, true);

                return $body['sender']      === ['email' => 'from@example.com']
                    && $body['to']          === [['email' => 'to@example.com']]
                    && $body['subject']     === 'Subject'
                    && $body['textContent'] === 'Body text';
            }))
            ->andReturn(new ApiResponse(201, []));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertTrue($result->isOk());
    }

    public function testFromWithADisplayNameRendersAsASenderObjectWithEmailAndName(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $json) {
                $body = json_decode($json, true);

                return $body['sender'] === ['email' => 'from@example.com', 'name' => 'From Name'];
            }))
            ->andReturn(new ApiResponse(201, []));

        $connection = $this->connection(['fromName' => 'From Name']);

        $result = $this->provider()->transport()->send($this->message(), $connection);

        $this->assertTrue($result->isOk());
    }

    public function testReplyToRendersAsASingleObjectRatherThanAList(): void
    {
        $captured = [];
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $json) use (&$captured) {
                $captured = json_decode($json, true);

                return true;
            }))
            ->andReturn(new ApiResponse(201, []));

        $message = $this->message(['replyTo' => 'reply@example.com']);

        $result = $this->provider()->transport()->send($message, $this->connection());

        $this->assertTrue($result->isOk());
        // The 'single' unwrap must produce a bare {email} object, not a one-element list [{email}].
        $this->assertSame(['email' => 'reply@example.com'], $captured['replyTo']);
        $this->assertArrayNotHasKey(0, $captured['replyTo']);
    }

    public function testToIsAListOfObjectsWithEmailAndOptionalName(): void
    {
        $captured = [];
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $json) use (&$captured) {
                $captured = json_decode($json, true);

                return true;
            }))
            ->andReturn(new ApiResponse(201, []));

        $message = $this->message(['to' => ['to@example.com', 'Second <second@example.com>']]);

        $result = $this->provider()->transport()->send($message, $this->connection());

        $this->assertTrue($result->isOk());
        $this->assertSame([
            ['email' => 'to@example.com'],
            ['email' => 'second@example.com', 'name' => 'Second'],
        ], $captured['to']);
    }

    public function testHtmlContentTypeMapsBodyToTheHtmlContentKeyAndOmitsTextContent(): void
    {
        $captured = [];
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.brevo.com/v3/smtp/email', Mockery::on(function (string $json) use (&$captured) {
                $captured = json_decode($json, true);

                return true;
            }))
            ->andReturn(new ApiResponse(201, []));

        $message = $this->message(['contentType' => 'text/html', 'body' => '<p>Hello</p>']);

        $result = $this->provider()->transport()->send($message, $this->connection());

        $this->assertTrue($result->isOk());
        $this->assertSame('<p>Hello</p>', $captured['htmlContent']);
        $this->assertArrayNotHasKey('textContent', $captured);
    }

    public function testAttachmentRendersAsABrevoShapedEntryUnderTheAttachmentKey(): void
    {
        Functions\when('wp_check_filetype')->justReturn(['ext' => 'txt', 'type' => 'text/plain']);
        $path = $this->createTempFile('known attachment bytes');

        $captured = [];
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.brevo.com/v3/smtp/email', Mockery::on(function (string $json) use (&$captured) {
                $captured = json_decode($json, true);

                return true;
            }))
            ->andReturn(new ApiResponse(201, []));

        $message = $this->message(['attachments' => ['report.txt' => $path]]);

        $result = $this->provider()->transport()->send($message, $this->connection());

        $this->assertTrue($result->isOk());
        $this->assertCount(1, $captured['attachment']);
        $entry = $captured['attachment'][0];
        $this->assertEqualsCanonicalizing(['content', 'name'], array_keys($entry));
        $this->assertSame('report.txt', $entry['name']);
        $this->assertSame(base64_encode('known attachment bytes'), $entry['content']);
    }

    public function testStatusOutsideTheDeclaredSuccessListMapsToFailure(): void
    {
        // success => [201] in the descriptor; a 2xx like 200 is still a failure by that contract.
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, []));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('Brevo error HTTP 200', $result->getError());
    }

    public function testErrorBodyAtA4xxMapsToSendResultFailureCarryingTheMessage(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(422, [
            'message' => 'Invalid "sender" address',
        ]));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('Invalid "sender" address', $result->getError());
        $this->assertSame('422', $result->getCode());
    }

    private function provider(): BrevoProvider
    {
        return new BrevoProvider($this->apiClient, $this->resolver);
    }

    private function message(array $overrides = []): MailMessage
    {
        return MailMessage::fromArray(array_merge([
            'to' => ['to@example.com'], 'subject' => 'Subject', 'body' => 'Body text',
        ], $overrides));
    }

    private function connection(array $overrides = []): Connection
    {
        return Connection::fromArray(array_merge([
            'id'          => 'conn_1', 'provider' => 'brevo', 'kind' => 'api',
            'fromEmail'   => 'from@example.com',
            'credentials' => ['api_key' => ['source' => 'database', 'value' => 'server-token-123']],
        ], $overrides));
    }

    private function createTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bit-smtp-brevo-attach-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
