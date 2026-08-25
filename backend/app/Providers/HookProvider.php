<?php

namespace BitApps\SMTP\Providers;

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPKit\Hooks\Hooks;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\RequestType;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Router\Router;
use BitApps\SMTP\HTTP\Controllers\TelemetryPopupController;
use BitApps\SMTP\HTTP\OAuth\OAuthCallbackRouter;
use BitApps\SMTP\HTTP\Tracking\TrackingRouter;
use BitApps\SMTP\HTTP\Webhook\WebhookRouter;
use BitApps\SMTP\Plugin;

class HookProvider
{
    private $_pluginBackend;

    public function __construct()
    {
        $this->_pluginBackend = Config::get('BACKEND_PATH') . DIRECTORY_SEPARATOR;
        $this->loadAppHooks();
        Hooks::addAction('rest_api_init', [$this, 'loadApi']);
        (new OAuthCallbackRouter())->register();
        Hooks::addAction('template_redirect', [new WebhookRouter(), 'match'], 20);
        Hooks::addAction('template_redirect', [new TrackingRouter(), 'match'], 15);
        Hooks::addFilter(Config::VAR_PREFIX . 'telemetry_additional_data', [new TelemetryPopupController(), 'filterTrackingData']);
        Hooks::addFilter(Config::withPrefix('deactivate_reasons'), [$this, 'deactivateReasons']);
    }

    /**
     * Loads API routes.
     */
    public function loadApi()
    {
        if (
            is_readable($this->_pluginBackend . 'hooks' . DIRECTORY_SEPARATOR . 'api.php')
            && RequestType::is(RequestType::API)
        ) {
            $router = new Router(RequestType::API, Config::SLUG, 'v' . Config::API_VERSION);
            $router->setMiddlewares(Plugin::instance()->middlewares());

            include $this->_pluginBackend . 'hooks' . DIRECTORY_SEPARATOR . 'api.php';
            $router->register();
        }
    }

    public function deactivateReasons($reasons)
    {
        if (isset($reasons[Config::withPrefix('pro')])) {
            unset($reasons[Config::withPrefix('pro')]);
        }

        return $reasons;
    }

    /**
     * Helps to register App hooks.
     */
    protected function loadAppHooks()
    {
        if (
            RequestType::is(RequestType::AJAX)
            && is_readable($this->_pluginBackend . 'hooks' . DIRECTORY_SEPARATOR . 'ajax.php')
        ) {
            $router = new Router(RequestType::AJAX, Config::VAR_PREFIX, '');
            $router->setMiddlewares(Plugin::instance()->middlewares());
            include $this->_pluginBackend . 'hooks' . DIRECTORY_SEPARATOR . 'ajax.php';
            $router->register();
        }

        if (is_readable($this->_pluginBackend . 'hooks.php')) {
            include $this->_pluginBackend . 'hooks.php';
        }
    }
}
