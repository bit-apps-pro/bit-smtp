<?php

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPKit\Migration\Migration;
use BitApps\SMTP\Settings\PluginSettings;

if (!\defined('ABSPATH')) {
    exit;
}

final class BitSmtpPluginOptions extends Migration
{
    /**
     * Every bare (unprefixed) bit_smtp_* option key ever written outside the preferences blob,
     * including the encrypted credential store (`options`) and its legacy plaintext backup
     * (`options_v1_backup`) — the two keys a prior version of this migration LEFT BEHIND on
     * uninstall.
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
    ];

    public function up()
    {
        Config::updateOption('db_version', Config::DB_VERSION, true);
        Config::updateOption('installed', time(), true);
        Config::updateOption('version', Config::VERSION, true);
    }

    /**
     * Purges every bit_smtp_* option — including the encrypted credential store and its legacy
     * plaintext backup — unless the user opted out via the `uninstall_purge` preference. Leaves
     * everything untouched when the user chose to keep their data.
     */
    public function down()
    {
        if (!self::shouldPurge()) {
            return;
        }

        foreach (self::PURGEABLE_OPTION_KEYS as $key) {
            Config::deleteOption($key);
        }
        Config::deleteOption(Config::LOGGING_CONTINUITY_FROM_OPTION);
        delete_option(PluginSettings::OPTION_NAME);

        wp_clear_scheduled_hook(Config::RETENTION_GC_HOOK);
    }

    /**
     * Resolve the uninstall-purge preference before the preferences blob is deleted, defaulting to
     * true (purge) — the schema default and the security-safe choice — if it can't be read.
     */
    private static function shouldPurge(): bool
    {
        try {
            return (bool) PluginSettings::make()->get('uninstall_purge', true);
        } catch (\Throwable $e) {
            return true;
        }
    }
}
