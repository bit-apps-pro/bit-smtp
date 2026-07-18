<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\AmazonSes;

use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\MimeBuilder;
use BitApps\SMTP\Mail\Providers\AmazonSes\SesTransport;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
class SesTransportTest extends BaseUnitTestCase
{
    private ApiClient $apiClient;

    private MimeBuilder $mimeBuilder;

    private SesTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiClient   = Mockery::mock(ApiClient::class);
        $this->mimeBuilder = Mockery::mock(MimeBuilder::class);
        $this->transport   = new SesTransport($this->apiClient, new SigV4Signer(), $this->mimeBuilder);
    }

    public function testSendPostsToTheRegionSpecificOutboundEmailsEndpoint(): void
    {
        $this->mimeBuilder->shouldReceive('fromMailMessage')->once()->andReturn('raw-mime');

        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://email.eu-west-1.amazonaws.com/v2/email/outbound-emails', Mockery::any())
            ->andReturn(new ApiResponse(200, []));

        $this->transport->send($this->message(), $this->connection(['settings' => ['region' => 'eu-west-1', 'access_key' => 'AKIDEXAMPLE']]));
    }

    public function testSendReturnsFailureAndNeverCallsApiClientForMalformedRegionWithHostInjectionCharacters(): void
    {
        $this->mimeBuilder->shouldNotReceive('fromMailMessage');
        $this->apiClient->shouldNotReceive('setHeaders');
        $this->apiClient->shouldNotReceive('post');

        $result = $this->transport->send($this->message(), $this->connection(['settings' => ['region' => 'evil.com#', 'access_key' => 'AKIDEXAMPLE']]));

        $this->assertFalse($result->isOk());
        $this->assertSame('Invalid AWS SES region.', $result->getError());
    }

    public function testSendReturnsFailureAndNeverCallsApiClientForMalformedRegionWithPathTraversalCharacters(): void
    {
        $this->mimeBuilder->shouldNotReceive('fromMailMessage');
        $this->apiClient->shouldNotReceive('setHeaders');
        $this->apiClient->shouldNotReceive('post');

        $result = $this->transport->send($this->message(), $this->connection(['settings' => ['region' => 'a.b/', 'access_key' => 'AKIDEXAMPLE']]));

        $this->assertFalse($result->isOk());
        $this->assertSame('Invalid AWS SES region.', $result->getError());
    }

    public function testSendReturnsFailureAndNeverCallsApiClientForRegionWithTrailingNewline(): void
    {
        // A bare `$` anchor matches before a trailing newline; the region must still be rejected
        // so "us-east-1\n" can never reach sprintf into the request host.
        $this->mimeBuilder->shouldNotReceive('fromMailMessage');
        $this->apiClient->shouldNotReceive('setHeaders');
        $this->apiClient->shouldNotReceive('post');

        $result = $this->transport->send($this->message(), $this->connection(['settings' => ['region' => "us-east-1\n", 'access_key' => 'AKIDEXAMPLE']]));

        $this->assertFalse($result->isOk());
        $this->assertSame('Invalid AWS SES region.', $result->getError());
    }

    public function testSendBuildsCorrectEndpointAndSucceedsForValidRegion(): void
    {
        $this->mimeBuilder->shouldReceive('fromMailMessage')->once()->andReturn('raw-mime');

        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://email.us-east-1.amazonaws.com/v2/email/outbound-emails', Mockery::any())
            ->andReturn(new ApiResponse(200, []));

        $result = $this->transport->send($this->message(), $this->connection(['settings' => ['region' => 'us-east-1', 'access_key' => 'AKIDEXAMPLE']]));

        $this->assertTrue($result->isOk());
    }

    public function testSendBuildsCorrectEndpointAndSucceedsForFourSegmentGovCloudRegion(): void
    {
        $this->mimeBuilder->shouldReceive('fromMailMessage')->once()->andReturn('raw-mime');

        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://email.us-gov-east-1.amazonaws.com/v2/email/outbound-emails', Mockery::any())
            ->andReturn(new ApiResponse(200, []));

        $result = $this->transport->send($this->message(), $this->connection(['settings' => ['region' => 'us-gov-east-1', 'access_key' => 'AKIDEXAMPLE']]));

        $this->assertTrue($result->isOk());
    }

    public function testBuildBodyEncodesMimeMessageAsBase64RawData(): void
    {
        $mime = "Subject: Hi\r\n\r\nBody";

        $this->mimeBuilder->shouldReceive('fromMailMessage')->once()->andReturn($mime);

        $expectedBody = json_encode([
            'Content' => [
                'Raw' => [
                    'Data' => base64_encode($mime),
                ],
            ],
        ]);

        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), $expectedBody)
            ->andReturn(new ApiResponse(200, []));

        $this->transport->send($this->message(), $this->connection());
    }

    public function testBuildBodyPassesMailMessageToMimeBuilder(): void
    {
        $message = $this->message();

        $this->mimeBuilder->shouldReceive('fromMailMessage')
            ->once()
            ->with($message)
            ->andReturn('raw-mime');

        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, []));

        $this->transport->send($message, $this->connection());
    }

    public function testSendSignsRequestWithAws4Hmac256AuthorizationForSesService(): void
    {
        $this->mimeBuilder->shouldReceive('fromMailMessage')->once()->andReturn('raw-mime');

        $captured = null;
        $this->apiClient->shouldReceive('setHeaders')
            ->once()
            ->with(Mockery::on(static function (array $headers) use (&$captured) {
                $captured = $headers;

                return true;
            }))
            ->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, []));

        $this->transport->send($this->message(), $this->connection(['settings' => ['region' => 'us-east-1', 'access_key' => 'AKIDEXAMPLE']]));

        $this->assertMatchesRegularExpression(
            '/^AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE\/\d{8}\/us-east-1\/ses\/aws4_request, /',
            $captured['Authorization']
        );
        $this->assertSame('email.us-east-1.amazonaws.com', $captured['Host']);
    }

    public function testSendSignsContentHashMatchingThePostedBody(): void
    {
        $this->mimeBuilder->shouldReceive('fromMailMessage')->once()->andReturn('raw-mime');

        $capturedHeaders = null;
        $capturedBody    = null;
        $this->apiClient->shouldReceive('setHeaders')
            ->once()
            ->with(Mockery::on(static function (array $headers) use (&$capturedHeaders) {
                $capturedHeaders = $headers;

                return true;
            }))
            ->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), Mockery::on(static function ($body) use (&$capturedBody) {
                $capturedBody = $body;

                return true;
            }))
            ->andReturn(new ApiResponse(200, []));

        $this->transport->send($this->message(), $this->connection());

        $this->assertSame(hash('sha256', $capturedBody), $capturedHeaders['X-Amz-Content-Sha256']);
    }

    public function testSendReturnsSuccessResultOnStatus200(): void
    {
        $this->mimeBuilder->shouldReceive('fromMailMessage')->once()->andReturn('raw-mime');
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, ['MessageId' => 'abc123']));

        $result = $this->transport->send($this->message(), $this->connection());

        $this->assertTrue($result->isOk());
    }

    public function testSuccessFromReturnsTrueOnlyForStatus200(): void
    {
        $this->mimeBuilder->shouldReceive('fromMailMessage')->once()->andReturn('raw-mime');
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(202, []));

        $result = $this->transport->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
    }

    public function testErrorFromParsesLowercaseMessageKey(): void
    {
        $this->mimeBuilder->shouldReceive('fromMailMessage')->once()->andReturn('raw-mime');
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(400, ['message' => 'Email address is not verified.']));

        $result = $this->transport->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('Email address is not verified.', $result->getError());
        $this->assertSame('400', $result->getCode());
    }

    public function testErrorFromParsesUppercaseMessageKey(): void
    {
        $this->mimeBuilder->shouldReceive('fromMailMessage')->once()->andReturn('raw-mime');
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(403, ['Message' => 'Access denied.']));

        $result = $this->transport->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('Access denied.', $result->getError());
    }

    public function testErrorFromFallsBackToGenericMessageWhenBodyHasNoRecognizedKey(): void
    {
        $this->mimeBuilder->shouldReceive('fromMailMessage')->once()->andReturn('raw-mime');
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(500, 'Internal Server Error'));

        $result = $this->transport->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('SES error HTTP 500', $result->getError());
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
            'id'          => 'conn_1', 'provider' => 'amazon_ses', 'kind' => 'api',
            'settings'    => ['region' => 'us-east-1', 'access_key' => 'AKIDEXAMPLE'],
            'credentials' => ['secret_key' => ['source' => 'database', 'value' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY']],
        ], $overrides));
    }
}
