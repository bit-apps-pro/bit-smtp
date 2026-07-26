<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\Mailjet;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use BitApps\SMTP\Mail\Providers\Mailjet\MailjetProvider;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Drives MailjetProvider::transport()->send() over a mocked ApiClient, proving the shipped
 * descriptor engine renders Mailjet's contract correctly (spec §5/§11): basic auth, the
 * {"Messages":[...]} envelope, and capitalized {Email,Name} address objects.
 *
 * @internal
 *
 * @coversNothing
 */
class MailjetProviderTest extends BaseUnitTestCase
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

    public function testSendsSuccessfullyToTheMailjetSendEndpointWithBasicAuthHeaderAndJsonBody(): void
    {
        $expectedAuth = 'Basic ' . base64_encode('apikey123:secretkey456');

        $this->apiClient->shouldReceive('setHeaders')
            ->once()
            ->with(['Content-Type' => 'application/json', 'Authorization' => $expectedAuth])
            ->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.mailjet.com/v3.1/send', Mockery::on(function (string $json) {
                $body = json_decode($json, true);

                return $body['Messages'][0]['From']     === ['Email' => 'from@example.com']
                    && $body['Messages'][0]['To']       === [['Email' => 'to@example.com']]
                    && $body['Messages'][0]['Subject']  === 'Subject'
                    && $body['Messages'][0]['TextPart'] === 'Body text';
            }))
            ->andReturn(new ApiResponse(200, []));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertTrue($result->isOk());
    }

    public function testTheRequestBodyIsWrappedInTheMessagesEnvelope(): void
    {
        $captured = null;
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
        $this->assertIsArray($captured);
        $this->assertArrayHasKey('Messages', $captured);
        $this->assertCount(1, $captured['Messages']);
        $this->assertIsArray($captured['Messages'][0]);
    }

    public function testTrackingUsesTheDedicatedCustomIdProperty(): void
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

        $message = $this->message(['metadata' => ['bit_tracking_id' => 'tracking-1']]);
        $result  = $this->provider()->transport()->send($message, $this->connection());

        $this->assertTrue($result->isOk());
        $this->assertSame('tracking-1', $captured['Messages'][0]['CustomID']);
        $this->assertArrayNotHasKey('CustomCampaign', $captured['Messages'][0]);
        $this->assertArrayNotHasKey('Headers', $captured['Messages'][0]);
    }

    public function testTrackingStampsInternalMetadataForCustomIdMapping(): void
    {
        $this->assertSame(
            ['channel' => 'metadata', 'key' => 'bit_tracking_id'],
            $this->provider()->tracking()
        );
    }

    public function testFromWithADisplayNameRendersAsASingleCapitalizedObject(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $json) {
                $body = json_decode($json, true);

                return $body['Messages'][0]['From'] === ['Email' => 'from@example.com', 'Name' => 'From Name'];
            }))
            ->andReturn(new ApiResponse(200, []));

        $connection = $this->connection(['fromName' => 'From Name']);

        $result = $this->provider()->transport()->send($this->message(), $connection);

        $this->assertTrue($result->isOk());
    }

    public function testToRendersAsAListOfCapitalizedObjectsWithAndWithoutNames(): void
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
        $this->assertSame(
            [['Email' => 'to@example.com'], ['Email' => 'second@example.com', 'Name' => 'Second']],
            $captured['Messages'][0]['To']
        );
    }

    public function testHtmlContentTypeMapsBodyToTheHtmlPartKeyAndOmitsTextPart(): void
    {
        $captured = [];
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.mailjet.com/v3.1/send', Mockery::on(function (string $json) use (&$captured) {
                $captured = json_decode($json, true);

                return true;
            }))
            ->andReturn(new ApiResponse(200, []));

        $message = $this->message(['contentType' => 'text/html', 'body' => '<p>Hello</p>']);

        $result = $this->provider()->transport()->send($message, $this->connection());

        $this->assertTrue($result->isOk());
        $this->assertSame('<p>Hello</p>', $captured['Messages'][0]['HTMLPart']);
        $this->assertArrayNotHasKey('TextPart', $captured['Messages'][0]);
    }

    public function testAttachmentRendersAsAMailjetShapedEntryUnderTheAttachmentsKey(): void
    {
        Functions\when('wp_check_filetype')->justReturn(['ext' => 'txt', 'type' => 'text/plain']);
        $path = $this->createTempFile('known attachment bytes');

        $captured = [];
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.mailjet.com/v3.1/send', Mockery::on(function (string $json) use (&$captured) {
                $captured = json_decode($json, true);

                return true;
            }))
            ->andReturn(new ApiResponse(200, []));

        $message = $this->message(['attachments' => ['report.txt' => $path]]);

        $result = $this->provider()->transport()->send($message, $this->connection());

        $this->assertTrue($result->isOk());
        $entry = $captured['Messages'][0]['Attachments'][0];
        $this->assertEqualsCanonicalizing(['ContentType', 'Filename', 'Base64Content'], array_keys($entry));
        $this->assertSame('report.txt', $entry['Filename']);
        $this->assertSame('text/plain', $entry['ContentType']);
        $this->assertSame(base64_encode('known attachment bytes'), $entry['Base64Content']);
    }

    public function testStatusOutsideTheDeclaredSuccessListMapsToFailure(): void
    {
        // success => [200] in the descriptor; a 201 is still a failure by that contract.
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(201, []));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('Mailjet error HTTP 201', $result->getError());
    }

    public function testNestedMessagesErrorBodyAtA4xxMapsToSendResultFailureCarryingTheFirstErrorPath(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(400, [
            'Messages' => [
                ['Errors' => [['ErrorMessage' => 'bad']]],
            ],
        ]));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('bad', $result->getError());
        $this->assertSame('400', $result->getCode());
    }

    public function testFlatErrorMessageBodyMapsToSendResultFailureCarryingTheThirdFallbackPath(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(500, [
            'ErrorMessage' => 'top',
        ]));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('top', $result->getError());
        $this->assertSame('500', $result->getCode());
    }

    public function testA200WithANestedMessagesErrorBodyMapsToFailure(): void
    {
        // Mailjet answers a per-message failure with HTTP 200, so its errorDetectPaths must catch it.
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, [
            'Messages' => [['Errors' => [['ErrorMessage' => 'bad']]]],
        ]));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('bad', $result->getError());
        $this->assertSame('200', $result->getCode());
    }

    public function testA200WithACleanMessagesBodyMapsToSuccess(): void
    {
        // Mailjet's success 200 body has no Errors/ErrorMessage, so errorDetectPaths must miss.
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, [
            'Messages' => [['Status' => 'success']],
        ]));

        $this->assertTrue($this->provider()->transport()->send($this->message(), $this->connection())->isOk());
    }

    private function provider(): MailjetProvider
    {
        return new MailjetProvider($this->apiClient, $this->resolver);
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
            'id'          => 'conn_1', 'provider' => 'mailjet', 'kind' => 'api',
            'fromEmail'   => 'from@example.com',
            'credentials' => [
                'api_key'    => ['source' => 'database', 'value' => 'apikey123'],
                'secret_key' => ['source' => 'database', 'value' => 'secretkey456'],
            ],
        ], $overrides));
    }

    private function createTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bit-smtp-mailjet-attach-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
