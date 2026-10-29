<?php

declare(strict_types=1);

namespace BitApps\SMTP\Support;

\defined('ABSPATH') || exit();

/**
 * Plugin-prefixed diagnostics written to the PHP error log only while WordPress debug logging is on.
 */
final class DebugLog
{
    private const PREFIX = 'Bit SMTP: ';

    /**
     * Log a message when WP_DEBUG and WP_DEBUG_LOG are both enabled; a silent no-op otherwise.
     */
    public static function write(string $message): void
    {
        if (!self::enabled()) {
            return;
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Gated on WP_DEBUG_LOG.
        error_log(self::PREFIX . $message);
    }

    /**
     * Always log a message for an unrecoverable data-loss event the admin must be able to trace.
     */
    public static function error(string $message): void
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Records data loss even with debug off.
        error_log(self::PREFIX . $message);
    }

    /**
     * Whether WordPress debug logging is switched on for this request.
     */
    private static function enabled(): bool
    {
        return \defined('WP_DEBUG') && WP_DEBUG && \defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;
    }
}
