<?php

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPKit\Migration\Migration;
use BitApps\SMTP\Settings\PluginSettings;
use BitApps\SMTP\Settings\UninstallPurge;

if (! \defined('ABSPATH')) {
    exit;
}

/**
 * Seeds the bit_smtp_preferences store from legacy scattered options; idempotent no-op once that blob exists.
 */
final class BitSmtpSettingsSeed extends Migration
{
    public function up()
    {
        // Never overwrite an existing blob: it may already hold user-modified preferences.
        if (! empty(get_option(PluginSettings::OPTION_NAME))) {
            return;
        }

        $settings = PluginSettings::make();

        // Config::getOption() prefixes with bit_smtp_; null default distinguishes "never set" from a legitimate falsy legacy value (e.g. logging disabled).
        $loggingEnabled = Config::getOption('logging_enabled', null);
        if ($loggingEnabled !== null) {
            $settings->set('logging_enabled', (bool) $loggingEnabled);
        }

        $logRetention = Config::getOption('log_retention', null);
        if ($logRetention !== null) {
            $settings->set('log_retention_days', (int) $logRetention);
        }

        $settings->save();
    }

    public function down()
    {
        // Read the purge flag before this method's own delete_option() below can remove it.
        if (!UninstallPurge::shouldPurge()) {
            return;
        }

        delete_option(PluginSettings::OPTION_NAME);
    }
}
