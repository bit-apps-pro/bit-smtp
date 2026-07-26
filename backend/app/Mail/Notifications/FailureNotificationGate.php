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
        // notifySuccess() runs on every successful send; delete_option() always issues a DB SELECT,
        // so skip it when the (non-autoloaded) flag isn't set — the common case for sites without alerts.
        if (Config::getOption(self::OPTION, null) !== null) {
            Config::deleteOption(self::OPTION);
        }
    }
}
