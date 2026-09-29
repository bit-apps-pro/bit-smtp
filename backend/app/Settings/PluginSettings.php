<?php

namespace BitApps\SMTP\Settings;

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPKit\Settings\SettingField;
use BitApps\SMTP\Deps\BitApps\WPKit\Settings\SettingsRepository;
use BitApps\SMTP\Deps\BitApps\WPKit\Settings\SettingsSchema;
use BitApps\SMTP\Mail\Dispatch\FailureCategory;
use BitApps\SMTP\Mail\Notifications\AlertEvents;

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

    public const LOG_RETENTION_DAYS_RANGE = [1, 200];

    /**
     * Upper bound keeps a hung provider from stalling a PHP request past typical max_execution_time.
     */
    public const SEND_TIMEOUT_SECONDS_RANGE = [5, 120];

    public const RETRY_MAX_ATTEMPTS_RANGE = [1, 10];

    /**
     * Build the five-group preferences schema, one group per Settings tab.
     */
    public static function schema(): SettingsSchema
    {
        return (new SettingsSchema())
            ->add(
                SettingField::bool('logging_enabled', true, 'general'),
                SettingField::int('log_retention_days', 30, 'general', self::clampTo(self::LOG_RETENTION_DAYS_RANGE)),
                SettingField::enum('log_store_body', ['full', 'redacted', 'metadata'], 'full', 'general')
            )
            ->add(
                SettingField::int('send_timeout_seconds', 30, 'reliability', self::clampTo(self::SEND_TIMEOUT_SECONDS_RANGE)),
                SettingField::bool('retry_enabled', false, 'reliability'),
                SettingField::int('retry_max_attempts', 3, 'reliability', self::clampTo(self::RETRY_MAX_ATTEMPTS_RANGE)),
                SettingField::enum('retry_backoff', ['exponential', 'fixed'], 'exponential', 'reliability'),
                SettingField::arr('retry_on_classes', FailureCategory::retryableClasses(), 'reliability', self::keepOnly(FailureCategory::retryableClasses()))
            )
            ->add(
                SettingField::bool('health_check_enabled', false, 'health'),
                SettingField::enum('health_check_interval', ['hourly', 'twicedaily', 'daily'], 'daily', 'health')
            )
            ->add(
                SettingField::arr('notify_events', AlertEvents::keys(), 'notifications', self::keepOnly(AlertEvents::keys()))
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
     * Whether open/click tracking is enabled.
     */
    public static function isTrackingEnabled(): bool
    {
        return (bool) self::make()->get('tracking_enabled', false);
    }

    /**
     * Read preference from seeded blob (cast via schema) or fall back to legacy option (pre-seed window).
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

    /**
     * A sanitizer clamping an integer into the inclusive [min, max] range.
     *
     * @param array{0:int,1:int} $range
     */
    private static function clampTo(array $range): callable
    {
        return static fn ($value): int => max($range[0], min($range[1], (int) $value));
    }

    /**
     * A sanitizer keeping only the allowed members of a list, in canonical order and without duplicates.
     *
     * @param string[] $allowed
     */
    private static function keepOnly(array $allowed): callable
    {
        return static fn (array $value): array => array_values(array_intersect($allowed, $value));
    }
}
