<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Router\Router;
use BitApps\SMTP\HTTP\Services\MailConfigService;

/**
 * Verifies the OAuth routes are wired with the right protection: `authorize` inside the
 * `nonce:admin` group, `callback` public (state-gated) outside it.
 *
 * @internal
 *
 * @coversNothing
 */
final class OAuthRouteTest extends IntegrationTestCase
{
    /**
     * @var array<string,\BitApps\SMTP\Deps\BitApps\WPKit\Http\Router\RouteRegister>
     */
    private array $routesByPath = [];

    protected function setUp(): void
    {
        parent::setUp();

        // loadApi() is gated on REST_REQUEST; define it to unlock route registration.
        if (!\defined('REST_REQUEST')) {
            \define('REST_REQUEST', true);
        }

        do_action('rest_api_init');

        foreach (Router::instance()->getRoutes() as $route) {
            $this->routesByPath[$route->getPath()] = $route;
        }
    }

    public function testOauthRoutesAreRegisteredInTheRestServer(): void
    {
        $routes = rest_get_server()->get_routes();
        $prefix = '/' . Config::SLUG . '/v' . Config::API_VERSION;

        $this->assertArrayHasKey($prefix . '/mail/oauth/authorize', $routes);
        $this->assertArrayHasKey($prefix . '/mail/oauth/callback', $routes);
    }

    public function testAuthorizeIsBehindNonceAdminMiddleware(): void
    {
        $this->assertArrayHasKey('mail/oauth/authorize', $this->routesByPath);
        $this->assertContains('nonce:admin', $this->routesByPath['mail/oauth/authorize']->getMiddleware());
    }

    public function testCallbackIsPublicWithoutNonceMiddleware(): void
    {
        $this->assertArrayHasKey('mail/oauth/callback', $this->routesByPath);
        $this->assertNotContains('nonce:admin', $this->routesByPath['mail/oauth/callback']->getMiddleware());
        $this->assertEmpty($this->routesByPath['mail/oauth/callback']->getMiddleware());
    }

    /**
     * Guards the OAuth flow's persistence contract: the consent handshake is worthless if the
     * connection's client_id and the stored token expiry do not survive a save round-trip.
     */
    public function testOauthConnectionSettingsSurviveSaveAndReload(): void
    {
        $service = new MailConfigService();
        $service->saveConnection([
            'id'          => '',
            'provider'    => 'gmail',
            'kind'        => 'api',
            'name'        => 'Gmail',
            'enabled'     => true,
            'settings'    => ['client_id' => 'my-client.apps.googleusercontent.com', 'token_expires_at' => 1700000000],
            'credentials' => [
                'client_secret' => ['source' => 'database', 'value' => 'the-secret'],
                'refresh_token' => ['source' => 'database', 'value' => 'the-refresh-token'],
            ],
        ]);

        $connection = (new MailConfigService())->load()->getConnections()->first();

        $this->assertNotNull($connection);
        $this->assertSame('my-client.apps.googleusercontent.com', $connection->setting('client_id'));
        $this->assertSame(1700000000, $connection->setting('token_expires_at'));
        $this->assertSame('the-refresh-token', $connection->getCredentials()['refresh_token']['value']);
    }
}
