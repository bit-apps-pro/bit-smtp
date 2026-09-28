<?php

namespace BitApps\SMTP\Mail\Exceptions;

use InvalidArgumentException;

/**
 * Unusable alert channel; message safe for admin display (never carries secrets).
 */
class InvalidAlertChannelException extends InvalidArgumentException
{
    /**
     * The submitted type is not a known, registered alert channel.
     */
    public static function unknownType(): self
    {
        return new self(__('Unknown notification channel type.', 'bit-smtp'));
    }

    /**
     * The channel lacks a required field; $message names what to add.
     */
    public static function incomplete(string $message): self
    {
        return new self($message);
    }

    /**
     * Two submitted channels carry the same id, so one would silently overwrite the other's secrets.
     */
    public static function duplicateId(): self
    {
        return new self(__('Two notification channels share the same ID. Reload the page and try again.', 'bit-smtp'));
    }
}
