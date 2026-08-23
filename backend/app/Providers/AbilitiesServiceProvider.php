<?php

namespace BitApps\SMTP\Providers;

use BitApps\SMTP\Deps\BitApps\WPKit\Cache\CacheManager;
use BitApps\SMTP\Deps\BitApps\WPKit\Container\ServiceProvider;
use BitApps\SMTP\Mail\Abilities\AbilitiesProvider;

/**
 * Registers the Abilities API integration when WordPress provides it (6.9+).
 */
class AbilitiesServiceProvider extends ServiceProvider
{
    /**
     * WordPress before 6.9 does not provide the Abilities API. Do not even attach its hooks there,
     * so email sending and the rest of the plugin keep their existing behavior.
     */
    public function register(): void
    {
        if (\function_exists('wp_register_ability') && \function_exists('wp_register_ability_category')) {
            $cache = $this->app->make(CacheManager::class)->store('transient');
            (new AbilitiesProvider(null, null, null, $cache))->register();
        }
    }
}
