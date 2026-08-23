<?php

namespace BitApps\SMTP\Settings;

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
                SettingField::int('retry_max_attempts', 3, 'reliability'),
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
     * Whether the preferences blob has been seeded yet, so readers can fall back to legacy options
     * for the window between an update and BitSmtpSettingsSeed running (manage_options-gated).
     */
    public static function exists(): bool
    {
        return !empty(get_option(self::OPTION_NAME, false));
    }
}
