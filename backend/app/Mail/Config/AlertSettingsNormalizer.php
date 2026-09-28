<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Config;

/**
 * Normalize alerts blob to canonical {enabled, reminder_minutes, channels[]} shape.
 */
final class AlertSettingsNormalizer
{
    private const MAX_CHANNELS = 20;

    private const MAX_NAME_LENGTH = 60;

    private const ID_PATTERN = '/^ch_[a-z0-9]{6,32}$/';

    private const DEFAULT_REMINDER_MINUTES = 60;

    private const MAX_REMINDER_MINUTES = 1440;

    /**
     * Normalize an alerts blob into the canonical list shape.
     *
     * @param mixed $alerts
     *
     * @return array{enabled:bool,reminder_minutes:int,channels:list<array{id:string,type:string,name:string,enabled:bool,settings:array<string,mixed>}>}
     */
    public static function normalize($alerts): array
    {
        $alerts   = \is_array($alerts) ? $alerts : [];
        $channels = $alerts['channels'] ?? [];

        return [
            'enabled'          => self::bool($alerts['enabled'] ?? false),
            'reminder_minutes' => self::reminderMinutes($alerts['reminder_minutes'] ?? null),
            'channels'         => self::channels(\is_array($channels) ? $channels : []),
        ];
    }

    /**
     * Minutes between still-failing reminders (0 = off), clamped to 0..MAX; non-integers use the default.
     *
     * @param mixed $minutes
     */
    private static function reminderMinutes($minutes): int
    {
        $minutes = filter_var($minutes, FILTER_VALIDATE_INT);
        if ($minutes === false) {
            return self::DEFAULT_REMINDER_MINUTES;
        }

        return max(0, min(self::MAX_REMINDER_MINUTES, $minutes));
    }

    /**
     * Normalize each instance, dropping unknown types and capping the list at MAX_CHANNELS.
     *
     * @param array<int|string,mixed> $raw
     *
     * @return list<array{id:string,type:string,name:string,enabled:bool,settings:array<string,mixed>}>
     */
    private static function channels(array $raw): array
    {
        $channels = [];
        $usedIds  = [];
        foreach ($raw as $item) {
            if (!\is_array($item) || !AlertChannelSettings::isType($item['type'] ?? null)) {
                continue;
            }

            $channel                 = self::channel($item, $usedIds);
            $usedIds[$channel['id']] = true;
            $channels[]              = $channel;
            if (\count($channels) === self::MAX_CHANNELS) {
                break;
            }
        }

        return $channels;
    }

    /**
     * Normalize one known-type instance, regenerating an invalid or already-used id.
     *
     * @param array<string,mixed> $item
     * @param array<string,true>  $usedIds
     *
     * @return array{id:string,type:string,name:string,enabled:bool,settings:array<string,mixed>}
     */
    private static function channel(array $item, array $usedIds): array
    {
        $type     = (string) $item['type'];
        $settings = isset($item['settings']) && \is_array($item['settings']) ? $item['settings'] : [];

        return [
            'id'       => self::id($item['id'] ?? null, $usedIds),
            'type'     => $type,
            'name'     => self::name($item['name'] ?? null, $type),
            'enabled'  => self::bool($item['enabled'] ?? false),
            'settings' => array_intersect_key($settings, array_flip(AlertChannelSettings::SETTING_KEYS[$type])),
        ];
    }

    /**
     * Keep a well-formed, unused id; otherwise mint a new unique one.
     *
     * @param mixed              $id
     * @param array<string,true> $usedIds
     */
    private static function id($id, array $usedIds): string
    {
        if (\is_string($id) && preg_match(self::ID_PATTERN, $id) === 1 && !isset($usedIds[$id])) {
            return $id;
        }

        do {
            $id = 'ch_' . bin2hex(random_bytes(6));
        } while (isset($usedIds[$id]));

        return $id;
    }

    /**
     * Sanitize a display name to plain text within MAX_NAME_LENGTH, falling back to the type label.
     *
     * @param mixed $name
     */
    private static function name($name, string $type): string
    {
        $name = \is_scalar($name) ? trim(mb_substr(sanitize_text_field((string) $name), 0, self::MAX_NAME_LENGTH)) : '';

        return $name !== '' ? $name : AlertChannelSettings::typeLabel($type);
    }

    /**
     * Coerce a loosely typed flag ('1', 'true', true, ...) to a real bool.
     *
     * @param mixed $value
     */
    private static function bool($value): bool
    {
        return (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
