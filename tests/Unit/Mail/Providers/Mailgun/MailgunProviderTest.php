<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\Mailgun;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use BitApps\SMTP\Mail\Providers\Mailgun\MailgunProvider;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;

/**
 * Drives MailgunProvider::transport()->send() over a mocked ApiClient, proving the shipped
 * descriptor engine renders Mailgun's contract correctly (spec §5/§11): Basic auth with the
 * literal "api" user, the region-mapped host with an SSRF-validated {domain} path segment, and
 * the form/multipart auto-upgrade (FormMultipartEncoder) driven by whether the message carries
 * attachments.
 *
 * @internal
 *
 * @coversNothing
 */
class MailgunProviderTest extends BaseUnitTestCase
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

    public function testSendsSuccessfullyToTheUsHostByDefaultWithBasicAuthAndUrlencodedBody(): void
    {
        $expectedAuth = 'Basic ' . base64_encode('api:' . self::API_KEY);

        $this->apiClient->shouldReceive('setHeaders')
            ->once()
            ->with(['Content-Type' => 'application/x-www-form-urlencoded', 'Authorization' => $expectedAuth])
            ->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.mailgun.net/v3/mg.example.com/messages', Mockery::on(function (string $body) {
                parse_str($body, $fields);

                return $fields['from']    === 'from@example.com'
                    && $fields['to']      === 'to@example.com'
                    && $fields['subject'] === 'Subject'
                    && $fields['text']    === 'Body text';
            }))
            ->andReturn(new ApiResponse(200, []));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertTrue($result->isOk());
    }

    public function testSendsToTheEuHostWhenRegionConfigured(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.eu.mailgun.net/v3/mg.example.com/messages', Mockery::any())
            ->andReturn(new ApiResponse(200, []));

        $connection = $this->connection(['settings' => ['domain' => 'mg.example.com', 'region' => 'eu']]);

        $result = $this->provider()->transport()->send($this->message(), $connection);

        $this->assertTrue($result->isOk());
    }

    public function testAnInvalidDomainSettingFailsAndNeverPosts(): void
    {
        $this->apiClient->shouldNotReceive('post');

        $connection = $this->connection(['settings' => ['domain' => 'evil.com/inject']]);

        $result = $this->provider()->transport()->send($this->message(), $connection);

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('Invalid domain', $result->getError());
    }

    public function testAMessageWithAnAttachmentUpgradesToMultipartAndCarriesAFilePart(): void
    {
        $path = $this->createTempFile('known attachment bytes');

        $this->apiClient->shouldReceive('setHeaders')
            ->once()
            ->with(Mockery::on(function (array $headers) {
                return isset($headers['Content-Type'])
                    && strpos($headers['Content-Type'], 'multipart/form-data; boundary=') === 0;
            }))
            ->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $body) {
                return strpos($body, 'name="attachment"; filename="report.txt"') !== false
                    && strpos($body, 'known attachment bytes')                   !== false;
            }))
            ->andReturn(new ApiResponse(200, []));

        $message = $this->message(['attachments' => ['report.txt' => $path]]);

        $result = $this->provider()->transport()->send($message, $this->connection());

        $this->assertTrue($result->isOk());
    }

    public function testATextOnlyMessageWithNoAttachmentsStaysUrlencoded(): void
    {
        $this->apiClient->shouldReceive('setHeaders')
            ->once()
            ->with(Mockery::on(function (array $headers) {
                return ($headers['Content-Type'] ?? null) === 'application/x-www-form-urlencoded';
            }))
            ->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $body) {
                parse_str($body, $fields);

                return $fields['to'] === 'to@example.com' && $fields['from'] === 'from@example.com';
            }))
            ->andReturn(new ApiResponse(200, []));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertTrue($result->isOk());
    }

    public function testFromWithADisplayNameRendersAsARfc822String(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $body) {
                parse_str($body, $fields);

                return $fields['from'] === 'From Name <from@example.com>';
            }))
            ->andReturn(new ApiResponse(200, []));

        $connection = $this->connection(['fromName' => 'From Name']);

        $result = $this->provider()->transport()->send($this->message(), $connection);

        $this->assertTrue($result->isOk());
    }

    public function testMultipleToAddressesJoinIntoASingleCommaSeparatedFieldNotAnArray(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $body) {
                parse_str($body, $fields);

                return $fields['to'] === 'to@example.com, Second <second@example.com>'
                    && \is_string($fields['to']);
            }))
            ->andReturn(new ApiResponse(200, []));

        $message = $this->message(['to' => ['to@example.com', 'Second <second@example.com>']]);

        $result = $this->provider()->transport()->send($message, $this->connection());

        $this->assertTrue($result->isOk());
    }

    public function testReplyToMapsToTheHReplyToFormField(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $body) {
                parse_str($body, $fields);

                return $fields['h:Reply-To'] === 'Reply Name <reply@example.com>';
            }))
            ->andReturn(new ApiResponse(200, []));

        $message = $this->message(['replyTo' => 'Reply Name <reply@example.com>']);

        $result = $this->provider()->transport()->send($message, $this->connection());

        $this->assertTrue($result->isOk());
    }

    public function testNoReplyToOmitsTheHReplyToFormFieldEntirely(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $body) {
                parse_str($body, $fields);

                return !isset($fields['h:Reply-To']) && strpos($body, 'Reply-To') === false;
            }))
            ->andReturn(new ApiResponse(200, []));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertTrue($result->isOk());
    }

    public function testSubjectMapsToTheSubjectField(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $body) {
                parse_str($body, $fields);

                return $fields['subject'] === 'Hello';
            }))
            ->andReturn(new ApiResponse(200, []));

        $result = $this->provider()->transport()->send($this->message(['subject' => 'Hello']), $this->connection());

        $this->assertTrue($result->isOk());
    }

    public function testHtmlContentTypeMapsBodyToHtmlAndOmitsText(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $body) {
                parse_str($body, $fields);

                return $fields['html'] === '<p>Hello</p>' && !isset($fields['text']);
            }))
            ->andReturn(new ApiResponse(200, []));

        $message = $this->message(['contentType' => 'text/html', 'body' => '<p>Hello</p>']);

        $result = $this->provider()->transport()->send($message, $this->connection());

        $this->assertTrue($result->isOk());
    }

    public function testPlainContentTypeMapsBodyToTextAndOmitsHtml(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $body) {
                parse_str($body, $fields);

                return $fields['text'] === 'Plain body' && !isset($fields['html']);
            }))
            ->andReturn(new ApiResponse(200, []));

        $message = $this->message(['body' => 'Plain body']);

        $result = $this->provider()->transport()->send($message, $this->connection());

        $this->assertTrue($result->isOk());
    }

    public function testStatus200MapsToSuccess(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, []));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertTrue($result->isOk());
    }

    public function testAMessageErrorBodyAtA4xxMapsToSendResultFailureCarryingTheMessage(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(400, ['message' => 'bad']));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('bad', $result->getError());
        $this->assertSame('400', $result->getCode());
    }

    public function testStatusOutsideTheDeclaredSuccessListMapsToFailure(): void
    {
        // success => [200] in the descriptor; a 202 is still a failure by that contract.
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(202, []));

        $result = $this->provider()->transport()->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('Mailgun error HTTP 202', $result->getError());
    }

    private function provider(): MailgunProvider
    {
        return new MailgunProvider($this->apiClient, $this->resolver);
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
            'id'          => 'conn_1', 'provider' => 'mailgun', 'kind' => 'api',
            'fromEmail'   => 'from@example.com',
            'settings'    => ['domain' => 'mg.example.com'],
            'credentials' => [
                'api_key' => ['source' => 'database', 'value' => self::API_KEY],
            ],
        ], $overrides));
    }

    private function createTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bit-smtp-mailgun-attach-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
