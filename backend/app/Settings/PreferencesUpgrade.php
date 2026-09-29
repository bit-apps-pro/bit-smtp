<?php

namespace BitApps\SMTP\Settings;

use BitApps\SMTP\Mail\Dispatch\FailureCategory;
use BitApps\SMTP\Mail\Notifications\AlertEvents;

\defined('ABSPATH') || exit();

/**
 * Lossless rewrite of pre-DB 2.8 preference blobs (stored or imported) into the current explicit shape.
 */
final class PreferencesUpgrade
{
    /**
     * Removed health-only alert throttle; its value now folds into the alerts `reminder_minutes` control.
     */
    public const LEGACY_COOLDOWN_KEY = 'notify_cooldown_minutes';

    /**
     * Preset intervals the unified "Repeat while the problem continues" control offers; 0 = don't repeat.
     */
    public const REPEAT_PRESETS_MINUTES = [0, 15, 30, 60, 240, 1440];

    private const CLAMPED_INT_KEYS = ['send_timeout_seconds', 'retry_max_attempts'];

    /**
     * Upgrade a preferences array; idempotent, and a no-op for a blob already in the current shape.
     *
     * @param array<string,mixed> $preferences
     *
     * @return array<string,mixed>
     */
    public static function upgrade(array $preferences): array
    {
        if (self::isLegacyAlerting($preferences)) {
            $preferences                  = self::withExplicitRetryClasses($preferences);
            $preferences['notify_events'] = self::withSendFailure($preferences['notify_events'] ?? []);
            unset($preferences[self::LEGACY_COOLDOWN_KEY]);
        }

        return self::clampIntegers($preferences);
    }

    /**
     * True for a blob from before send-failure alerts became an explicit `notify_events` subscription.
     *
     * @param array<string,mixed> $preferences
     */
    public static function isLegacyAlerting(array $preferences): bool
    {
        return \array_key_exists(self::LEGACY_COOLDOWN_KEY, $preferences);
    }

    /**
     * Merge failure reminder + health cooldown into one repeat interval: 0 stays off, else the quieter wins, snapped up to a preset.
     */
    public static function mergedRepeatMinutes(int $reminderMinutes, int $cooldownMinutes): int
    {
        if ($reminderMinutes <= 0) {
            return 0;
        }

        $merged = max($reminderMinutes, $cooldownMinutes);
        foreach (self::REPEAT_PRESETS_MINUTES as $preset) {
            if ($preset >= $merged) {
                return $preset;
            }
        }

        return max(self::REPEAT_PRESETS_MINUTES);
    }

    /**
     * Legacy `retry_on_classes: []` meant "retry every retryable class"; today an empty list means retry nothing.
     *
     * @param array<string,mixed> $preferences
     *
     * @return array<string,mixed>
     */
    private static function withExplicitRetryClasses(array $preferences): array
    {
        if (\array_key_exists('retry_on_classes', $preferences) && $preferences['retry_on_classes'] === []) {
            $preferences['retry_on_classes'] = FailureCategory::retryableClasses();
        }

        return $preferences;
    }

    /**
     * Legacy send-failure alerts were implicit, so every legacy subscriber keeps receiving them.
     *
     * @param mixed $events
     *
     * @return string[]
     */
    private static function withSendFailure($events): array
    {
        $events = \is_array($events) ? $events : [];

        return array_values(array_intersect(AlertEvents::keys(), array_merge([AlertEvents::SEND_FAILURE], $events)));
    }

    /**
     * Pull stored out-of-range timeout/attempt values into their now-enforced bounds.
     *
     * @param array<string,mixed> $preferences
     *
     * @return array<string,mixed>
     */
    private static function clampIntegers(array $preferences): array
    {
        $schema = PluginSettings::schema();
        foreach (self::CLAMPED_INT_KEYS as $key) {
            if (\array_key_exists($key, $preferences)) {
                $preferences[$key] = $schema->field($key)->cast($preferences[$key]);
            }
        }

        return $preferences;
    }
}
