<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Config;

use BitApps\SMTP\Mail\Exceptions\AlertSettingsConflictException;
use BitApps\SMTP\Mail\Exceptions\InvalidAlertChannelException;

/**
 * Save-time guard for alerts: rejects stale revisions, duplicate channel ids, or incomplete enabled channels.
 */
final class AlertSettingsValidator
{
    /**
     * Validate the incoming alerts against the stored ones; a payload without alerts is not checked.
     *
     * @param array<string,mixed>                                                                                      $incomingV2 raw save payload
     * @param array{channels:list<array{id:string,type:string,name:string,enabled:bool,settings:array<string,mixed>}>} $sanitized  resolved + sanitized alerts about to be stored
     *
     * @throws AlertSettingsConflictException when the payload's revision is stale
     * @throws InvalidAlertChannelException   when a channel id repeats or an enabled channel is incomplete
     */
    public static function assertSaveable(array $incomingV2, array $sanitized, MailSettings $current): void
    {
        $incoming = $incomingV2['features']['alerts'] ?? null;
        if (!\is_array($incoming)) {
            return;
        }

        self::assertCurrentRevision($incoming['revision'] ?? null, $current);
        self::assertUniqueIds($incoming['channels'] ?? null);
        self::assertEnabledChannelsComplete(
            $sanitized['channels'],
            AlertSettingsNormalizer::normalize($current->getFeatures()['alerts'] ?? [])['channels']
        );
    }

    /**
     * Reject a submitted revision that no longer matches the stored alerts; an absent one skips the check.
     *
     * @param mixed $revision
     */
    private static function assertCurrentRevision($revision, MailSettings $current): void
    {
        if ($revision === null || $revision === '') {
            return;
        }

        if (!\is_string($revision) || !AlertSettingsRevision::matches($revision, $current)) {
            throw AlertSettingsConflictException::staleRevision();
        }
    }

    /**
     * Reject two submitted channels carrying the same id before normalization would silently re-mint one.
     *
     * @param mixed $channels
     */
    private static function assertUniqueIds($channels): void
    {
        $seen = [];
        foreach (\is_array($channels) ? $channels : [] as $channel) {
            $id = \is_array($channel) ? ($channel['id'] ?? null) : null;
            if (!\is_string($id) || $id === '') {
                continue;
            }
            if (isset($seen[$id])) {
                throw InvalidAlertChannelException::duplicateId();
            }
            $seen[$id] = true;
        }
    }

    /**
     * Reject the first enabled, new-or-edited channel lacking a required setting (loose-matched against stored channels).
     *
     * @param list<array{id:string,type:string,name:string,enabled:bool,settings:array<string,mixed>}> $channels
     * @param list<array{id:string,type:string,name:string,enabled:bool,settings:array<string,mixed>}> $storedChannels
     */
    private static function assertEnabledChannelsComplete(array $channels, array $storedChannels): void
    {
        $storedById = array_column($storedChannels, null, 'id');
        foreach ($channels as $channel) {
            if (!$channel['enabled'] || ($storedById[$channel['id']] ?? null) == $channel) {
                continue;
            }

            $message = AlertChannelSettings::incompleteChannelMessage($channel['type'], $channel['name'], $channel['settings']);
            if ($message !== null) {
                throw InvalidAlertChannelException::incomplete($message);
            }
        }
    }
}
