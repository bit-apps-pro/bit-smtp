<?php

namespace BitApps\SMTP\Providers;

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPKit\Hooks\Hooks;
use BitApps\SMTP\Deps\BitApps\WPKit\Installer;
use BitApps\SMTP\Deps\BitApps\WPKit\Migration\MigrationHelper;
use WP_Site;

class InstallerProvider
{
    private $_activateHook;

    private $_deactivateHook;

    private static $_uninstallHook;

    public function __construct()
    {
        register_activation_hook(Config::get('MAIN_FILE'), [$this, 'registerActivator']);
        register_deactivation_hook(Config::get('MAIN_FILE'), [$this, 'registerDeactivator']);
        $this->_activateHook   = Config::withPrefix('activate');
        $this->_deactivateHook = Config::withPrefix('deactivate');
        self::$_uninstallHook  = Config::withPrefix('uninstall');

        Hooks::addAction($this->_deactivateHook, [$this, 'deactivate']);

        // Subsites created after network activation miss the activation-time provisioning loop, so
        // wire schema creation into the new-site lifecycle here at load time. Priority 20 runs after
        // core's own priority-10 wp_initialize_site handler that creates the blog's options/core
        // tables, so switch_to_blog() lands on a fully-initialised site before the migrations run.
        add_action('wp_initialize_site', [$this, 'provisionNewSite'], 20);

        // Only a static class method or function can be used in an uninstall hook.
        register_uninstall_hook(Config::get('MAIN_FILE'), [self::class, 'registerUninstaller']);
    }

    public function register()
    {
        $installer = new Installer(
            [
                'php'        => Config::REQUIRED_PHP_VERSION,
                'wp'         => Config::REQUIRED_WP_VERSION,
                'version'    => Config::VERSION,
                'oldVersion' => Config::getOption('version', '0.0'),
                'multisite'  => true,
                'basename'   => Config::get('BASENAME'),
            ],
            [
                'activate'  => $this->_activateHook,
                'uninstall' => self::$_uninstallHook,
            ],
            [

                'migration' => $this->migration(),
                'drop'      => $this->drop(),
            ]
        );
        $installer->register();
    }

    public function deactivate($networkWide)
    {
        // Clear by explicit hook name: a deactivation request may not have booted the service
        // providers that call Scheduler::job() this request, so a Scheduler instance's own
        // bookkeeping can't be relied on here -- WordPress's cron API is the only sure handle.
        wp_clear_scheduled_hook(Config::RETENTION_GC_HOOK);
        wp_clear_scheduled_hook(Config::RETRY_QUEUE_HOOK);
        wp_clear_scheduled_hook(Config::HEALTH_CHECK_HOOK);
    }

    /**
     * Provisions the plugin schema on a subsite created after network activation, which the
     * activation-time provisioning loop never covered (late blogs would otherwise silently lack the
     * logs/engagement/retry tables). Idempotent: the migrations are CREATE TABLE IF NOT EXISTS.
     */
    public function provisionNewSite(WP_Site $newSite): void
    {
        if (!is_multisite() || !$this->isNetworkActive()) {
            return;
        }

        switch_to_blog((int) $newSite->blog_id);

        try {
            MigrationHelper::migrate(self::migration());
        } finally {
            // Restore in a finally so a migration throw can't leave the wrong blog switched.
            restore_current_blog();
        }
    }

    public function registerActivator($networkWide)
    {
        Hooks::doAction($this->_activateHook, $networkWide);
    }

    public function registerDeactivator($networkWide)
    {
        Hooks::doAction($this->_deactivateHook, $networkWide);
    }

    public static function registerUninstaller($networkWide)
    {
        Hooks::doAction(self::$_uninstallHook, $networkWide);
    }

    public static function migration()
    {
        // BitSmtpPluginOptions (the db_version bump) always runs LAST so a failed schema migration
        // leaves the version gate open to retry (each migration is idempotent); new schema
        // migrations are inserted before it, not appended after.
        $migrations = [
            'BitSmtpLogsTableMigration',
            'BitSmtpSettingsSeed',
            'BitSmtpEncryptSecrets',
            'BitSmtpCleanupOrphanDeliveryEvents',
            'BitSmtpRetryQueueMigration',
            'BitSmtpEngagementTableMigration',
            'BitSmtpPluginOptions',
        ];

        return [
            'path' => Config::get('BACKEND_PATH')
                . DIRECTORY_SEPARATOR
                . 'db'
                . DIRECTORY_SEPARATOR
                . 'Migrations'
                . DIRECTORY_SEPARATOR,
            'migrations' => $migrations,
        ];
    }

    public static function drop()
    {
        $migrations = [
            'BitSmtpPluginOptions',
            'BitSmtpCleanupOrphanDeliveryEvents',
            'BitSmtpSettingsSeed',
            'BitSmtpRetryQueueMigration',
            'BitSmtpEngagementTableMigration',
            'BitSmtpLogsTableMigration',
            'BitSmtpEncryptSecrets',
        ];

        return [
            'path' => Config::get('BACKEND_PATH')
                . DIRECTORY_SEPARATOR
                . 'db'
                . DIRECTORY_SEPARATOR
                . 'Migrations'
                . DIRECTORY_SEPARATOR,
            'migrations' => $migrations,
        ];
    }

    /**
     * Whether the plugin is active network-wide; gates subsite provisioning to network activations
     * so tables are never created on a site where the plugin isn't running.
     */
    private function isNetworkActive(): bool
    {
        if (!\function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active_for_network(Config::get('BASENAME'));
    }
}
