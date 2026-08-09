<?php

namespace BitApps\SMTP\Mail\Notifications;

use BitApps\SMTP\Config;

class FailureNotificationGate
{
    private const OPTION = 'failure_notification_active';

    public function acquire(): bool
    {
        return Config::addOption(self::OPTION, [
            'started_at' => gmdate(DATE_ATOM),
        ]);
    }

    public function reset(): void
    {
        // Compare-and-delete the exact marker we observed. A plain get_option() followed by
        // delete_option() can otherwise erase a new failure streak after another request resets
        // the old marker and the new streak acquires its own one.
        $marker = Config::getOption(self::OPTION, false);
        if ($marker !== false) {
            Config::deleteOptionIfUnchanged(self::OPTION, $marker);
        }
    }
}
