<?php

namespace BitApps\SMTP\Providers;

use BitApps\SMTP\Deps\BitApps\WPKit\Container\ServiceProvider;

/**
 * Wires plugin activation/deactivation/uninstall hooks and the migration list.
 */
class InstallerServiceProvider extends ServiceProvider
{
    /**
     * Registers InstallerProvider eagerly (not deferred to boot()): WordPress fires the activation
     * hook before init, so wiring this in boot() instead would miss it entirely.
     */
    public function register(): void
    {
        (new InstallerProvider())->register();
    }
}
