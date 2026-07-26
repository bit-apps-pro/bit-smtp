<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\Resend;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use BitApps\SMTP\Mail\Providers\Resend\ResendProvider;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Drives ResendProvider::transport()->send() over a mocked ApiClient, proving the shipped
 * descriptor engine renders Resend's contract correctly (spec §5/§11).
 *
 * @internal
 *
 * @coversNothing
 */
class ResendProviderTest extends BaseUnitTestCase
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

    public function testSendsSuccessfullyToTheResendEmailEndpointWithBearerAuthHeaderAndJsonBody(): void
    {
        $this->apiClient->shouldReceive('setHeaders')
            ->once()
            ->with(['Content-Type' => 'application/json', 'Authorization' => 'Bearer server-token-123'])
            ->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.resend.com/emails', Mockery::on(function (string $json) {
                $body = json_decode($json, true);

                return $body['from']    === 'from@example.com'
                    && $body['to']      === 'to@example.com'
                    && $body['subject'] === 'Subject'
                    && $body['text']    === 'Body text';
            }))
            ->andReturn(new ApiResponse(200, []));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertTrue($result->isOk());
    }

    public function testFromWithADisplayNameRendersAsASingleRfc822String(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $json) {
                $body = json_decode($json, true);

                return $body['from'] === 'From Name <from@example.com>';
            }))
            ->andReturn(new ApiResponse(200, []));

        $connection = $this->connection(['fromName' => 'From Name']);

        $result = $this->provider()->transport()->send($this->message(), $connection);

        $this->assertTrue($result->isOk());
    }

    public function testToWithOneRecipientRendersAsAStringAndWithTwoRendersAsAnArray(): void
    {
        $captured = [];
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $json) use (&$captured) {
                $captured = json_decode($json, true);

                return true;
            }))
            ->andReturn(new ApiResponse(200, []));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertTrue($result->isOk());
        // rfc822 without 'single': one recipient collapses to a bare string, not a one-element list.
        $this->assertSame('to@example.com', $captured['to']);
    }

    public function testToWithTwoRecipientsRendersAsAnArrayOfRfc822Strings(): void
    {
        $captured = [];
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $json) use (&$captured) {
                $captured = json_decode($json, true);

                return true;
            }))
            ->andReturn(new ApiResponse(200, []));

        $message = $this->message(['to' => ['to@example.com', 'Second <second@example.com>']]);

        $result = $this->provider()->transport()->send($message, $this->connection());

        $this->assertTrue($result->isOk());
        $this->assertSame(['to@example.com', 'Second <second@example.com>'], $captured['to']);
    }

    public function testHtmlContentTypeMapsBodyToTheHtmlKeyAndOmitsText(): void
    {
        $captured = [];
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.resend.com/emails', Mockery::on(function (string $json) use (&$captured) {
                $captured = json_decode($json, true);

                return true;
            }))
            ->andReturn(new ApiResponse(200, []));

        $message = $this->message(['contentType' => 'text/html', 'body' => '<p>Hello</p>']);

        $result = $this->provider()->transport()->send($message, $this->connection());

        $this->assertTrue($result->isOk());
        $this->assertSame('<p>Hello</p>', $captured['html']);
        $this->assertArrayNotHasKey('text', $captured);
    }

    public function testTrackingMetadataRendersAsTagsAndReturnsTheProviderMessageId(): void
    {
        $captured = [];
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.resend.com/emails', Mockery::on(function (string $json) use (&$captured) {
                $captured = json_decode($json, true);

                return true;
            }))
            ->andReturn(new ApiResponse(200, ['id' => 'resend-email-1']));

        $provider = $this->provider();
        $result   = $provider->transport()->send(
            $this->message(['metadata' => ['bit_tracking_id' => 'tracking-1']]),
            $this->connection()
        );

        $this->assertSame(
            [['name' => 'bit_tracking_id', 'value' => 'tracking-1']],
            $captured['tags']
        );
        $this->assertSame(
            ['channel' => 'metadata', 'key' => 'bit_tracking_id'],
            $provider->tracking()
        );
        $this->assertSame('resend-email-1', $result->getMessageId());
        $this->assertTrue($result->isOk());
    }

    public function testAttachmentRendersAsAResendShapedEntryUnderTheAttachmentsKey(): void
    {
        Functions\when('wp_check_filetype')->justReturn(['ext' => 'txt', 'type' => 'text/plain']);
        $path = $this->createTempFile('known attachment bytes');

        $captured = [];
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.resend.com/emails', Mockery::on(function (string $json) use (&$captured) {
                $captured = json_decode($json, true);

                return true;
            }))
            ->andReturn(new ApiResponse(200, []));

        $message = $this->message(['attachments' => ['report.txt' => $path]]);

        $result = $this->provider()->transport()->send($message, $this->connection());

        $this->assertTrue($result->isOk());
        $this->assertCount(1, $captured['attachments']);
        $entry = $captured['attachments'][0];
        $this->assertEqualsCanonicalizing(['filename', 'content'], array_keys($entry));
        $this->assertSame('report.txt', $entry['filename']);
        $this->assertSame(base64_encode('known attachment bytes'), $entry['content']);
    }

    public function testStatusOutsideTheDeclaredSuccessListMapsToFailure(): void
    {
        // success => [200] in the descriptor; a 2xx like 202 is still a failure by that contract.
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(202, []));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('Resend error HTTP 202', $result->getError());
    }

    public function testErrorBodyAtA4xxMapsToSendResultFailureCarryingTheMessage(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(422, [
            'message' => 'Invalid `from` field',
        ]));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('Invalid `from` field', $result->getError());
        $this->assertSame('422', $result->getCode());
    }

    private function provider(): ResendProvider
    {
        return new ResendProvider($this->apiClient, $this->resolver);
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
            'id'          => 'conn_1', 'provider' => 'resend', 'kind' => 'api',
            'fromEmail'   => 'from@example.com',
            'credentials' => ['api_key' => ['source' => 'database', 'value' => 'server-token-123']],
        ], $overrides));
    }

    private function createTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bit-smtp-resend-attach-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
