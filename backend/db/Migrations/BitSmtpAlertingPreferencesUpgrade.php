<?php

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPKit\Migration\Migration;
use BitApps\SMTP\Mail\Config\AlertSettingsNormalizer;
use BitApps\SMTP\Settings\PluginSettings;
use BitApps\SMTP\Settings\PreferencesUpgrade;

if (! \defined('ABSPATH')) {
    exit;
}

/**
 * DB 2.8: explicit retry-class list, send failures as an alert subscription, and one merged repeat interval.
 */
final class BitSmtpAlertingPreferencesUpgrade extends Migration
{
    /**
     * Mail-settings option (without the plugin prefix) holding the `features.alerts` blob.
     */
    private const MAIL_OPTIONS_KEY = 'options';

    /**
     * Upgrade the stored preferences once; the removed cooldown key doubles as the not-yet-migrated marker.
     */
    public function up()
    {
        $stored = get_option(PluginSettings::OPTION_NAME, []);
        if (!\is_array($stored) || $stored === []) {
            return;
        }

        if (PreferencesUpgrade::isLegacyAlerting($stored)) {
            $this->mergeRepeatInterval((int) $stored[PreferencesUpgrade::LEGACY_COOLDOWN_KEY]);
        }

        $upgraded = PreferencesUpgrade::upgrade($stored);
        if ($upgraded !== $stored) {
            update_option(PluginSettings::OPTION_NAME, $upgraded, true);
        }
    }

    /**
     * No-op: the upgrade is forward-only and the preferences option is dropped by BitSmtpSettingsSeed.
     */
    public function down()
    {
    }

    /**
     * Fold the health cooldown into `reminder_minutes`, editing the raw (still-encrypted) option in place.
     */
    private function mergeRepeatInterval(int $cooldownMinutes): void
    {
        $options = Config::getOption(self::MAIL_OPTIONS_KEY, []);
        if (!\is_array($options) || !isset($options['features']) || !\is_array($options['features'])) {
            return;
        }

        $alerts  = $options['features']['alerts'] ?? [];
        $current = AlertSettingsNormalizer::normalize($alerts)['reminder_minutes'];
        $merged  = PreferencesUpgrade::mergedRepeatMinutes($current, $cooldownMinutes);
        if ($merged === $current) {
            return;
        }

        $options['features']['alerts']                     = \is_array($alerts) ? $alerts : [];
        $options['features']['alerts']['reminder_minutes'] = $merged;
        Config::updateOption(self::MAIL_OPTIONS_KEY, $options);
    }
}
