<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\Microsoft365;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\MimeBuilder;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use BitApps\SMTP\Mail\Providers\Microsoft365\Microsoft365Transport;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
class Microsoft365TransportTest extends BaseUnitTestCase
{
    private const SEND_ENDPOINT = 'https://graph.microsoft.com/v1.0/me/sendMail';

    private ApiClient $apiClient;

    private OAuth2TokenProvider $tokenProvider;

    private MimeBuilder $mimeBuilder;

    private Microsoft365Transport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiClient     = Mockery::mock(ApiClient::class);
        $this->tokenProvider = Mockery::mock(OAuth2TokenProvider::class);
        $this->mimeBuilder   = Mockery::mock(MimeBuilder::class);
        $this->transport     = new Microsoft365Transport($this->apiClient, $this->tokenProvider, $this->mimeBuilder);
    }

    public function testAuthUrlUsesConfiguredTenant(): void
    {
        $connection = $this->connectionWithTenant('contoso.onmicrosoft.com');

        $this->assertSame(
            'https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/authorize',
            $this->transport->authUrl($connection)
        );
    }

    public function testAuthUrlFallsBackToCommonTenantWhenUnset(): void
    {
        $this->assertSame(
            'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            $this->transport->authUrl($this->connection())
        );
    }

    public function testTokenUrlUsesConfiguredTenant(): void
    {
        $connection = $this->connectionWithTenant('contoso.onmicrosoft.com');

        $this->assertSame(
            'https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/token',
            $this->transport->tokenUrl($connection)
        );
    }

    public function testTokenUrlFallsBackToCommonTenantWhenUnset(): void
    {
        $this->assertSame(
            'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            $this->transport->tokenUrl($this->connection())
        );
    }

    public function testAuthUrlRawurlencodesTenantToPreventPathInjection(): void
    {
        $connection = $this->connectionWithTenant('evil/../..?x=1');

        $url = $this->transport->authUrl($connection);

        $this->assertSame(
            'https://login.microsoftonline.com/' . rawurlencode('evil/../..?x=1') . '/oauth2/v2.0/authorize',
            $url
        );
        $this->assertStringNotContainsString('evil/', $url, 'the / separators in the tenant must be encoded');
        $this->assertStringNotContainsString('?x=1', $url, 'the ? in the tenant must not open a query string');
    }

    public function testTokenUrlRawurlencodesTenantToPreventPathInjection(): void
    {
        $connection = $this->connectionWithTenant('evil/../..?x=1');

        $url = $this->transport->tokenUrl($connection);

        $this->assertSame(
            'https://login.microsoftonline.com/' . rawurlencode('evil/../..?x=1') . '/oauth2/v2.0/token',
            $url
        );
        $this->assertStringNotContainsString('evil/', $url);
        $this->assertStringNotContainsString('?x=1', $url);
    }

    public function testScopesRequestMailSendAndOfflineAccess(): void
    {
        $this->assertSame(
            ['https://graph.microsoft.com/Mail.Send', 'offline_access'],
            $this->transport->scopes()
        );
    }

    public function testExtraAuthParamsAreEmpty(): void
    {
        $this->assertSame([], $this->transport->extraAuthParams());
    }

    public function testSendPostsBase64MimeAsBearerAndDoesNotRefreshAValidToken(): void
    {
        $mime        = "Subject: Ping\r\n\r\nHello Graph";
        $accessToken = 'cached-access-token';

        // A real token provider proves the send serves the cached, unexpired token directly:
        // its token-exchange client must never be hit for a refresh.
        $tokenClient = Mockery::mock(ApiClient::class);
        $config      = Mockery::mock(MailConfigService::class);
        $tokenClient->shouldNotReceive('postForm');
        $config->shouldNotReceive('saveConnection');

        $tokens     = new OAuth2TokenProvider($tokenClient, $config);
        $sendClient = Mockery::mock(ApiClient::class);
        $mime_      = Mockery::mock(MimeBuilder::class);
        $mime_->shouldReceive('fromMailMessage')->once()->andReturn($mime);

        $transport = new Microsoft365Transport($sendClient, $tokens, $mime_);

        $sendClient->shouldReceive('setHeaders')
            ->once()
            ->with(['Content-Type' => 'text/plain', 'Authorization' => 'Bearer ' . $accessToken])
            ->andReturnSelf();
        $sendClient->shouldReceive('post')
            ->once()
            ->with(self::SEND_ENDPOINT, base64_encode($mime))
            ->andReturn(new ApiResponse(202, ''));

        $result = $transport->send($this->message(), $this->connectionWithValidToken($accessToken));

        $this->assertTrue($result->isOk());
    }

    public function testBuildBodyUsesStandardBase64NotUrlSafe(): void
    {
        // Bytes chosen so standard base64 emits '+', '/' and '=' padding: proving the transport
        // keeps the standard alphabet (Graph's text/plain sendMail body), not Gmail's base64url.
        $mime     = "Subject: Hi\r\n\r\n" . "\xfb\xff\xfe";
        $expected = base64_encode($mime);

        $this->mimeBuilder->shouldReceive('fromMailMessage')->once()->andReturn($mime);
        $this->tokenProvider->shouldReceive('accessToken')->once()->andReturn('tok');
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with(self::SEND_ENDPOINT, $expected)
            ->andReturn(new ApiResponse(202, ''));

        $result = $this->transport->send($this->message(), $this->connection());

        $this->assertTrue($result->isOk());
        $this->assertMatchesRegularExpression('/[+\/=]/', $expected, 'the fixture must exercise standard-only base64 characters');
    }

    public function testBuildBodyPassesMailMessageToMimeBuilder(): void
    {
        $message = $this->message();

        $this->mimeBuilder->shouldReceive('fromMailMessage')->once()->with($message, Mockery::type(Connection::class))->andReturn('raw-mime');
        $this->tokenProvider->shouldReceive('accessToken')->once()->andReturn('tok');
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(202, ''));

        $this->transport->send($message, $this->connection());
    }

    public function testSuccessFromReturnsTrueForStatus202(): void
    {
        $this->mimeBuilder->shouldReceive('fromMailMessage')->once()->andReturn('raw-mime');
        $this->tokenProvider->shouldReceive('accessToken')->once()->andReturn('tok');
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(202, ''));

        $result = $this->transport->send($this->message(), $this->connection());

        $this->assertTrue($result->isOk());
    }

    public function testSuccessFromReturnsFalseForStatus200(): void
    {
        $this->mimeBuilder->shouldReceive('fromMailMessage')->once()->andReturn('raw-mime');
        $this->tokenProvider->shouldReceive('accessToken')->once()->andReturn('tok');
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(200, ''));

        $result = $this->transport->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
    }

    public function testErrorFromParsesGraphErrorMessage(): void
    {
        $this->mimeBuilder->shouldReceive('fromMailMessage')->once()->andReturn('raw-mime');
        $this->tokenProvider->shouldReceive('accessToken')->once()->andReturn('tok');
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(400, [
            'error' => ['code' => 'ErrorInvalidRecipients', 'message' => 'Invalid recipient'],
        ]));

        $result = $this->transport->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('Invalid recipient', $result->getError());
        $this->assertSame('400', $result->getCode());
    }

    public function testErrorFromFallsBackToGenericMessageWhenErrorShapeMissing(): void
    {
        $this->mimeBuilder->shouldReceive('fromMailMessage')->once()->andReturn('raw-mime');
        $this->tokenProvider->shouldReceive('accessToken')->once()->andReturn('tok');
        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')->once()->andReturn(new ApiResponse(500, 'Internal Server Error'));

        $result = $this->transport->send($this->message(), $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('Microsoft 365 error HTTP 500', $result->getError());
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
            'id'          => 'conn_1', 'provider' => 'microsoft365', 'kind' => 'api',
            'credentials' => [
                'access_token'  => ['source' => 'database', 'value' => 'tok'],
                'refresh_token' => ['source' => 'database', 'value' => 'refresh-1'],
            ],
        ]);
    }

    private function connectionWithTenant(string $tenant): Connection
    {
        return Connection::fromArray([
            'id'       => 'conn_1', 'provider' => 'microsoft365', 'kind' => 'api',
            'settings' => ['tenant' => $tenant],
        ]);
    }

    private function connectionWithValidToken(string $accessToken): Connection
    {
        return Connection::fromArray([
            'id'          => 'conn_1', 'provider' => 'microsoft365', 'kind' => 'api',
            'settings'    => ['token_expires_at' => time() + 3600, 'client_id' => 'cid'],
            'credentials' => [
                'access_token'  => ['source' => 'database', 'value' => $accessToken],
                'refresh_token' => ['source' => 'database', 'value' => 'refresh-1'],
                'client_secret' => ['source' => 'database', 'value' => 'csecret'],
            ],
        ]);
    }
}
