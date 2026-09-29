<?php

namespace BitApps\SMTP\Providers;

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPKit\Hooks\Hooks;
use BitApps\SMTP\Deps\BitApps\WPKit\Installer;
use BitApps\SMTP\Mail\Notifications\FailureIncidentStore;

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

    /**
     * Unschedule every plugin cron hook and drop failure incident state.
     *
     * @param mixed $networkWide
     */
    public function deactivate($networkWide)
    {
        foreach (Config::scheduledHooks() as $hook) {
            wp_unschedule_hook($hook);
        }
        foreach (FailureIncidentStore::optionNames() as $optionName) {
            delete_option($optionName);
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

    /**
     * Migration list with BitSmtpPluginOptions last so failed schema migrations are retryable (idempotent).
     */
    public static function migration()
    {
        $migrations = [
            'BitSmtpLogsTableMigration',
            'BitSmtpSettingsSeed',
            'BitSmtpEncryptSecrets',
            'BitSmtpCleanupOrphanDeliveryEvents',
            'BitSmtpRetryQueueMigration',
            'BitSmtpEngagementTableMigration',
            'BitSmtpAlertingPreferencesUpgrade',
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
}
