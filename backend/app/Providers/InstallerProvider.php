<?php

namespace BitApps\SMTP\Providers;

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPKit\Hooks\Hooks;
use BitApps\SMTP\Deps\BitApps\WPKit\Installer;

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
}
