<?php

namespace BitApps\SMTP\Mail\Notifications;

final class NotificationDispatchGuard
{
    private static int $depth = 0;

    /**
     * Run native notification delivery without routing it back through configured providers.
     *
     * @return mixed
     */
    public static function run(callable $callback)
    {
        ++self::$depth;

        try {
            return $callback();
        } finally {
            --self::$depth;
        }
    }

    public static function isActive(): bool
    {
        return self::$depth > 0;
    }
}
