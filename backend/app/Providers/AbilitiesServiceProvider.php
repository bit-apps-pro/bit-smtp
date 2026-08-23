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
     * WordPress before 6.9 does not provide the Abilities API. Do not even attach its hooks there,
     * so email sending and the rest of the plugin keep their existing behavior.
     *
     * AbilitiesProvider resolves its own MailAnalyticsService lazily, from the container, only when
     * an ability actually executes (see AbilitiesProvider::analyticsService()) -- never eagerly here.
     * register() runs during Plugin::__construct(), before Plugin::load() has called
     * DB::setPluginPrefix(); building MailAnalyticsRepository (and its Log model) this early would
     * bake in a table name missing the plugin prefix for the rest of the request.
     */
    public function register(): void
    {
        if (\function_exists('wp_register_ability') && \function_exists('wp_register_ability_category')) {
            (new AbilitiesProvider())->register();
        }
    }
}
