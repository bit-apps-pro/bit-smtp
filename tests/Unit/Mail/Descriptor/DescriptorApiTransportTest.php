<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Descriptor;

use BitApps\SMTP\Mail\Auth\BearerTokenStrategy;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Descriptor\DescriptorApiTransport;
use BitApps\SMTP\Mail\Descriptor\ProviderDescriptor;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Support\AddressFormatter;
use BitApps\SMTP\Mail\Support\AttachmentBuilder;
use BitApps\SMTP\Mail\Support\FormEncoder;
use BitApps\SMTP\Mail\Support\JsonEncoder;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
class DescriptorApiTransportTest extends BaseUnitTestCase
{
    private ApiClient $apiClient;

    /**
     * @var string[]
     */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->apiClient = Mockery::mock(ApiClient::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    public function testSendPostsToRegionEndpointWithBearerAuthAndJsonBody(): void
    {
        $this->apiClient->shouldReceive('setHeaders')
            ->once()
            ->with(['Content-Type' => 'application/json', 'Authorization' => 'Bearer TESTTOKEN'])
            ->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.us.example.com/v1/send', Mockery::on(function (string $json) {
                $body = json_decode($json, true);

                return $body['to']      === [['email' => 'to@example.com']]
                    && $body['from']    === ['email' => 'from@example.com', 'name' => 'From Name']
                    && $body['subject'] === 'Subject'
                    && $body['text']    === 'Body text';
            }))
            ->andReturn(new ApiResponse(202, []));

        $result = $this->transport()->send($this->message(), $this->connection());

        $this->assertTrue($result->isOk());
    }

    public function testUnknownRegionYieldsFailureAndNeverPosts(): void
    {
        $this->apiClient->shouldNotReceive('post');

        $result = $this->transport()->send($this->message(), $this->connection(['settings' => ['region' => 'moon']]));

        $this->assertFalse($result->isOk());
    }

    public function testMissingRegionSettingFallsBackToTheDescriptorsDefaultRegion(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.us.example.com/v1/send', Mockery::any())
            ->andReturn(new ApiResponse(202, []));

        $result = $this->transportWith($this->descriptorWithDefaultRegion())
            ->send($this->message(), $this->connection(['settings' => []]));

        $this->assertTrue($result->isOk());
    }

    public function testAnExplicitRegionSettingOverridesTheDefaultRegion(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.eu.example.com/v1/send', Mockery::any())
            ->andReturn(new ApiResponse(202, []));

        $result = $this->transportWith($this->descriptorWithDefaultRegion())
            ->send($this->message(), $this->connection(['settings' => ['region' => 'eu']]));

        $this->assertTrue($result->isOk());
    }

    public function testAnUnknownRegionStillFailsWhenADefaultRegionIsDeclared(): void
    {
        $this->apiClient->shouldNotReceive('post');

        $result = $this->transportWith($this->descriptorWithDefaultRegion())
            ->send($this->message(), $this->connection(['settings' => ['region' => 'moon']]));

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('Unknown region', $result->getError());
    }

    public function testADefaultRegionAbsentFromTheHostMapStillFailsTheClosedMapGuard(): void
    {
        // defaultRegion is descriptor-controlled, but a misconfigured one must never bypass the SSRF guard.
        $this->apiClient->shouldNotReceive('post');

        $descriptor = ProviderDescriptor::fromArray($this->descriptorConfig([
            'endpoint' => [
                'hostByRegion'  => ['us' => 'api.us.example.com', 'eu' => 'api.eu.example.com'],
                'regionSetting' => 'region',
                'defaultRegion' => 'moon',
                'path'          => '/v1/send',
            ],
        ]));

        $result = $this->transportWith($descriptor)->send($this->message(), $this->connection(['settings' => []]));

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('Unknown region', $result->getError());
    }

    public function testSuccessStatusMapsToSuccessResult(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, []));

        $this->assertTrue($this->transport()->send($this->message(), $this->connection())->isOk());
    }

    public function testNonSuccessStatusMapsToFailure(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(201, []));

        $this->assertFalse($this->transport()->send($this->message(), $this->connection())->isOk());
    }

    public function testErrorBodyMapsToFailureViaErrorPaths(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(400, [
            'errors' => [['message' => 'Recipient rejected']],
        ]));

        $result = $this->transport()->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('Recipient rejected', $result->getError());
        $this->assertSame('400', $result->getCode());
    }

    public function testSuccessStatusWithProviderErrorBodyIsAFailureWhenTheProviderOptsIn(): void
    {
        // A provider that opts into 2xx-body error detection via errorDetectPaths: HTTP 200 with a
        // per-message error in the body must be treated as a failed send, not a false positive.
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, [
            'errors' => [['message' => 'Recipient rejected']],
        ]));

        $descriptor = ProviderDescriptor::fromArray($this->descriptorConfig([
            'errorDetectPaths' => ['errors.0.message', 'message'],
        ]));

        $result = $this->transportWith($descriptor)->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('Recipient rejected', $result->getError());
        $this->assertSame('200', $result->getCode());
    }

    public function testSuccessStatusWithProviderErrorBodyIsAcceptedButNotOkSoNoFallback(): void
    {
        // The 2xx-with-body-error case (Mailjet-style): the provider DID accept/hand off the
        // message, so the dispatch fallback must not re-send it to the next connection.
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, [
            'errors' => [['message' => 'Recipient rejected']],
        ]));

        $descriptor = ProviderDescriptor::fromArray($this->descriptorConfig([
            'errorDetectPaths' => ['errors.0.message', 'message'],
        ]));

        $result = $this->transportWith($descriptor)->send($this->message(), $this->connection());

        $this->assertTrue($result->isAccepted());
        $this->assertFalse($result->isOk());
    }

    public function testNonSuccessStatusIsNotAcceptedSoFallbackCanRun(): void
    {
        // A 4xx was never handed off by the provider, so the dispatch fallback must be allowed to
        // try the next connection.
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(400, [
            'errors' => [['message' => 'Recipient rejected']],
        ]));

        $result = $this->transport()->send($this->message(), $this->connection());

        $this->assertFalse($result->isAccepted());
        $this->assertFalse($result->isOk());
    }

    public function testCleanSuccessStatusIsAccepted(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, ['id' => 'msg_1']));

        $result = $this->transport()->send($this->message(), $this->connection());

        $this->assertTrue($result->isAccepted());
        $this->assertTrue($result->isOk());
    }

    public function testSuccessStatusWithBenignBodyKeyIsNotAFalseFailureWhenErrorDetectPathsIsEmpty(): void
    {
        // The default descriptor sets no errorDetectPaths (→ []), so a benign top-level `message`
        // (e.g. Mailgun's "Queued. Thank you.") on a 200 must NOT be mistaken for an error.
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, [
            'id'      => 'msg_1',
            'message' => 'Queued. Thank you.',
        ]));

        $this->assertTrue($this->transport()->send($this->message(), $this->connection())->isOk());
    }

    public function testSuccessStatusWithCleanBodyStillSucceeds(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, ['id' => 'msg_1']));

        $this->assertTrue($this->transport()->send($this->message(), $this->connection())->isOk());
    }

    public function testEnvelopeWrapsBodyUnderTheEnvelopeKey(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $json) {
                $body = json_decode($json, true);

                return array_keys($body)              === ['message']
                    && $body['message'][0]['subject'] === 'Subject'
                    && $body['message'][0]['to']      === [['email' => 'to@example.com']];
            }))
            ->andReturn(new ApiResponse(202, []));

        $descriptor = ProviderDescriptor::fromArray($this->descriptorConfig([
            'payload' => ['envelope' => 'message'] + $this->basePayload(),
        ]));

        $result = $this->transportWith($descriptor)->send($this->message(), $this->connection());

        $this->assertTrue($result->isOk());
    }

    public function testOmitsEmptyAddressAndAttachmentKeys(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $json) {
                $body = json_decode($json, true);

                return !isset($body['cc'], $body['bcc'], $body['reply_to'], $body['attachments'])
                    && isset($body['from'], $body['to']);
            }))
            ->andReturn(new ApiResponse(202, []));

        $result = $this->transport()->send($this->message(), $this->connection());

        $this->assertTrue($result->isOk());
    }

    public function testWiresAllFiveAddressSourcesWhenPresent(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $json) {
                $body = json_decode($json, true);

                return $body['from']     === ['email' => 'from@example.com', 'name' => 'From Name']
                    && $body['to']       === [['email' => 'to@example.com']]
                    && $body['cc']       === [['email' => 'cc@example.com']]
                    && $body['bcc']      === [['email' => 'bcc@example.com']]
                    && $body['reply_to'] === ['email' => 'reply@example.com'];
            }))
            ->andReturn(new ApiResponse(202, []));

        $message = $this->message([
            'cc' => ['cc@example.com'], 'bcc' => ['bcc@example.com'], 'replyTo' => 'reply@example.com',
        ]);

        $this->assertTrue($this->transport()->send($message, $this->connection())->isOk());
    }

    public function testHtmlContentTypeRoutesBodyToHtmlKey(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $json) {
                $body = json_decode($json, true);

                return isset($body['html']) && !isset($body['text']) && $body['html'] === '<p>Hi</p>';
            }))
            ->andReturn(new ApiResponse(202, []));

        $message = $this->message(['body' => '<p>Hi</p>', 'contentType' => 'text/html; charset=UTF-8']);

        $this->assertTrue($this->transport()->send($message, $this->connection())->isOk());
    }

    public function testSingleAddressUnwrapsToObjectAndJoinCollapsesArrayForFormBodies(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $body) {
                parse_str($body, $fields);

                return $fields['from'] === 'from@example.com'
                    && $fields['to']   === 'a@example.com, b@example.com';
            }))
            ->andReturn(new ApiResponse(202, []));

        $descriptor = ProviderDescriptor::fromArray($this->descriptorConfig([
            'encoder' => 'form',
            'payload' => [
                'addresses' => [
                    'from' => ['source' => 'from', 'shape' => AddressFormatter::SHAPE_RFC822, 'single' => true],
                    'to'   => ['source' => 'to', 'shape' => AddressFormatter::SHAPE_RFC822, 'join' => true],
                ],
                'subject' => 'subject',
                'body'    => ['html' => 'html', 'text' => 'text'],
            ],
        ]));

        $message = $this->message(['to' => ['a@example.com', 'b@example.com'], 'from' => 'from@example.com']);

        $result = $this->transportWith($descriptor, new FormEncoder())->send($message, $this->connection());

        $this->assertTrue($result->isOk());
    }

    public function testPayloadBuilderOverrideBypassesMap(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), json_encode(['custom' => 'shape']))
            ->andReturn(new ApiResponse(202, []));

        $descriptor = ProviderDescriptor::fromArray($this->descriptorConfig([
            'payloadBuilder' => static function (MailMessage $message, Connection $connection): array {
                return ['custom' => 'shape'];
            },
        ]));

        $this->assertTrue($this->transportWith($descriptor)->send($this->message(), $this->connection())->isOk());
    }

    public function testValidDomainIsInterpolatedIntoPath(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.mailer.example.com/v3/mg.example.com/messages', Mockery::any())
            ->andReturn(new ApiResponse(202, []));

        $descriptor = ProviderDescriptor::fromArray($this->descriptorConfig([
            'endpoint' => ['host' => 'api.mailer.example.com', 'path' => '/v3/{domain}/messages'],
        ]));

        $connection = $this->connection(['settings' => ['domain' => 'mg.example.com']]);

        $this->assertTrue($this->transportWith($descriptor)->send($this->message(), $connection)->isOk());
    }

    public function testInvalidDomainYieldsFailureAndNeverPosts(): void
    {
        $this->apiClient->shouldNotReceive('post');

        $descriptor = ProviderDescriptor::fromArray($this->descriptorConfig([
            'endpoint' => ['host' => 'api.mailer.example.com', 'path' => '/v3/{domain}/messages'],
        ]));

        $connection = $this->connection(['settings' => ['domain' => 'not a domain!']]);

        $this->assertFalse($this->transportWith($descriptor)->send($this->message(), $connection)->isOk());
    }

    public function testSingleSourceThatFormatsToBlankOmitsTheKey(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $json) {
                $body = json_decode($json, true);

                return !isset($body['to']) && $body['subject'] === 'Subject';
            }))
            ->andReturn(new ApiResponse(202, []));

        $descriptor = ProviderDescriptor::fromArray($this->descriptorConfig([
            'payload' => [
                'addresses' => ['to' => ['source' => 'to', 'shape' => AddressFormatter::SHAPE_OBJECT, 'single' => true]],
                'subject'   => 'subject',
            ],
        ]));

        // Whitespace-only recipient survives the raw-empty guard but the formatter drops it.
        $result = $this->transportWith($descriptor)->send($this->message(['to' => ['   ']]), $this->connection());

        $this->assertTrue($result->isOk());
    }

    public function testShapedAttachmentIsBuiltAndPlacedUnderTheAttachmentKey(): void
    {
        Functions\when('wp_check_filetype')->justReturn(['ext' => 'txt', 'type' => 'text/plain']);
        $path = $this->createTempFile('attachment bytes');

        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $json) {
                $body = json_decode($json, true);

                return $body['attachments'] === [[
                    'content'  => base64_encode('attachment bytes'),
                    'filename' => 'report.txt',
                    'type'     => 'text/plain',
                ]];
            }))
            ->andReturn(new ApiResponse(202, []));

        $message = $this->message(['attachments' => ['report.txt' => $path]]);

        $this->assertTrue($this->transport()->send($message, $this->connection())->isOk());
    }

    public function testFilesShapeAttachmentPassesTheRawFilenamePathMapThrough(): void
    {
        $path = $this->createTempFile('attachment bytes');

        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (string $json) use ($path) {
                $body = json_decode($json, true);

                return $body['attachments'] === ['report.txt' => $path];
            }))
            ->andReturn(new ApiResponse(202, []));

        $descriptor = ProviderDescriptor::fromArray($this->descriptorConfig([
            'payload' => ['attachments' => ['key' => 'attachments', 'shape' => 'files']] + $this->basePayload(),
        ]));

        $message = $this->message(['attachments' => ['report.txt' => $path]]);

        $this->assertTrue($this->transportWith($descriptor)->send($message, $this->connection())->isOk());
    }

    private function transport(): DescriptorApiTransport
    {
        return $this->transportWith(ProviderDescriptor::fromArray($this->descriptorConfig()));
    }

    private function transportWith(ProviderDescriptor $descriptor, ?object $encoder = null): DescriptorApiTransport
    {
        return new DescriptorApiTransport(
            $this->apiClient,
            $descriptor,
            new BearerTokenStrategy(['credentialKey' => 'api_key']),
            $encoder ?? new JsonEncoder()
        );
    }

    private function descriptorConfig(array $overrides = []): array
    {
        return array_merge([
            'key'      => 'synthetic',
            'label'    => 'Synthetic',
            'kind'     => 'api',
            'auth'     => ['type' => 'bearer', 'params' => ['credentialKey' => 'api_key']],
            'endpoint' => [
                'hostByRegion'  => ['us' => 'api.us.example.com', 'eu' => 'api.eu.example.com'],
                'regionSetting' => 'region',
                'path'          => '/v1/send',
            ],
            'encoder'    => 'json',
            'payload'    => $this->basePayload(),
            'success'    => [200, 202],
            'errorPaths' => ['errors.0.message', 'message'],
        ], $overrides);
    }

    private function descriptorWithDefaultRegion(): ProviderDescriptor
    {
        return ProviderDescriptor::fromArray($this->descriptorConfig([
            'endpoint' => [
                'hostByRegion'  => ['us' => 'api.us.example.com', 'eu' => 'api.eu.example.com'],
                'regionSetting' => 'region',
                'defaultRegion' => 'us',
                'path'          => '/v1/send',
            ],
        ]));
    }

    private function basePayload(): array
    {
        return [
            'addresses' => [
                'from'     => ['source' => 'from', 'shape' => AddressFormatter::SHAPE_OBJECT, 'single' => true],
                'to'       => ['source' => 'to', 'shape' => AddressFormatter::SHAPE_OBJECT],
                'cc'       => ['source' => 'cc', 'shape' => AddressFormatter::SHAPE_OBJECT],
                'bcc'      => ['source' => 'bcc', 'shape' => AddressFormatter::SHAPE_OBJECT],
                'reply_to' => ['source' => 'replyTo', 'shape' => AddressFormatter::SHAPE_OBJECT, 'single' => true],
            ],
            'subject'     => 'subject',
            'body'        => ['html' => 'html', 'text' => 'text'],
            'attachments' => ['key' => 'attachments', 'shape' => AttachmentBuilder::SHAPE_SENDGRID],
        ];
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
            'id'          => 'conn_1', 'provider' => 'synthetic', 'kind' => 'api',
            'fromEmail'   => 'from@example.com', 'fromName' => 'From Name',
            'settings'    => ['region' => 'us'],
            'credentials' => ['api_key' => ['source' => 'database', 'value' => 'TESTTOKEN']],
        ], $overrides));
    }

    private function createTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bit-smtp-descriptor-attach-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
