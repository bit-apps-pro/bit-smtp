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
     * No bindings; route/layout registration deferred to boot() for init:11 firing (as Plugin::registerProviders()).
     */
    public function register(): void
    {
    }

    /**
     * Wire admin layout and all HTTP routing (identical to legacy Plugin::registerProviders() body).
     */
    public function boot(): void
    {
        if (RequestType::is('admin')) {
            new Layout();
        }

        new HookProvider();
    }
}
