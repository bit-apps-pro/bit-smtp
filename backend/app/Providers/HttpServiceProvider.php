<?php

namespace BitApps\SMTP\Providers;

use BitApps\SMTP\Deps\BitApps\WPKit\Container\ServiceProvider;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\RequestType;
use BitApps\SMTP\Views\Layout;

/**
 * Boots the HTTP layer: AJAX/REST/webhook/OAuth routing and, on admin requests, the admin layout.
 */
class HttpServiceProvider extends ServiceProvider
{
    /**
     * No bindings. Route/layout registration has side effects and is deferred to boot() so it keeps
     * firing at init:11, exactly as Plugin::registerProviders() does today.
     */
    public function register(): void
    {
    }

    /**
     * Wire the admin layout and all HTTP routing, identical to the legacy
     * Plugin::registerProviders() body.
     */
    public function boot(): void
    {
        if (RequestType::is('admin')) {
            new Layout();
        }

        new HookProvider();
    }
}
