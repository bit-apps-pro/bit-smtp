<?php

namespace BitApps\SMTP\Tests\Unit\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Controllers\OAuthController;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\OAuth2ProviderInterface;
use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\OAuth\OAuthStateCodec;
use BitApps\SMTP\Mail\Providers\ProviderRegistry;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
class OAuthControllerTest extends BaseUnitTestCase
{
    private const CALLBACK_URL = 'https://site.test/bit-smtp/oauth/callback';

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/gmail.send';

    /**
     * @var ApiClient|Mockery\MockInterface
     */
    private $apiClient;

    /**
     * @var MailConfigService|Mockery\MockInterface
     */
    private $config;

    /**
     * @var ProviderRegistry|Mockery\MockInterface
     */
    private $registry;

    /**
     * @var OAuth2ProviderInterface|Mockery\MockInterface
     */
    private $transport;

    private CapturingOAuthController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('__')->returnArg(1);
        Functions\when('wp_salt')->justReturn('unit-test-salt');
        Functions\when('home_url')->justReturn(self::CALLBACK_URL);
        Functions\when('current_user_can')->justReturn(true);

        $this->apiClient = Mockery::mock(ApiClient::class);
        $this->config    = Mockery::mock(MailConfigService::class);
        $this->registry  = Mockery::mock(ProviderRegistry::class);
        // Real OAuth2 transports implement both contracts; the double must too, since
        // ProviderInterface::transport() is typed to return a TransportInterface.
        $this->transport = Mockery::mock(TransportInterface::class, OAuth2ProviderInterface::class);

