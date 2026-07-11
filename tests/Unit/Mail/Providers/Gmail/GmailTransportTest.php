<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\Gmail;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\MimeBuilder;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use BitApps\SMTP\Mail\Providers\Gmail\GmailTransport;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
class GmailTransportTest extends BaseUnitTestCase
{
    private ApiClient $apiClient;

    private OAuth2TokenProvider $tokenProvider;

    private MimeBuilder $mimeBuilder;

    private GmailTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiClient     = Mockery::mock(ApiClient::class);
        $this->tokenProvider = Mockery::mock(OAuth2TokenProvider::class);
        $this->mimeBuilder   = Mockery::mock(MimeBuilder::class);
        $this->transport     = new GmailTransport($this->apiClient, $this->tokenProvider, $this->mimeBuilder);
    }

    public function testAuthUrlReturnsGoogleAuthEndpoint(): void
    {
        $this->assertSame('https://accounts.google.com/o/oauth2/v2/auth', $this->transport->authUrl());
    }

    public function testTokenUrlReturnsGoogleTokenEndpoint(): void
    {
        $this->assertSame('https://oauth2.googleapis.com/token', $this->transport->tokenUrl());
    }

    public function testScopesReturnsGmailSendScope(): void
    {
        $this->assertSame(['https://www.googleapis.com/auth/gmail.send'], $this->transport->scopes());
    }

    public function testSendEndpointReturnsGmailSendMessagesUrl(): void
    {
        $this->assertSame('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', $this->transport->sendEndpoint());
    }

    public function testSendPostsToGmailSendEndpoint(): void
    {
        $this->mimeBuilder->shouldReceive('fromMailMessage')->once()->andReturn('raw-mime');
        $this->tokenProvider->shouldReceive('accessToken')->once()->andReturn('tok123');

        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', Mockery::any())
            ->andReturn(new ApiResponse(200, []));

        $this->transport->send($this->message(), $this->connection());
    }

    public function testAuthHeadersBuildsBearerTokenFromOAuth2TokenProvider(): void
    {
        $this->mimeBuilder->shouldReceive('fromMailMessage')->once()->andReturn('raw-mime');
        $this->tokenProvider->shouldReceive('accessToken')->once()->andReturn('tok123');

        $this->apiClient->shouldReceive('setHeaders')
            ->once()
            ->with(['Authorization' => 'Bearer tok123', 'Content-Type' => 'application/json'])
            ->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, []));

        $this->transport->send($this->message(), $this->connection());
    }

    public function testBuildBodyEncodesMimeMessageAsUrlSafeBase64WithoutPadding(): void
    {
        // Bytes chosen so standard base64 output contains '+', '/' and '=' padding, proving
        // the transport swaps to the URL-safe alphabet and strips padding (Gmail's `raw` format).
        $mime        = "Subject: Hi\r\n\r\n" . "\xfb\xff\xfe";
        $expectedRaw = rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');

        $this->mimeBuilder->shouldReceive('fromMailMessage')->once()->andReturn($mime);
        $this->tokenProvider->shouldReceive('accessToken')->once()->andReturn('tok123');

        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(Mockery::any(), ['raw' => $expectedRaw])
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
        $this->tokenProvider->shouldReceive('accessToken')->once()->andReturn('tok123');

        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, []));

        $this->transport->send($message, $this->connection());
    }

    public function testSuccessFromReturnsTrueOnlyForStatus200(): void
    {
        $this->mimeBuilder->shouldReceive('fromMailMessage')->once()->andReturn('raw-mime');
        $this->tokenProvider->shouldReceive('accessToken')->once()->andReturn('tok123');
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(202, []));

        $result = $this->transport->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
    }

    public function testErrorFromParsesErrorMessageFromErrorObject(): void
    {
        $this->mimeBuilder->shouldReceive('fromMailMessage')->once()->andReturn('raw-mime');
        $this->tokenProvider->shouldReceive('accessToken')->once()->andReturn('tok123');
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(400, [
            'error' => ['message' => 'Invalid To header'],
        ]));

        $result = $this->transport->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('Invalid To header', $result->getError());
        $this->assertSame('400', $result->getCode());
    }

    public function testErrorFromFallsBackToGenericMessageWhenErrorShapeMissing(): void
    {
        $this->mimeBuilder->shouldReceive('fromMailMessage')->once()->andReturn('raw-mime');
        $this->tokenProvider->shouldReceive('accessToken')->once()->andReturn('tok123');
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(500, 'Internal Server Error'));

        $result = $this->transport->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('Gmail error HTTP 500', $result->getError());
    }

    private function message(): MailMessage
    {
        return MailMessage::fromArray([
            'to' => ['to@example.com'], 'subject' => 'Subject', 'body' => 'Body text',
        ]);
    }

    private function connection(): Connection
    {
        return Connection::fromArray([
            'id'          => 'conn_1', 'provider' => 'gmail', 'kind' => 'api',
            'credentials' => [
                'access_token'  => ['source' => 'database', 'value' => 'tok123'],
                'refresh_token' => ['source' => 'database', 'value' => 'refresh-1'],
            ],
        ]);
    }
}
