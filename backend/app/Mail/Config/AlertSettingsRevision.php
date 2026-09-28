<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Config;

use UnexpectedValueException;

/**
 * Opaque fingerprint of alerts blob for optimistic concurrency; keyed with auth salt.
 */
final class AlertSettingsRevision
{
    private const LENGTH = 32;

    /**
     * The revision of the alerts stored in $settings.
     *
     * @throws UnexpectedValueException when the alerts cannot be JSON-encoded
     */
    public static function ofStored(MailSettings $settings): string
    {
        $json = wp_json_encode(AlertSettingsNormalizer::normalize($settings->getFeatures()['alerts'] ?? []));
        if (!\is_string($json)) {
            throw new UnexpectedValueException('Alert settings could not be encoded for their revision.');
        }

        return substr(hash_hmac('sha256', $json, wp_salt('auth')), 0, self::LENGTH);
    }

    /**
     * True when the submitted revision still matches the alerts stored in $settings.
     */
    public static function matches(string $revision, MailSettings $settings): bool
    {
        return hash_equals(self::ofStored($settings), $revision);
    }
}
