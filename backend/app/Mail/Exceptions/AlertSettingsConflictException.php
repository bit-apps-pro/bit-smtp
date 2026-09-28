<?php

namespace BitApps\SMTP\Mail\Exceptions;

use RuntimeException;

/**
 * An alerts save was based on a revision that another save has since replaced.
 */
class AlertSettingsConflictException extends RuntimeException
{
    /**
     * The submitted revision no longer matches the stored alerts.
     */
    public static function staleRevision(): self
    {
        return new self(__('Notification settings were changed in another tab. Reload to see the latest.', 'bit-smtp'));
    }
}
