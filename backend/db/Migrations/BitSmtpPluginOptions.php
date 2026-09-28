<?php

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPKit\Migration\Migration;
use BitApps\SMTP\Mail\Health\ConnectionHealthStore;
use BitApps\SMTP\Mail\Health\HealthProbeRunner;
use BitApps\SMTP\Settings\PluginSettings;
use BitApps\SMTP\Settings\UninstallPurge;

if (!\defined('ABSPATH')) {
    exit;
}

final class BitSmtpPluginOptions extends Migration
{
    /**
     * Every bit_smtp_* option key outside preferences blob (includes encrypted store + legacy plaintext backup).
     */
    private const PURGEABLE_OPTION_KEYS = [
        'db_version',
        'installed',
        'version',
        'settings',
        'old_version',
        'log_retention',
        'log_deleted_at',
        'tracking_skipped',
        'test_mail_form_submitted',
        'options',
        'options_v1_backup',
        'logging_enabled',
        'failure_notification_active',
        'failure_resolved_incidents',
    ];

    public function up()
    {
        Config::updateOption('db_version', Config::DB_VERSION, true);
        Config::updateOption('installed', time(), true);
        Config::updateOption('version', Config::VERSION, true);
    }

    /**
     * Purge bit_smtp_* options and unschedule every plugin cron hook, unless uninstall_purge is disabled.
     */
    public function down()
    {
        if (!UninstallPurge::shouldPurge()) {
            return;
        }

        foreach (self::PURGEABLE_OPTION_KEYS as $key) {
            Config::deleteOption($key);
        }
        Config::deleteOption(Config::LOGGING_CONTINUITY_FROM_OPTION);
        delete_option(PluginSettings::OPTION_NAME);
        delete_option(ConnectionHealthStore::OPTION_NAME);
        delete_option(HealthProbeRunner::LAST_RUN_OPTION);

        foreach (Config::scheduledHooks() as $hook) {
            wp_unschedule_hook($hook);
        }
    }
}
