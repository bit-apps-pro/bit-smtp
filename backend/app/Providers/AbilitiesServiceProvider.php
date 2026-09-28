<?php

namespace BitApps\SMTP\Providers;

use BitApps\SMTP\Deps\BitApps\WPKit\Container\ServiceProvider;
use BitApps\SMTP\Mail\Abilities\AbilitiesProvider;

/**
 * Registers the Abilities API integration when WordPress provides it (6.9+).
 */
class AbilitiesServiceProvider extends ServiceProvider
{
    /**
     * Register Abilities API only on WP 6.9+ (guards hooks, AbilitiesProvider resolves MailAnalyticsService lazily).
     */
    public function register(): void
    {
        if (\function_exists('wp_register_ability') && \function_exists('wp_register_ability_category')) {
            (new AbilitiesProvider())->register();
        }
    }
}
