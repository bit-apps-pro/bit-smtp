<?php

namespace BitApps\SMTP\Settings;

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPKit\Settings\SettingField;
use BitApps\SMTP\Deps\BitApps\WPKit\Settings\SettingsRepository;
use BitApps\SMTP\Deps\BitApps\WPKit\Settings\SettingsSchema;

\defined('ABSPATH') || exit();

/**
 * Defines the plugin's global preferences schema and builds the repository backing it.
 */
class PluginSettings
{
    /**
     * Dedicated preferences option key; distinct from the vestigial legacy `bit_smtp_settings` option.
     */
    public const OPTION_NAME = 'bit_smtp_preferences';

    /**
     * Build the four-group preferences schema (General & Logging, Reliability, Health & Notifications, Privacy & Data).
     */
    public static function schema(): SettingsSchema
    {
        return (new SettingsSchema())
            ->add(
                SettingField::bool('logging_enabled', true, 'general'),
                SettingField::int('log_retention_days', 30, 'general', static function ($value) {
                    return max(1, min(200, (int) $value));
                }),
                SettingField::enum('log_store_body', ['full', 'redacted', 'metadata'], 'full', 'general')
            )
            ->add(
                SettingField::int('send_timeout_seconds', 30, 'reliability'),
                SettingField::bool('retry_enabled', false, 'reliability'),
                SettingField::int('retry_max_attempts', 3, 'reliability', static function ($value) {
                    // Clamp to the queue's max_attempts tinyint-unsigned column range; an out-of-range
                    // value makes the row INSERT fail silently under MySQL strict mode, dropping the retry.
                    return max(1, min(255, (int) $value));
                }),
                SettingField::enum('retry_backoff', ['exponential', 'fixed'], 'exponential', 'reliability'),
                SettingField::arr('retry_on_classes', [], 'reliability')
            )
            ->add(
                SettingField::bool('health_check_enabled', false, 'health'),
                SettingField::enum('health_check_interval', ['hourly', 'twicedaily', 'daily'], 'daily', 'health'),
                SettingField::int('notify_cooldown_minutes', 0, 'health'),
                SettingField::arr('notify_events', [], 'health')
            )
            ->add(
                SettingField::bool('uninstall_purge', true, 'privacy'),
                SettingField::bool('tracking_enabled', false, 'privacy')
            );
    }

    /**
     * Build the settings repository over the dedicated, autoloaded `bit_smtp_preferences` option.
     */
    public static function make(): SettingsRepository
    {
        return new SettingsRepository(self::OPTION_NAME, self::schema(), true);
    }

    /**
     * Read a preference, preferring the seeded blob (read once, cast via schema) and falling back to
     * the legacy standalone option for the window before BitSmtpSettingsSeed (manage_options-gated) runs.
     *
     * @param mixed $default
     *
     * @return mixed
     */
    public static function getWithLegacyFallback(string $prefKey, string $legacyKey, $default)
    {
        $blob = get_option(self::OPTION_NAME, false);
        if (\is_array($blob) && $blob !== []) {
            $field = self::schema()->field($prefKey);
            if ($field !== null) {
                return \array_key_exists($prefKey, $blob) ? $field->cast($blob[$prefKey]) : $field->default();
            }
        }

        return Config::getOption($legacyKey, $default);
    }
}
