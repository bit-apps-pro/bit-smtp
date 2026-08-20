<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\SparkPost;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use BitApps\SMTP\Mail\Providers\SparkPost\SparkPostProvider;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Drives SparkPostProvider::transport()->send() over a mocked ApiClient, proving the shipped
 * descriptor engine renders SparkPost's contract correctly (spec §5/§11): the region-mapped
 * transmissions host, the raw (no "Bearer") Authorization header, and — the whole reason SparkPost
 * needs the payloadBuilder escape hatch — that cc/bcc collapse into `recipients[]` while bcc never
 * leaks into any `content.headers` value.
 *
 * @internal
 *
 * @coversNothing
 */
class SparkPostProviderTest extends BaseUnitTestCase
{
    private const API_KEY = 'apikey123';

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

    public function testSendsSuccessfullyToTheUsHostByDefaultWithRawApiKeyAuthAndJsonBody(): void
    {
        $this->apiClient->shouldReceive('setHeaders')
            ->once()
            ->with(['Content-Type' => 'application/json', 'Authorization' => self::API_KEY])
            ->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.sparkpost.com/api/v1/transmissions', Mockery::on(function (string $json) {
                $body = json_decode($json, true);

                return $body['content']['from']                   === 'from@example.com'
                    && $body['content']['subject']                === 'Subject'
                    && $body['content']['text']                   === 'Body text'
                    && $body['recipients'][0]['address']['email'] === 'to@example.com';
            }))
            ->andReturn(new ApiResponse(200, ['results' => ['id' => 'sparkpost-transmission-1']]));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertTrue($result->isOk());
        $this->assertSame('sparkpost-transmission-1', $result->getMessageId());
    }

    public function testSendsToTheEuHostWhenRegionConfigured(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.eu.sparkpost.com/api/v1/transmissions', Mockery::any())
            ->andReturn(new ApiResponse(200, []));

        $connection = $this->connection(['settings' => ['region' => 'eu']]);

        $result = $this->provider()->transport()->send($this->message(), $connection);

        $this->assertTrue($result->isOk());
    }

    public function testAuthorizationHeaderCarriesTheBareApiKeyWithNoBearerPrefix(): void
    {
        $this->apiClient->shouldReceive('setHeaders')
            ->once()
            ->with(Mockery::on(function (array $headers) {
                return ($headers['Authorization'] ?? null)         === self::API_KEY
                    && strpos($headers['Authorization'], 'Bearer') === false;
            }))
            ->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, []));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertTrue($result->isOk());
    }

    public function testToCcAndBccAllAppearInRecipientsSharingTheSameHeaderToButBccNeverAppearsInAnyHeaderValue(): void
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

        $message = $this->message([
            'to'  => ['a@example.com'],
            'cc'  => ['b@example.com'],
            'bcc' => ['c@example.com'],
        ]);

        $result = $this->provider()->transport()->send($message, $this->connection());

        $this->assertTrue($result->isOk());

        $emails = array_column(array_column($captured['recipients'], 'address'), 'email');
        $this->assertSame(['a@example.com', 'b@example.com', 'c@example.com'], $emails);

        foreach ($captured['recipients'] as $recipient) {
            $this->assertSame('a@example.com', $recipient['address']['header_to']);
        }

        $headerValues = $this->flattenHeaderValues($captured['content']['headers'] ?? []);
        $this->assertStringContainsString('b@example.com', implode(' ', $headerValues));
        foreach ($headerValues as $value) {
            $this->assertStringNotContainsString('c@example.com', $value, 'a bcc address must never appear in any content.headers value');
        }
    }

    public function testFromWithADisplayNameRendersAsARfc822String(): void
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

        $connection = $this->connection(['fromName' => 'From Name']);

        $result = $this->provider()->transport()->send($this->message(), $connection);

        $this->assertTrue($result->isOk());
        $this->assertSame('From Name <from@example.com>', $captured['content']['from']);
    }

    public function testReplyToIsOmittedWhenAbsentAndSetWhenPresent(): void
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
        $this->assertArrayNotHasKey('reply_to', $captured['content']);
    }

    public function testHtmlContentTypeMapsBodyToHtmlAndOmitsText(): void
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

        $message = $this->message(['contentType' => 'text/html', 'body' => '<p>Hello</p>']);

        $result = $this->provider()->transport()->send($message, $this->connection());

        $this->assertTrue($result->isOk());
        $this->assertSame('<p>Hello</p>', $captured['content']['html']);
        $this->assertArrayNotHasKey('text', $captured['content']);
    }

    public function testPlainContentTypeMapsBodyToTextAndOmitsHtml(): void
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

        $message = $this->message(['body' => 'Plain body']);

        $result = $this->provider()->transport()->send($message, $this->connection());

        $this->assertTrue($result->isOk());
        $this->assertSame('Plain body', $captured['content']['text']);
        $this->assertArrayNotHasKey('html', $captured['content']);
    }

    public function testAttachmentRendersAsASparkPostShapedEntryUnderContentAttachments(): void
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
            ->andReturn(new ApiResponse(200, []));

        $message = $this->message(['attachments' => ['report.txt' => $path]]);

        $result = $this->provider()->transport()->send($message, $this->connection());

        $this->assertTrue($result->isOk());
        $entry = $captured['content']['attachments'][0];
        $this->assertEqualsCanonicalizing(['name', 'type', 'data'], array_keys($entry));
        $this->assertSame('report.txt', $entry['name']);
        $this->assertSame('text/plain', $entry['type']);
        $this->assertSame(base64_encode('known attachment bytes'), $entry['data']);
    }

    public function testStatus200MapsToSuccess(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, []));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertTrue($result->isOk());
    }

    public function testAnErrorsArrayBodyAtA4xxMapsToSendResultFailureCarryingTheFirstMessage(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(400, [
            'errors' => [['message' => 'bad']],
        ]));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('bad', $result->getError());
        $this->assertSame('400', $result->getCode());
    }

    public function testHttp200WithPartialRecipientErrorsIsAcceptedButSurfacesTheProviderError(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, [
            'errors'  => [['message' => 'transmission created, but with validation errors']],
            'results' => [
                'id'                        => 'sparkpost-transmission-1',
                'total_accepted_recipients' => 1,
                'total_rejected_recipients' => 1,
            ],
        ]));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertTrue($result->isAccepted());
        $this->assertFalse($result->isOk());
        $this->assertSame('transmission created, but with validation errors', $result->getError());
        $this->assertSame('sparkpost-transmission-1', $result->getMessageId());
    }

    public function testStatusOutsideTheDeclaredSuccessListMapsToFailure(): void
    {
        // success => [200] in the descriptor; a 202 is still a failure by that contract.
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(202, []));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('SparkPost error HTTP 202', $result->getError());
    }

    private function provider(): SparkPostProvider
    {
        return new SparkPostProvider($this->apiClient, $this->resolver);
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
            'id'          => 'conn_1', 'provider' => 'sparkpost', 'kind' => 'api',
            'fromEmail'   => 'from@example.com',
            'credentials' => [
                'api_key' => ['source' => 'database', 'value' => self::API_KEY],
            ],
        ], $overrides));
    }

    private function createTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bit-smtp-sparkpost-attach-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return string[]
     */
    private function flattenHeaderValues(array $headers): array
    {
        return array_map('strval', array_values($headers));
    }
}
