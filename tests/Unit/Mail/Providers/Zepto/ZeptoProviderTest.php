<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\Zepto;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use BitApps\SMTP\Mail\Providers\Zepto\ZeptoProvider;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Drives ZeptoProvider::transport()->send() over a mocked ApiClient, proving the shipped
 * descriptor engine renders ZeptoMail's contract correctly (spec §5/§11): the region-mapped
 * data-center host (with a 'us' engine default), the "Zoho-enczapikey" api-key header, and
 * the unwrapped {address,name} address shape.
 *
 * @internal
 *
 * @coversNothing
 */
class ZeptoProviderTest extends BaseUnitTestCase
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

    public function testSendsSuccessfullyToTheUsDataCenterWhenNoneIsConfiguredProvingTheEngineDefaultRegion(): void
    {
        $this->apiClient->shouldReceive('setHeaders')
            ->once()
            ->with(['Content-Type' => 'application/json', 'Authorization' => 'Zoho-enczapikey apikey123'])
            ->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.zeptomail.com/v1.1/email', Mockery::any())
            ->andReturn(new ApiResponse(201, []));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertTrue($result->isOk());
    }

    public function testSendsTrackingMetadataAsDocumentedClientReference(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(static function (string $json): bool {
                $body = json_decode($json, true);

                return ($body['client_reference'] ?? null) === 'tracking-1';
            }))
            ->andReturn(new ApiResponse(201, []));

        $result = $this->provider()->transport()->send(
            $this->message(['metadata' => ['bit_tracking_id' => 'tracking-1']]),
            $this->connection()
        );

        $this->assertTrue($result->isOk());
    }

    public function testSendsToTheEuDataCenterHostWhenConfigured(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.zeptomail.eu/v1.1/email', Mockery::any())
            ->andReturn(new ApiResponse(201, []));

        $connection = $this->connection(['settings' => ['data_center' => 'eu']]);

        $result = $this->provider()->transport()->send($this->message(), $connection);

        $this->assertTrue($result->isOk());
    }

    public function testAnUnknownDataCenterFailsAndNeverPosts(): void
    {
        $this->apiClient->shouldNotReceive('post');

        $connection = $this->connection(['settings' => ['data_center' => 'xx']]);

        $result = $this->provider()->transport()->send($this->message(), $connection);

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('Unknown region', $result->getError());
    }

    public function testFromWithADisplayNameRendersAsASingleUnwrappedAddressObject(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $json) {
                $body = json_decode($json, true);

                return $body['from'] === ['address' => 'from@example.com', 'name' => 'From Name'];
            }))
            ->andReturn(new ApiResponse(201, []));

        $connection = $this->connection(['fromName' => 'From Name']);

        $result = $this->provider()->transport()->send($this->message(), $connection);

        $this->assertTrue($result->isOk());
    }

    public function testToRendersAsANestedEmailAddressListWithAndWithoutNames(): void
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
        $this->assertSame(
            [
                ['email_address' => ['address' => 'to@example.com']],
                ['email_address' => ['address' => 'second@example.com', 'name' => 'Second']],
            ],
            $captured['to']
        );
    }

    public function testReplyToRendersAsAListOfUnwrappedAddressObjects(): void
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
        $this->assertSame([['address' => 'reply@example.com']], $captured['reply_to']);
    }

    public function testSubjectMapsToTheSubjectKey(): void
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

        $result = $this->provider()->transport()->send($this->message(['subject' => 'Hello']), $this->connection());

        $this->assertTrue($result->isOk());
        $this->assertSame('Hello', $captured['subject']);
    }

    public function testHtmlContentTypeMapsBodyToHtmlbodyAndOmitsTextbody(): void
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

        $message = $this->message(['contentType' => 'text/html', 'body' => '<p>Hello</p>']);

        $result = $this->provider()->transport()->send($message, $this->connection());

        $this->assertTrue($result->isOk());
        $this->assertSame('<p>Hello</p>', $captured['htmlbody']);
        $this->assertArrayNotHasKey('textbody', $captured);
    }

    public function testPlainContentTypeMapsBodyToTextbody(): void
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

        $message = $this->message(['body' => 'Plain body']);

        $result = $this->provider()->transport()->send($message, $this->connection());

        $this->assertTrue($result->isOk());
        $this->assertSame('Plain body', $captured['textbody']);
        $this->assertArrayNotHasKey('htmlbody', $captured);
    }

    public function testAttachmentRendersAsAZeptoShapedEntryUnderTheAttachmentsKey(): void
    {
        Functions\when('wp_check_filetype')->justReturn(['ext' => 'txt', 'type' => 'text/plain']);
        $path = $this->createTempFile('known attachment bytes');

        $captured = [];
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $json) use (&$captured) {
                $captured = json_decode($json, true);

                return true;
            }))
            ->andReturn(new ApiResponse(201, []));

        $message = $this->message(['attachments' => ['report.txt' => $path]]);

        $result = $this->provider()->transport()->send($message, $this->connection());

        $this->assertTrue($result->isOk());
        $entry = $captured['attachments'][0];
        $this->assertEqualsCanonicalizing(['content', 'name', 'mime_type'], array_keys($entry));
        $this->assertSame('report.txt', $entry['name']);
        $this->assertSame('text/plain', $entry['mime_type']);
        $this->assertSame(base64_encode('known attachment bytes'), $entry['content']);
    }

    public function testStatusOutsideTheDeclaredSuccessListMapsToFailure(): void
    {
        // success => [201] in the descriptor; a 200 is still a failure by that contract.
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, []));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('ZeptoMail error HTTP 200', $result->getError());
    }

    public function testNestedErrorDetailsBodyAtA4xxMapsToSendResultFailureCarryingTheFirstErrorPath(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(422, [
            'error' => ['details' => [['message' => 'bad']]],
        ]));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('bad', $result->getError());
        $this->assertSame('422', $result->getCode());
    }

    public function testFlatErrorMessageBodyMapsToSendResultFailureCarryingTheSecondFallbackPath(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(500, [
            'error' => ['message' => 'top'],
        ]));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('top', $result->getError());
        $this->assertSame('500', $result->getCode());
    }

    private function provider(): ZeptoProvider
    {
        return new ZeptoProvider($this->apiClient, $this->resolver);
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
            'id'          => 'conn_1', 'provider' => 'zeptomail', 'kind' => 'api',
            'fromEmail'   => 'from@example.com',
            'credentials' => [
                'api_key' => ['source' => 'database', 'value' => 'apikey123'],
            ],
        ], $overrides));
    }

    private function createTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bit-smtp-zepto-attach-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