        $this->controller = new CapturingOAuthController($this->apiClient, $this->config, $this->registry);
    }

    // --- authorize ---

    public function testAuthorizeBuildsConsentUrlWithGoogleParamsAndValidState(): void
    {
        $connection = $this->gmailConnection();

        $this->registerGmailTransport();
        $this->transport->shouldReceive('authUrl')->once()->with($connection)->andReturn(self::AUTH_URL);
        $this->transport->shouldReceive('scopes')->andReturn([self::SCOPE]);
        $this->transport->shouldReceive('extraAuthParams')->andReturn(['access_type' => 'offline', 'prompt' => 'consent']);
        $this->config->shouldReceive('connectionById')->with('conn_1')->andReturn($connection);

        $this->controller->authorize($this->request(['connection_id' => 'conn_1', 'provider' => 'gmail']));

        $this->assertSame(Response::SUCCESS, Response::getStatus());
        $data = (array) Response::getData();

        $this->assertStringStartsWith(self::AUTH_URL . '?', $data['url']);
        parse_str(parse_url($data['url'], PHP_URL_QUERY), $query);

        $this->assertSame('CLIENT-123', $query['client_id']);
        $this->assertSame(self::CALLBACK_URL, $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame(self::SCOPE, $query['scope']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);

        $decoded = OAuthStateCodec::decode($query['state']);
        $this->assertSame('conn_1', $decoded['connection_id']);
        $this->assertSame('gmail', $decoded['provider']);
    }

    public function testAuthorizeRejectsWhenCapabilityMissing(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        $this->config->shouldNotReceive('connectionById');

        $this->controller->authorize($this->request(['connection_id' => 'conn_1', 'provider' => 'gmail']));

        $this->assertSame(Response::ERROR, Response::getStatus());
    }

    public function testAuthorizeRejectsUnknownProvider(): void
    {
        $this->config->shouldReceive('connectionById')->andReturn($this->gmailConnection());
        $this->registry->shouldReceive('has')->with('mystery')->andReturn(false);

        $this->controller->authorize($this->request(['connection_id' => 'conn_1', 'provider' => 'mystery']));

        $this->assertSame(Response::ERROR, Response::getStatus());
    }

    public function testAuthorizeRejectsNonOAuth2Provider(): void
    {
        $this->config->shouldReceive('connectionById')->andReturn($this->gmailConnection());
        $provider = Mockery::mock(ProviderInterface::class);
        $provider->shouldReceive('transport')->andReturn(Mockery::mock(TransportInterface::class));
        $this->registry->shouldReceive('has')->with('other_smtp')->andReturn(true);
        $this->registry->shouldReceive('get')->with('other_smtp')->andReturn($provider);

        $this->controller->authorize($this->request(['connection_id' => 'conn_1', 'provider' => 'other_smtp']));

        $this->assertSame(Response::ERROR, Response::getStatus());
    }

    public function testAuthorizeRejectsMissingClientId(): void
    {
        $this->registerGmailTransport();
        $connection = Connection::fromArray([
            'id'       => 'conn_1', 'provider' => 'gmail', 'kind' => 'api',
            'settings' => [], 'credentials' => [],
        ]);
        $this->config->shouldReceive('connectionById')->andReturn($connection);

        $this->controller->authorize($this->request(['connection_id' => 'conn_1', 'provider' => 'gmail']));

        $this->assertSame(Response::ERROR, Response::getStatus());
    }

    // --- callback ---

    public function testCallbackExchangesCodeAndStoresTokens(): void
    {
        $state = OAuthStateCodec::encode('conn_1', 'gmail');

        $this->registerGmailTransport();
        $this->transport->shouldReceive('tokenUrl')->andReturn(self::TOKEN_URL);
        $this->config->shouldReceive('connectionById')->with('conn_1')->andReturn($this->gmailConnection());

        $this->apiClient->shouldReceive('postForm')
            ->once()
            ->with(self::TOKEN_URL, [
                'grant_type'    => 'authorization_code',
                'code'          => 'AUTH-CODE',
                'client_id'     => 'CLIENT-123',
                'client_secret' => 'SECRET-XYZ',
                'redirect_uri'  => self::CALLBACK_URL,
            ])
            ->andReturn(new ApiResponse(200, [
                'access_token'  => 'access-token-value',
                'refresh_token' => 'refresh-token-value',
                'expires_in'    => 3600,
            ]));

        $before = time();
        $this->config->shouldReceive('saveConnection')
            ->once()
            ->with(Mockery::on(function (array $saved) use ($before): bool {
                return $saved['credentials']['refresh_token'] === ['source' => 'database', 'value' => 'refresh-token-value']
                    && $saved['credentials']['access_token']  === ['source' => 'database', 'value' => 'access-token-value']
                    && $saved['settings']['token_expires_at'] >= $before + 3600
                    && $saved['settings']['token_expires_at'] <= time()  + 3600;
            }))
            ->andReturn(true);

        $this->controller->callback($this->request(['code' => 'AUTH-CODE', 'state' => $state]));

        $this->assertSame(1, $this->controller->emitCount);
        $this->assertStringContainsString('"status":"success"', $this->controller->emittedHtml);
        $this->assertStringNotContainsString('access-token-value', $this->controller->emittedHtml);
        $this->assertStringNotContainsString('refresh-token-value', $this->controller->emittedHtml);
        $this->assertStringNotContainsString('SECRET-XYZ', $this->controller->emittedHtml);
    }

    public function testCallbackShowsErrorPageWhenPersistenceFails(): void
    {
        $state = OAuthStateCodec::encode('conn_1', 'gmail');

        $this->registerGmailTransport();
        $this->transport->shouldReceive('tokenUrl')->andReturn(self::TOKEN_URL);
        $this->config->shouldReceive('connectionById')->with('conn_1')->andReturn($this->gmailConnection());
        $this->apiClient->shouldReceive('postForm')->once()->andReturn(new ApiResponse(200, [
            'access_token'  => 'access-token-value',
            'refresh_token' => 'refresh-token-value',
            'expires_in'    => 3600,
        ]));
        $this->config->shouldReceive('saveConnection')->once()->andReturn(false);

        $this->controller->callback($this->request(['code' => 'AUTH-CODE', 'state' => $state]));

        $this->assertSame(1, $this->controller->emitCount);
        $this->assertStringContainsString('"status":"error"', $this->controller->emittedHtml);
    }

    public function testCallbackRejectsInvalidStateWithoutExchangeOrSave(): void
    {
        $this->apiClient->shouldNotReceive('postForm');
        $this->config->shouldNotReceive('saveConnection');
        $this->config->shouldNotReceive('connectionById');

        $this->controller->callback($this->request(['code' => 'AUTH-CODE', 'state' => 'tampered.state']));

        $this->assertSame(1, $this->controller->emitCount);
    }

    public function testCallbackRejectsProviderErrorParamWithoutSave(): void
    {
        $this->apiClient->shouldNotReceive('postForm');
        $this->config->shouldNotReceive('saveConnection');
        $this->config->shouldNotReceive('connectionById');

        $this->controller->callback($this->request([
            'error' => 'access_denied',
            'state' => OAuthStateCodec::encode('conn_1', 'gmail'),
        ]));

        $this->assertSame(1, $this->controller->emitCount);
    }

    public function testCallbackDoesNotSaveWhenTokenExchangeFails(): void
    {
        $state = OAuthStateCodec::encode('conn_1', 'gmail');

        $this->registerGmailTransport();
        $this->transport->shouldReceive('tokenUrl')->andReturn(self::TOKEN_URL);
        $this->config->shouldReceive('connectionById')->with('conn_1')->andReturn($this->gmailConnection());

        $this->apiClient->shouldReceive('postForm')->once()->andReturn(new ApiResponse(400, ['error' => 'invalid_grant']));
        $this->config->shouldNotReceive('saveConnection');

        $this->controller->callback($this->request(['code' => 'AUTH-CODE', 'state' => $state]));

        $this->assertSame(1, $this->controller->emitCount);
    }

    // --- helpers ---

    private function registerGmailTransport(): void
    {
        $provider = Mockery::mock(ProviderInterface::class);
        $provider->shouldReceive('transport')->andReturn($this->transport);
        $this->registry->shouldReceive('has')->with('gmail')->andReturn(true);
        $this->registry->shouldReceive('get')->with('gmail')->andReturn($provider);
    }

    private function gmailConnection(): Connection
    {
        return Connection::fromArray([
            'id'          => 'conn_1',
            'provider'    => 'gmail',
            'kind'        => 'api',
            'settings'    => ['client_id' => 'CLIENT-123'],
            'credentials' => ['client_secret' => ['source' => 'database', 'value' => 'SECRET-XYZ']],
        ]);
    }

    /**
     * @param array<string,string> $params
     *
     * @return Request|Mockery\MockInterface
     */
    private function request(array $params)
    {
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('get')->andReturnUsing(static function (string $key, $default = null) use ($params) {
            return $params[$key] ?? $default;
        });

        return $request;
    }
}

/**
 * Captures the terminal HTML instead of echoing + exiting, so the browser-terminating callback
 * can be asserted under PHPUnit's strict no-output policy.
 *
 * @internal
 */
class CapturingOAuthController extends OAuthController
{
    public string $emittedHtml = '';

    public int $emitCount = 0;

    protected function emit(string $html): void
    {
        $this->emittedHtml = $html;
        ++$this->emitCount;
    }
}
