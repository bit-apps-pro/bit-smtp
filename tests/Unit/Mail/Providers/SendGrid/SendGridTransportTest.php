<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\SendGrid;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Providers\SendGrid\SendGridTransport;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
class SendGridTransportTest extends BaseUnitTestCase
{
    private ApiClient $apiClient;

    private SendGridTransport $transport;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiClient = Mockery::mock(ApiClient::class);
        $this->transport = new SendGridTransport($this->apiClient);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function testSendPostsToSendGridMailSendEndpoint(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://api.sendgrid.com/v3/mail/send', Mockery::any())
            ->andReturn(new ApiResponse(202, []));

        $this->transport->send($this->message(), $this->connection());
    }

    public function testSendUsesBearerApiKeyFromConnectionCredential(): void
    {
        $this->apiClient->shouldReceive('setHeaders')
            ->once()
            ->with(['Authorization' => 'Bearer SG.key123', 'Content-Type' => 'application/json'])
            ->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(202, []));

        $this->transport->send($this->message(), $this->connection());
    }

    public function testBuildBodyIncludesPersonalizationsFromSubjectAndContent(): void
    {
        $expectedBody = [
            'personalizations' => [
                ['to' => [['email' => 'to@example.com']]],
            ],
            'from'    => ['email' => 'from@example.com', 'name' => 'From Name'],
            'subject' => 'Subject',
            'content' => [
                ['type' => 'text/plain', 'value' => 'Body text'],
            ],
        ];

        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), $expectedBody)
            ->andReturn(new ApiResponse(202, []));

        $this->transport->send($this->message(), $this->connection());
    }

    public function testBuildBodyIncludesCcAndBccWhenPresent(): void
    {
        $message = MailMessage::fromArray([
            'to'      => ['to@example.com'], 'cc' => ['cc@example.com'], 'bcc' => ['bcc@example.com'],
            'subject' => 'Subject', 'body' => 'Body text',
        ]);

        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(static function (array $body) {
                return $body['personalizations'][0]['cc']  === [['email' => 'cc@example.com']]
                    && $body['personalizations'][0]['bcc'] === [['email' => 'bcc@example.com']];
            }))
            ->andReturn(new ApiResponse(202, []));

        $this->transport->send($message, $this->connection());
    }

    public function testBuildBodyOmitsCcAndBccWhenAbsent(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(static function (array $body) {
                return !isset($body['personalizations'][0]['cc']) && !isset($body['personalizations'][0]['bcc']);
            }))
            ->andReturn(new ApiResponse(202, []));

        $this->transport->send($this->message(), $this->connection());
    }

    public function testBuildBodyMessageFromOverridesConnectionFrom(): void
    {
        $message = MailMessage::fromArray([
            'to'   => ['to@example.com'], 'subject' => 'Subject', 'body' => 'Body text',
            'from' => 'override@example.com', 'fromName' => 'Override Name',
        ]);

        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(static function (array $body) {
                return $body['from'] === ['email' => 'override@example.com', 'name' => 'Override Name'];
            }))
            ->andReturn(new ApiResponse(202, []));

        $this->transport->send($message, $this->connection());
    }

    public function testBuildBodyOmitsReplyToWhenNeitherMessageNorConnectionHasIt(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(static function (array $body) {
                return !isset($body['reply_to']);
            }))
            ->andReturn(new ApiResponse(202, []));

        $this->transport->send($this->message(), $this->connection());
    }

    public function testBuildBodyIncludesReplyToFromMessageWhenSet(): void
    {
        $message = MailMessage::fromArray([
            'to'      => ['to@example.com'], 'subject' => 'Subject', 'body' => 'Body text',
            'replyTo' => 'reply@example.com',
        ]);

        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(static function (array $body) {
                return $body['reply_to'] === ['email' => 'reply@example.com'];
            }))
            ->andReturn(new ApiResponse(202, []));

        $this->transport->send($message, $this->connection());
    }

    public function testBuildBodyIncludesReplyToFromConnectionWhenMessageReplyToAbsent(): void
    {
        $connection = $this->connection(['replyToEmail' => 'conn-reply@example.com']);

        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(static function (array $body) {
                return $body['reply_to'] === ['email' => 'conn-reply@example.com'];
            }))
            ->andReturn(new ApiResponse(202, []));

        $this->transport->send($this->message(), $connection);
    }

    public function testBuildBodyOmitsAttachmentsKeyWhenNoAttachments(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(static function (array $body) {
                return !isset($body['attachments']);
            }))
            ->andReturn(new ApiResponse(202, []));

        $this->transport->send($this->message(), $this->connection());
    }

    public function testBuildBodyIncludesBase64EncodedAttachmentWithFilenameAndType(): void
    {
        $path    = $this->createTempFile('hello.txt', 'hello world');
        $message = MailMessage::fromArray([
            'to'          => ['to@example.com'], 'subject' => 'Subject', 'body' => 'Body text',
            'attachments' => ['report.txt' => $path],
        ]);

        $expectedAttachment = [
            'content'  => base64_encode('hello world'),
            'filename' => 'report.txt',
            'type'     => 'text/plain',
        ];

        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(static function (array $body) use ($expectedAttachment) {
                return $body['attachments'] === [$expectedAttachment];
            }))
            ->andReturn(new ApiResponse(202, []));

        $this->transport->send($message, $this->connection());
    }

    public function testBuildBodyDerivesAttachmentFilenameFromPathWhenKeyIsNumeric(): void
    {
        $path    = $this->createTempFile('note.pdf', 'pdf-bytes');
        $message = MailMessage::fromArray([
            'to'          => ['to@example.com'], 'subject' => 'Subject', 'body' => 'Body text',
            'attachments' => [$path],
        ]);

        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(static function (array $body) use ($path) {
                return $body['attachments'][0]['filename'] === basename($path)
                    && $body['attachments'][0]['type']     === 'application/pdf';
            }))
            ->andReturn(new ApiResponse(202, []));

        $this->transport->send($message, $this->connection());
    }

    public function testSendReturnsFailureResultWhenAttachmentFileIsUnreadable(): void
    {
        $message = MailMessage::fromArray([
            'to'          => ['to@example.com'], 'subject' => 'Subject', 'body' => 'Body text',
            'attachments' => ['missing.txt' => '/nonexistent/path/missing.txt'],
        ]);

        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldNotReceive('post');

        $result = $this->transport->send($message, $this->connection());

        $this->assertFalse($result->isOk());
    }

    public function testSuccessFromReturnsTrueOnlyForStatus202(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, []));

        $result = $this->transport->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
    }

    public function testErrorFromParsesFirstErrorMessageFromErrorsArray(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(400, [
            'errors' => [['message' => 'The from address does not match a verified Sender Identity.']],
        ]));

        $result = $this->transport->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('The from address does not match a verified Sender Identity.', $result->getError());
        $this->assertSame('400', $result->getCode());
    }

    public function testErrorFromFallsBackToGenericMessageWhenErrorsArrayMissing(): void
    {
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(500, 'Internal Server Error'));

        $result = $this->transport->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('SendGrid error HTTP 500', $result->getError());
    }

    private function message(): MailMessage
    {
        return MailMessage::fromArray([
            'to' => ['to@example.com'], 'subject' => 'Subject', 'body' => 'Body text',
        ]);
    }

    private function connection(array $overrides = []): Connection
    {
        return Connection::fromArray(array_merge([
            'id'          => 'conn_1', 'provider' => 'sendgrid', 'kind' => 'api',
            'fromEmail'   => 'from@example.com', 'fromName' => 'From Name',
            'credentials' => ['api_key' => ['source' => 'database', 'value' => 'SG.key123']],
        ], $overrides));
    }

    private function createTempFile(string $name, string $contents): string
    {
        $path = sys_get_temp_dir() . '/' . uniqid('sgtest_', true) . '_' . $name;
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
