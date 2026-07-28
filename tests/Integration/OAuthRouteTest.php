<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Router\Router;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Router\StaticRouter;
use BitApps\SMTP\HTTP\Controllers\OAuthController;
use BitApps\SMTP\HTTP\OAuth\OAuthCallbackRouter;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\HTTP\Webhook\WebhookRouter;
use ReflectionProperty;

/**
 * Verifies OAuth authorization remains protected by REST while the public callback uses the exact
 * static route ahead of dynamic webhook dispatch.
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
        $this->assertArrayNotHasKey($prefix . '/mail/oauth/callback', $routes);
    }

    public function testAuthorizeIsBehindCapAdminMiddleware(): void
    {
        $this->assertArrayHasKey('mail/oauth/authorize', $this->routesByPath);
        $this->assertContains('cap:admin', $this->routesByPath['mail/oauth/authorize']->getMiddleware());
    }

    public function testCallbackIsRegisteredAsOneExactStaticGetRoute(): void
    {
        $callbacks = $this->callbacksFor('template_redirect', OAuthCallbackRouter::class, 'dispatch');

        $this->assertCount(1, $callbacks);

        $property     = new ReflectionProperty(OAuthCallbackRouter::class, 'staticRouter');
        $staticRouter = $property->getValue($callbacks[0][0]);
        $routes       = $staticRouter->getRouter()->getRoutes();

        $this->assertCount(1, $routes);
        $this->assertSame(['GET'], $routes[0]->getMethods());
        $this->assertSame('oauth/callback', $routes[0]->getPath());
        $this->assertSame([OAuthController::class, 'callback'], $routes[0]->getAction());
    }

    public function testExactCallbackRunsBeforeDynamicWebhookDispatch(): void
    {
        $oauthCallbacks   = $this->callbacksFor('template_redirect', OAuthCallbackRouter::class, 'dispatch');
        $webhookCallbacks = $this->callbacksFor('template_redirect', WebhookRouter::class, 'match');

        $this->assertCount(1, $oauthCallbacks);
        $this->assertCount(1, $webhookCallbacks);
        $this->assertSame(10, has_action('template_redirect', $oauthCallbacks[0]));
        $this->assertSame(20, has_action('template_redirect', $webhookCallbacks[0]));
        $this->assertSame([], $this->callbacksFor('template_redirect', StaticRouter::class, 'handleRequest'));
        $this->assertSame([], $this->callbacksFor('parse_request', OAuthCallbackRouter::class, 'dispatch'));
        $this->assertSame([], $this->callbacksFor('parse_request', WebhookRouter::class, 'match'));
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

    /**
     * @return array<int,array{0: object, 1: string}>
     */
    private function callbacksFor(string $hook, string $class, string $method): array
    {
        global $wp_filter;

        if (!isset($wp_filter[$hook])) {
            return [];
        }

        $callbacks = [];
        foreach ($wp_filter[$hook]->callbacks as $priorityCallbacks) {
            foreach ($priorityCallbacks as $registered) {
                $callback = $registered['function'];
                if (
                    \is_array($callback)
                    && isset($callback[0], $callback[1])
                    && $callback[0] instanceof $class
                    && $callback[1] === $method
                ) {
                    $callbacks[] = $callback;
                }
            }
        }

        return $callbacks;
    }
}
