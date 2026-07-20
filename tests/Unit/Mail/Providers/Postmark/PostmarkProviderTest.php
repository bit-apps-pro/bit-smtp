<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\Postmark;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use BitApps\SMTP\Mail\Providers\Postmark\PostmarkProvider;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Drives PostmarkProvider::transport()->send() over a mocked ApiClient, proving the shipped
 * descriptor engine renders Postmark's contract correctly (spec §5/§11).
 *
 * @internal
 *
 * @coversNothing
 */
class PostmarkProviderTest extends BaseUnitTestCase
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

    public function testSendsSuccessfullyToThePostmarkEmailEndpointWithApiKeyHeaderAndJsonBody(): void
    {
        $this->apiClient->shouldReceive('setHeaders')
            ->once()
            ->with(['Content-Type' => 'application/json', 'X-Postmark-Server-Token' => 'server-token-123'])
            ->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.postmarkapp.com/email', Mockery::on(function (string $json) {
                $body = json_decode($json, true);

                return $body['From']     === 'from@example.com'
                    && $body['To']       === 'to@example.com'
                    && $body['Subject']  === 'Subject'
                    && $body['TextBody'] === 'Body text';
            }))
            ->andReturn(new ApiResponse(200, []));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertTrue($result->isOk());
    }

    public function testFromWithADisplayNameRendersAsRfc822(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $json) {
                $body = json_decode($json, true);

                return $body['From'] === 'From Name <from@example.com>';
            }))
            ->andReturn(new ApiResponse(200, []));

        $connection = $this->connection(['fromName' => 'From Name']);

        $result = $this->provider()->transport()->send($this->message(), $connection);

        $this->assertTrue($result->isOk());
    }

    public function testHtmlContentTypeMapsBodyToTheHtmlBodyKeyAndOmitsTextBody(): void
    {
        $captured = [];
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.postmarkapp.com/email', Mockery::on(function (string $json) use (&$captured) {
                $captured = json_decode($json, true);

                return true;
            }))
            ->andReturn(new ApiResponse(200, []));

        $message = $this->message(['contentType' => 'text/html', 'body' => '<p>Hello</p>']);

        $result = $this->provider()->transport()->send($message, $this->connection());

        $this->assertTrue($result->isOk());
        $this->assertSame('<p>Hello</p>', $captured['HtmlBody']);
        $this->assertArrayNotHasKey('TextBody', $captured);
    }

    public function testAttachmentRendersAsAPostmarkShapedEntryUnderTheAttachmentsKey(): void
    {
        Functions\when('wp_check_filetype')->justReturn(['ext' => 'txt', 'type' => 'text/plain']);
        $path = $this->createTempFile('known attachment bytes');

        $captured = [];
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.postmarkapp.com/email', Mockery::on(function (string $json) use (&$captured) {
                $captured = json_decode($json, true);

                return true;
            }))
            ->andReturn(new ApiResponse(200, []));

        $message = $this->message(['attachments' => ['report.txt' => $path]]);

        $result = $this->provider()->transport()->send($message, $this->connection());

        $this->assertTrue($result->isOk());
        $this->assertCount(1, $captured['Attachments']);
        $entry = $captured['Attachments'][0];
        $this->assertEqualsCanonicalizing(['Name', 'Content', 'ContentType'], array_keys($entry));
        $this->assertSame('report.txt', $entry['Name']);
        $this->assertSame(base64_encode('known attachment bytes'), $entry['Content']);
        $this->assertSame('text/plain', $entry['ContentType']);
    }

    public function testStatusOutsideTheDeclaredSuccessListMapsToFailure(): void
    {
        // success => [200] in the descriptor; a 2xx like 201 is still a failure by that contract.
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(201, []));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('Postmark error HTTP 201', $result->getError());
    }

    public function testErrorBodyAtA4xxMapsToSendResultFailureCarryingTheMessage(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(422, [
            'Message' => 'Invalid "From" address',
        ]));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('Invalid "From" address', $result->getError());
        $this->assertSame('422', $result->getCode());
    }

    private function provider(): PostmarkProvider
    {
        return new PostmarkProvider($this->apiClient, $this->resolver);
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
            'id'          => 'conn_1', 'provider' => 'postmark', 'kind' => 'api',
            'fromEmail'   => 'from@example.com',
            'credentials' => ['api_key' => ['source' => 'database', 'value' => 'server-token-123']],
        ], $overrides));
    }

    private function createTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bit-smtp-postmark-attach-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
