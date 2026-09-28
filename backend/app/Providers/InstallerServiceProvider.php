<?php

namespace BitApps\SMTP\Providers;

use BitApps\SMTP\Deps\BitApps\WPKit\Container\ServiceProvider;

/**
 * Wires plugin activation/deactivation/uninstall hooks and the migration list.
 */
class InstallerServiceProvider extends ServiceProvider
{
    /**
     * Register InstallerProvider eagerly (activation hook fires before init, boot() would miss it).
     */
    public function register(): void
    {
        (new InstallerProvider())->register();
    }
}
