<?php

namespace BitApps\SMTP\Mail\Notifications;

/**
 * Queue background alerts via WP-Cron and spawn runner at shutdown.
 */
class AlertScheduler
{
    private bool $kickRegistered = false;

    /**
     * Schedule one alert event (identifier-only args) and arrange for cron to run it promptly.
     *
     * @param array<int,int|string> $args
     */
    public function queue(string $hook, array $args, int $timestamp): void
    {
        if (wp_schedule_single_event($timestamp, $hook, $args) === false) {
            return;
        }

        $this->kickCronOnShutdown();
    }

    /**
     * Check if event with matching args is queued to prevent duplicate pileup.
     *
     * @param array<int,int|string> $argPrefix
     */
    public function hasPending(string $hook, array $argPrefix): bool
    {
        foreach (self::events($hook) as [, $args]) {
            if (\array_slice($args, 0, \count($argPrefix)) === $argPrefix) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deliver immediately in WP-Cron context; otherwise queue once due and not already pending.
     *
     * @param array<int,int|string> $argPrefix
     */
    public function deliverOrQueue(callable $deliverNow, callable $isDue, string $hook, array $argPrefix, int $now): void
    {
        if (wp_doing_cron()) {
            $deliverNow();

            return;
        }

        if (!$isDue() || $this->hasPending($hook, $argPrefix)) {
            return;
        }

        $this->queue($hook, [...$argPrefix, $now], $now);
    }

    /**
     * Every [timestamp, args] pair currently scheduled for $hook, across all due times.
     *
     * @return iterable<int,array{0:int,1:array<int,int|string>}>
     */
    public static function events(string $hook): iterable
    {
        $crons = _get_cron_array();

        foreach (\is_array($crons) ? $crons : [] as $timestamp => $hooks) {
            foreach (\is_array($hooks) ? ($hooks[$hook] ?? []) : [] as $event) {
                yield [(int) $timestamp, $event['args'] ?? []];
            }
        }
    }

    /**
     * True when the site turned off WP-Cron's page-load runner (it relies on a system cron instead).
     */
    public static function isWpCronDisabled(): bool
    {
        return \defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
    }

    /**
     * Register shutdown spawn_cron() once per request if cron enabled and not already running.
     */
    private function kickCronOnShutdown(): void
    {
        if ($this->kickRegistered || self::isWpCronDisabled() || self::isAlternateWpCron() || wp_doing_cron()) {
            return;
        }

        $this->kickRegistered = true;
        add_action('shutdown', static function (): void {
            spawn_cron();
        });
    }

    /**
     * True when the site runs WP-Cron through the redirect-based alternate mode.
     */
    private static function isAlternateWpCron(): bool
    {
        return \defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON;
    }
}
