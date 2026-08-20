<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\Cloudflare;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use BitApps\SMTP\Mail\Providers\Cloudflare\CloudflareProvider;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Exercises the Cloudflare Email Sending contract at the HTTP boundary: the documented bearer
 * header, account-scoped endpoint, structured JSON builder payload, and response envelope.
 *
 * @internal
 *
 * @coversNothing
 */
class CloudflareProviderTest extends BaseUnitTestCase
{
    private const API_TOKEN = 'cf-secret-token';

    private ApiClient $apiClient;

    private AuthorizationResolver $resolver;

    /**
     * @var string[]
     */
    private array $tempFiles = [];

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

        parent::tearDown();
    }

    public function testSendsTextEmailToTheAccountEndpointWithBearerTokenOnlyInAuthorizationHeader(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->with([
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . self::API_TOKEN,
        ])->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->with(
            'https://api.cloudflare.com/client/v4/accounts/0123456789abcdef0123456789abcdef/email/sending/send',
            Mockery::on(function (string $json): bool {
                $body = json_decode($json, true);

                return $body === [
                    'from'    => ['address' => 'from@example.com'],
                    'to'      => [['address' => 'to@example.com']],
                    'subject' => 'Subject',
                    'text'    => 'Body text',
                ] && strpos($json, self::API_TOKEN) === false;
            })
        )->andReturn(new ApiResponse(200, $this->successEnvelope('cf-message-1')));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertTrue($result->isOk());
        $this->assertSame('cf-message-1', $result->getMessageId());
    }

    public function testRendersHtmlInsteadOfTextForHtmlMessage(): void
    {
        $captured = [];
        $this->expectSuccessfulSend($captured);

        $result = $this->provider()->transport()->send(
            $this->message(['contentType' => 'text/html; charset=UTF-8', 'body' => '<p>Hello</p>']),
            $this->connection()
        );

        $this->assertTrue($result->isOk());
        $this->assertSame('<p>Hello</p>', $captured['html']);
        $this->assertArrayNotHasKey('text', $captured);
    }

    public function testRendersToCcBccAndReplyToUsingCloudflareAddressObjects(): void
    {
        $captured = [];
        $this->expectSuccessfulSend($captured);

        $result = $this->provider()->transport()->send($this->message([
            'to'      => ['To Name <to@example.com>', 'second@example.com'],
            'cc'      => ['Cc Name <cc@example.com>'],
            'bcc'     => ['bcc@example.com'],
            'replyTo' => 'Reply Name <reply@example.com>',
        ]), $this->connection());

        $this->assertTrue($result->isOk());
        $this->assertSame([
            ['address' => 'to@example.com', 'name' => 'To Name'],
            ['address' => 'second@example.com'],
        ], $captured['to']);
        $this->assertSame([['address' => 'cc@example.com', 'name' => 'Cc Name']], $captured['cc']);
        $this->assertSame([['address' => 'bcc@example.com']], $captured['bcc']);
        $this->assertSame(['address' => 'reply@example.com', 'name' => 'Reply Name'], $captured['reply_to']);
    }

    public function testRendersMessageHeadersAsTheCloudflareHeadersMap(): void
    {
        $captured = [];
        $this->expectSuccessfulSend($captured);

        $result = $this->provider()->transport()->send($this->message([
            'headers' => ['X-Custom-Header' => 'custom-value'],
        ]), $this->connection());

        $this->assertTrue($result->isOk());
        $this->assertSame(['X-Custom-Header' => 'custom-value'], $captured['headers']);
    }

    public function testRendersAttachmentWithTheDocumentedCloudflareDispositionAndMimeFields(): void
    {
        Functions\when('wp_check_filetype')->justReturn(['ext' => 'txt', 'type' => 'text/plain']);
        $path     = $this->createTempFile('known attachment bytes');
        $captured = [];
        $this->expectSuccessfulSend($captured);

        $result = $this->provider()->transport()->send(
            $this->message(['attachments' => ['report.txt' => $path]]),
            $this->connection()
        );

        $this->assertTrue($result->isOk());
        $this->assertSame([[
            'content'     => base64_encode('known attachment bytes'),
            'disposition' => 'attachment',
            'filename'    => 'report.txt',
            'type'        => 'text/plain',
        ]], $captured['attachments']);
    }

    public function testErrorEnvelopeUsesFirstCloudflareErrorMessage(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(400, [
            'success'  => false,
            'errors'   => [['code' => 1000, 'message' => 'Invalid sender address']],
            'messages' => [],
            'result'   => null,
        ]));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('Invalid sender address', $result->getError());
        $this->assertSame('400', $result->getCode());
    }

    public function testUnexpectedStatusIsNotAcceptedEvenWithSuccessBody(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(202, $this->successEnvelope('cf-message-ignored')));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('Cloudflare error HTTP 202', $result->getError());
    }

    public function testSuccessFalseEnvelopeIsUnacceptedSoFallbackCanRun(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, [
            'success'  => false,
            'errors'   => [['code' => 1000, 'message' => 'Invalid sender address']],
            'messages' => [],
            'result'   => null,
        ]));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertFalse($result->isAccepted());
        $this->assertSame('Invalid sender address', $result->getError());
    }

    #[DataProvider('invalidSuccessBodies')]
    public function testMalformedOrEmptySuccessResponseIsUnacceptedSoFallbackCanRun(string $body, string $expectedError): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, $body));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertFalse($result->isAccepted());
        $this->assertSame($expectedError, $result->getError());
    }

    public function testSuccessEnvelopeWithoutMessageIdIsUnacceptedSoFallbackCanRun(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, [
            'success'  => true,
            'errors'   => [],
            'messages' => [],
            'result'   => [],
        ]));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertFalse($result->isAccepted());
        $this->assertSame('Cloudflare error HTTP 200', $result->getError());
    }

    public function testUppercaseAccountIdUsesTheAccountEndpoint(): void
    {
        $accountId = 'ABCDEFABCDEFABCDEFABCDEFABCDEFAB';
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->with(
            'https://api.cloudflare.com/client/v4/accounts/' . $accountId . '/email/sending/send',
            Mockery::any()
        )->andReturn(new ApiResponse(200, $this->successEnvelope('cf-uppercase-id')));

        $result = $this->provider()->transport()->send(
            $this->message(),
            $this->connection(['settings' => ['account_id' => $accountId]])
        );

        $this->assertTrue($result->isOk());
        $this->assertSame('cf-uppercase-id', $result->getMessageId());
    }

    /**
     * @return array<string,array{string,string}>
     */
    public static function invalidSuccessBodies(): array
    {
        return [
            'empty response'    => ['', 'Network error (HTTP 200)'],
            'non-json response' => ['<html>invalid response</html>', '<html>invalid response</html>'],
        ];
    }

    private function expectSuccessfulSend(array &$captured): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->with(Mockery::any(), Mockery::on(function (string $json) use (&$captured): bool {
            $captured = json_decode($json, true);

            return \is_array($captured);
        }))->andReturn(new ApiResponse(200, $this->successEnvelope('cf-message-1')));
    }

    private function provider(): CloudflareProvider
    {
        return new CloudflareProvider($this->apiClient, $this->resolver);
    }

    private function message(array $overrides = []): MailMessage
    {
        return MailMessage::fromArray(array_merge([
            'to'      => ['to@example.com'],
            'subject' => 'Subject',
            'body'    => 'Body text',
        ], $overrides));
    }

    private function connection(array $overrides = []): Connection
    {
        return Connection::fromArray(array_merge([
            'id'          => 'conn_1',
            'provider'    => 'cloudflare',
            'kind'        => 'api',
            'fromEmail'   => 'from@example.com',
            'settings'    => ['account_id' => '0123456789abcdef0123456789abcdef'],
            'credentials' => ['api_token' => ['source' => 'database', 'value' => self::API_TOKEN]],
        ], $overrides));
    }

    /**
     * @return array<string,mixed>
     */
    private function successEnvelope(string $messageId): array
    {
        return [
            'success'  => true,
            'errors'   => [],
            'messages' => [],
            'result'   => ['message_id' => $messageId],
        ];
    }

    private function createTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bit-smtp-cloudflare-attach-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
