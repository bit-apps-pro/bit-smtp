<?php

declare(strict_types=1);

namespace BitApps\SMTP\HTTP\OAuth;

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Router\Router;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Router\StaticRouter;

final class OAuthCallbackRouter
{
    private StaticRouter $staticRouter;

    public function __construct(?StaticRouter $staticRouter = null)
    {
        if ($staticRouter === null) {
            new Router('static', Config::SLUG, '');
            $staticRouter = new StaticRouter(
                Config::SLUG,
                Config::withPrefix('activate'),
                Config::withPrefix('deactivate')
            );
            $staticRouter->loadRoutesFromFile(
                Config::get('BACKEND_PATH') . DIRECTORY_SEPARATOR . 'hooks' . DIRECTORY_SEPARATOR . 'static.php'
            );
        }

        $this->staticRouter = $staticRouter;
    }

    public function register(): void
    {
        remove_action('template_redirect', [$this->staticRouter, 'handleRequest']);
        add_action('template_redirect', [$this, 'dispatch'], 10);
    }

    public function dispatch(): void
    {
        $hasRequestUri = \array_key_exists('REQUEST_URI', $_SERVER);
        $requestUri    = $_SERVER['REQUEST_URI'] ?? null;

        $_SERVER['REQUEST_URI'] = self::pathOnlyUri(\is_string($requestUri) ? $requestUri : null);

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

        $path = parse_url($requestUri, PHP_URL_PATH);

        return \is_string($path) ? $path : '';
    }
}
