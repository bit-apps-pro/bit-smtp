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
        if (! empty(get_option(PluginSettings::OPTION_NAME))) {
            return;
        }

        $settings = PluginSettings::make();

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
        if (!UninstallPurge::shouldPurge()) {
            return;
        }

        delete_option(PluginSettings::OPTION_NAME);
    }
}
