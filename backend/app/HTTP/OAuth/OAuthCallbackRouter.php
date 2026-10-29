<?php

declare(strict_types=1);

namespace BitApps\SMTP\HTTP\OAuth;

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Router\Router;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Router\StaticRouter;
use BitApps\SMTP\HTTP\ServerRequest;
use Closure;
use UnexpectedValueException;

final class OAuthCallbackRouter
{
    private const CALLBACK_PATH = '/bit-smtp/oauth/callback';

    private StaticRouter $staticRouter;

    private string $activationHook;

    private string $deactivationHook;

    private Closure $terminate;

    public function __construct(?StaticRouter $staticRouter = null, ?Closure $terminate = null)
    {
        $this->activationHook   = self::hookName(Config::withPrefix('activate'));
        $this->deactivationHook = self::hookName(Config::withPrefix('deactivate'));
        $this->terminate        = $terminate ?? static function (): void {
            exit;
        };

        if ($staticRouter === null) {
            new Router('static', Config::SLUG, '');
            $staticRouter = new StaticRouter(
                Config::SLUG,
                $this->activationHook,
                $this->deactivationHook
            );
            $staticRouter->loadRoutesFromFile(
                Config::get('BACKEND_PATH') . DIRECTORY_SEPARATOR . 'hooks' . DIRECTORY_SEPARATOR . 'static.php'
            );
        }

        $this->staticRouter = $staticRouter;
    }

    public function register(): void
    {
        remove_action($this->activationHook, [$this->staticRouter, 'flushOnDeactivate'], 10);
        remove_action($this->deactivationHook, [$this->staticRouter, 'flushOnActivate'], 10);
        remove_action('template_redirect', [$this->staticRouter, 'handleRequest'], 10);
        add_action($this->activationHook, [$this->staticRouter, 'flushOnActivate'], 10);
        add_action($this->deactivationHook, [$this->staticRouter, 'flushOnDeactivate'], 10);
        add_action('template_redirect', [$this, 'dispatch'], 10);
    }

    /**
     * Serve the OAuth callback through the static router when the request targets its path.
     */
    public function dispatch(): void
    {
        $hasRequestUri = \array_key_exists('REQUEST_URI', $_SERVER);
        $routerUri     = self::staticRouterUri(ServerRequest::path() ?? '');

        if (!self::isCallbackPath($routerUri)) {
            return;
        }

        if (ServerRequest::method() !== 'GET') {
            status_header(405);
            ($this->terminate)();

            return;
        }

        status_header(200);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Saved only to restore verbatim below.
        $requestUri             = $_SERVER['REQUEST_URI'] ?? null;
        $_SERVER['REQUEST_URI'] = $routerUri;

        try {
            $this->staticRouter->handleRequest();
        } finally {
            if ($hasRequestUri) {
                $_SERVER['REQUEST_URI'] = $requestUri;
            } else {
                unset($_SERVER['REQUEST_URI']);
            }
        }
    }

    public static function pathOnlyUri(?string $requestUri): string
    {
        if ($requestUri === null) {
            return '';
        }

        $path = wp_parse_url($requestUri, PHP_URL_PATH);

        return \is_string($path) ? $path : '';
    }

    /**
     * Request path relative to the site's home path, as the static router expects it.
     */
    private static function staticRouterUri(string $path): string
    {
        $homePath = rtrim(self::pathOnlyUri(home_url('/')), '/');

        if ($homePath === '') {
            return $path;
        }

        if ($path === $homePath) {
            return '/';
        }

        $homePrefix = $homePath . '/';
        if (strncmp($path, $homePrefix, \strlen($homePrefix)) !== 0) {
            return $path;
        }

        return (string) substr($path, \strlen($homePath));
    }

    private static function isCallbackPath(string $path): bool
    {
        return $path === self::CALLBACK_PATH || $path === self::CALLBACK_PATH . '/';
    }

    private static function hookName(mixed $hook): string
    {
        if (!\is_string($hook)) {
            throw new UnexpectedValueException('OAuth callback hook name must be a string.');
        }

        return $hook;
    }
}
