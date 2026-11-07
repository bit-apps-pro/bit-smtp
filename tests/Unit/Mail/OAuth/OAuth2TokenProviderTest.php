<?php

namespace BitApps\SMTP\Tests\Unit\Mail\OAuth;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\OAuth2ProviderInterface;
use BitApps\SMTP\Mail\Exceptions\OAuthException;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Message\SendResult;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
class OAuth2TokenProviderTest extends BaseUnitTestCase
{
    private const TOKEN_URL = 'https://oauth.example.com/token';

    private ApiClient $apiClient;

    private MailConfigService $config;

    private OAuth2TokenProvider $tokenProvider;

    private OAuth2ProviderInterface $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiClient  = Mockery::mock(ApiClient::class);
        $this->config     = Mockery::mock(MailConfigService::class);
        $this->provider   = Mockery::mock(OAuth2ProviderInterface::class);
        $this->provider->shouldReceive('tokenUrl')->andReturn(self::TOKEN_URL);

        $this->tokenProvider = new OAuth2TokenProvider($this->apiClient, $this->config);
    }

    public function testReturnsCachedAccessTokenWhenNotExpired(): void
    {
        $connection = $this->connection(
            ['token_expires_at' => time() + 3600, 'client_id' => 'cid'],
            [
                'access_token'   => ['source' => 'database', 'value' => 'cached-token'],
                'refresh_token'  => ['source' => 'database', 'value' => 'refresh-1'],
                'client_secret'  => ['source' => 'database', 'value' => 'csecret'],
            ]
        );

        $this->apiClient->shouldNotReceive('postForm');
        $this->config->shouldNotReceive('saveConnection');

        $this->assertSame('cached-token', $this->tokenProvider->accessToken($connection, $this->provider));
    }

    public function testRefreshesExpiredTokenAndPersistsNewToken(): void
    {
        $connection = $this->connection(
            ['token_expires_at' => time() - 100, 'client_id' => 'cid'],
            [
                'access_token'  => ['source' => 'database', 'value' => 'stale-token'],
                'refresh_token' => ['source' => 'database', 'value' => 'refresh-1'],
                'client_secret' => ['source' => 'database', 'value' => 'csecret'],
            ]
        );

        $this->apiClient->shouldReceive('postForm')
            ->once()
            ->with(self::TOKEN_URL, [
                'grant_type'    => 'refresh_token',
                'client_id'     => 'cid',
                'client_secret' => 'csecret',
                'refresh_token' => 'refresh-1',
            ])
            ->andReturn(new ApiResponse(200, ['access_token' => 'new-token', 'expires_in' => 3600]));

        $before = time();
        $this->config->shouldReceive('saveConnection')
            ->once()
            ->with(Mockery::on(function (array $saved) use ($before): bool {
                return $saved['credentials']['access_token'] === ['source' => 'database', 'value' => 'new-token']
                    && $saved['settings']['token_expires_at'] >= $before + 3600
                    && $saved['settings']['token_expires_at'] <= time()  + 3600;
            }))
            ->andReturn(true);

        $token = $this->tokenProvider->accessToken($connection, $this->provider);

        $this->assertSame('new-token', $token);
    }

    public function testRefreshesWhenAccessTokenIsAbsentEvenWithFutureExpiry(): void
    {
        $connection = $this->connection(
            ['token_expires_at' => time() + 3600, 'client_id' => 'cid'],
            [
                'refresh_token' => ['source' => 'database', 'value' => 'refresh-1'],
                'client_secret' => ['source' => 'database', 'value' => 'csecret'],
            ]
        );

        $this->apiClient->shouldReceive('postForm')
            ->once()
            ->andReturn(new ApiResponse(200, ['access_token' => 'new-token', 'expires_in' => 3600]));
        $this->config->shouldReceive('saveConnection')->once()->andReturn(true);

        $this->assertSame('new-token', $this->tokenProvider->accessToken($connection, $this->provider));
    }

    public function testThrowsOAuthExceptionWhenNoRefreshTokenIsStored(): void
    {
        $connection = $this->connection(
            ['token_expires_at' => time() - 100, 'client_id' => 'cid'],
            ['client_secret' => ['source' => 'database', 'value' => 'csecret']]
        );

        $this->apiClient->shouldNotReceive('postForm');
        $this->config->shouldNotReceive('saveConnection');

        $this->expectException(OAuthException::class);

        $this->tokenProvider->accessToken($connection, $this->provider);
    }

    public function testThrowsOAuthExceptionOnRefreshHttpError(): void
    {
        $connection = $this->connection(
            ['token_expires_at' => time() - 100, 'client_id' => 'cid'],
            [
                'refresh_token' => ['source' => 'database', 'value' => 'refresh-1'],
                'client_secret' => ['source' => 'database', 'value' => 'csecret'],
            ]
        );

        $this->apiClient->shouldReceive('postForm')
            ->once()
            ->andReturn(new ApiResponse(401, ['error' => 'invalid_grant']));
        $this->config->shouldNotReceive('saveConnection');

        $this->expectException(OAuthException::class);

        $this->tokenProvider->accessToken($connection, $this->provider);
    }

    /**
     * @return array<string,array{0:int,1:mixed}>
     */
    public static function rejectedRefreshes(): array
    {
        return [
            'google revoked token'   => [400, ['error' => 'invalid_grant', 'error_description' => 'Bad Request']],
            'bad client secret'      => [401, ['error' => 'invalid_client']],
            'unauthorized client'    => [400, ['error' => 'unauthorized_client']],
            'missing parameter'      => [400, ['error' => 'invalid_request']],
            'no error code'          => [400, 'Bad Request'],
            'forbidden'              => [403, []],
            'wrong token url'        => [404, []],
        ];
    }

    /**
     * @param mixed $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedRefreshes')]
    public function testRejectedRefreshCarriesTheAuthRejectedCode(int $status, $body): void
    {
        $exception = $this->refreshFailure(new ApiResponse($status, $body));

        $this->assertSame(SendResult::CODE_AUTH_REJECTED, $exception->sendResultCode());
        $this->assertStringContainsString('Reconnect', $exception->getMessage());
    }

    /**
     * @return array<string,array{0:int,1:mixed,2:?string}>
     */
    public static function retryableRefreshes(): array
    {
        return [
            'network error'          => [0, 'cURL error 28: Operation timed out', null],
            'server error'           => [500, ['error' => 'server_error'], '500'],
            'unavailable'            => [503, [], '503'],
            'rate limited'           => [429, [], '429'],
            'request timeout'        => [408, [], '408'],
            'busy with 400 status'   => [400, ['error' => 'temporarily_unavailable'], null],
            'server_error with 400'  => [400, ['error' => 'server_error'], null],
            'ok without a token'     => [200, ['expires_in' => 3600], null],
        ];
    }

    /**
     * @param mixed $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('retryableRefreshes')]
    public function testRetryableRefreshKeepsAStatusCodeOrNone(int $status, $body, ?string $expectedCode): void
    {
        $exception = $this->refreshFailure(new ApiResponse($status, $body));

        $this->assertSame($expectedCode, $exception->sendResultCode());
        $this->assertStringNotContainsString('Reconnect', $exception->getMessage());
    }

    public function testRejectedRefreshNamesTheOAuthErrorButNeverSecretsOrTheBody(): void
    {
        $message = $this->refreshFailure(new ApiResponse(400, [
            'error'             => 'invalid_grant',
            'error_description' => 'AADSTS700082: token for user@example.org expired',
        ]))->getMessage();

        $this->assertStringContainsString('invalid_grant', $message);
        $this->assertStringNotContainsString('AADSTS', $message);
        $this->assertStringNotContainsString('user@example.org', $message);
        $this->assertStringNotContainsString('refresh-1', $message);
        $this->assertStringNotContainsString('csecret', $message);
    }

    public function testUnsafeOAuthErrorCodeIsLeftOutOfTheMessage(): void
    {
        $exception = $this->refreshFailure(new ApiResponse(400, ['error' => "invalid_grant\r\n<script>\"x\""]));

        $this->assertSame(SendResult::CODE_AUTH_REJECTED, $exception->sendResultCode());
        $this->assertStringNotContainsString('<script>', $exception->getMessage());
        $this->assertStringContainsString('HTTP 400', $exception->getMessage());
    }

    public function testMissingRefreshTokenCarriesTheAuthRejectedCode(): void
    {
        $exception = OAuthException::missingRefreshToken();

        $this->assertSame(SendResult::CODE_AUTH_REJECTED, $exception->sendResultCode());
        $this->assertStringContainsString('Reconnect', $exception->getMessage());
    }

    public function testThrowsOAuthExceptionWhenResponseHasNoAccessToken(): void
    {
        $connection = $this->connection(
            ['token_expires_at' => time() - 100, 'client_id' => 'cid'],
            [
                'refresh_token' => ['source' => 'database', 'value' => 'refresh-1'],
                'client_secret' => ['source' => 'database', 'value' => 'csecret'],
            ]
        );

        $this->apiClient->shouldReceive('postForm')
            ->once()
            ->andReturn(new ApiResponse(200, ['expires_in' => 3600]));
        $this->config->shouldNotReceive('saveConnection');

        $this->expectException(OAuthException::class);

        $this->tokenProvider->accessToken($connection, $this->provider);
    }

    public function testPersistsRotatedRefreshTokenWhenResponseIncludesOne(): void
    {
        $connection = $this->connection(
            ['token_expires_at' => time() - 100, 'client_id' => 'cid'],
            [
                'access_token'  => ['source' => 'database', 'value' => 'stale-token'],
                'refresh_token' => ['source' => 'database', 'value' => 'refresh-1'],
                'client_secret' => ['source' => 'database', 'value' => 'csecret'],
            ]
        );

        $this->apiClient->shouldReceive('postForm')
            ->once()
            ->with(self::TOKEN_URL, [
                'grant_type'    => 'refresh_token',
                'client_id'     => 'cid',
                'client_secret' => 'csecret',
                'refresh_token' => 'refresh-1',
            ])
            ->andReturn(new ApiResponse(200, ['access_token' => 'new-token', 'refresh_token' => 'rotated-refresh-token', 'expires_in' => 3600]));

        $before = time();
        $this->config->shouldReceive('saveConnection')
            ->once()
            ->with(Mockery::on(function (array $saved) use ($before): bool {
                return $saved['credentials']['access_token']  === ['source' => 'database', 'value' => 'new-token']
                    && $saved['credentials']['refresh_token'] === ['source' => 'database', 'value' => 'rotated-refresh-token']
                    && $saved['settings']['token_expires_at'] >= $before + 3600
                    && $saved['settings']['token_expires_at'] <= time()  + 3600;
            }))
            ->andReturn(true);

        $token = $this->tokenProvider->accessToken($connection, $this->provider);

        $this->assertSame('new-token', $token);
    }

    public function testPreservesExistingRefreshTokenWhenResponseOmitsOne(): void
    {
        $connection = $this->connection(
            ['token_expires_at' => time() - 100, 'client_id' => 'cid'],
            [
                'access_token'  => ['source' => 'database', 'value' => 'stale-token'],
                'refresh_token' => ['source' => 'database', 'value' => 'refresh-1'],
                'client_secret' => ['source' => 'database', 'value' => 'csecret'],
            ]
        );

        $this->apiClient->shouldReceive('postForm')
            ->once()
            ->with(self::TOKEN_URL, [
                'grant_type'    => 'refresh_token',
                'client_id'     => 'cid',
                'client_secret' => 'csecret',
                'refresh_token' => 'refresh-1',
            ])
            ->andReturn(new ApiResponse(200, ['access_token' => 'new-token', 'expires_in' => 3600]));

        $before = time();
        $this->config->shouldReceive('saveConnection')
            ->once()
            ->with(Mockery::on(function (array $saved) use ($before): bool {
                return $saved['credentials']['access_token']  === ['source' => 'database', 'value' => 'new-token']
                    && $saved['credentials']['refresh_token'] === ['source' => 'database', 'value' => 'refresh-1']
                    && $saved['settings']['token_expires_at'] >= $before + 3600
                    && $saved['settings']['token_expires_at'] <= time()  + 3600;
            }))
            ->andReturn(true);

        $token = $this->tokenProvider->accessToken($connection, $this->provider);

        $this->assertSame('new-token', $token);
    }

    /**
     * Refresh an expired token against the given token-endpoint response and return the exception it raises.
     */
    private function refreshFailure(ApiResponse $response): OAuthException
    {
        $connection = $this->connection(
            ['token_expires_at' => time() - 100, 'client_id' => 'cid'],
            [
                'refresh_token' => ['source' => 'database', 'value' => 'refresh-1'],
                'client_secret' => ['source' => 'database', 'value' => 'csecret'],
            ]
        );

        $this->apiClient->shouldReceive('postForm')->once()->andReturn($response);
        $this->config->shouldNotReceive('saveConnection');

        try {
            $this->tokenProvider->accessToken($connection, $this->provider);
        } catch (OAuthException $e) {
            return $e;
        }

        $this->fail('a failed refresh must throw OAuthException');
    }

    private function connection(array $settings, array $credentials): Connection
    {
        return Connection::fromArray([
            'id'          => 'conn_1',
            'provider'    => 'google',
            'kind'        => 'api',
            'settings'    => $settings,
            'credentials' => $credentials,
        ]);
    }
}
