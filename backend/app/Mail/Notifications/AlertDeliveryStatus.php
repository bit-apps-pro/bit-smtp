<?php

namespace BitApps\SMTP\Mail\Notifications;

use BitApps\SMTP\Config;

/**
 * Expose alert delivery health: WP-Cron status and overdue queue check.
 */
final class AlertDeliveryStatus
{
    public const OVERDUE_AFTER_SECONDS = 600;

    private const ALERT_HOOKS = [Config::FAILURE_ALERT_HOOK, Config::HEALTH_ALERT_HOOK];

    /**
     * The current delivery status as the API exposes it.
     *
     * @return array{cron_disabled:bool,overdue:bool}
     */
    public static function snapshot(int $now): array
    {
        return [
            'cron_disabled' => AlertScheduler::isWpCronDisabled(),
            'overdue'       => self::hasOverdueAlert($now - self::OVERDUE_AFTER_SECONDS),
        ];
    }

    /**
     * True when an alert event is still queued with a due time before $cutoff.
     */
    private static function hasOverdueAlert(int $cutoff): bool
    {
        foreach (self::ALERT_HOOKS as $hook) {
            foreach (AlertScheduler::events($hook) as [$timestamp]) {
                if ($timestamp < $cutoff) {
                    return true;
                }
            }
        }

        return false;
    }
}
