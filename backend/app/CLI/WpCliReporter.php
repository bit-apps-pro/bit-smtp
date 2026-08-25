<?php

namespace BitApps\SMTP\CLI;

use WP_CLI;

\defined('ABSPATH') || exit();

/**
 * Production CliReporter writing through the real WP_CLI static API. Only ever instantiated from an
 * active WP-CLI request (CliServiceProvider guards on the WP_CLI constant before wiring commands).
 */
final class WpCliReporter implements CliReporter
{
    /**
     * Emit a terminal success message.
     */
    public function success(string $message): void
    {
        WP_CLI::success($message);
    }

    /**
     * Emit a terminal failure message; WP_CLI::error halts the command.
     */
    public function error(string $message): void
    {
        WP_CLI::error($message);
    }

    /**
     * Emit a non-fatal warning to STDERR.
     */
    public function warning(string $message): void
    {
        WP_CLI::warning($message);
    }

    /**
     * Emit one plain line to STDOUT.
     */
    public function line(string $message): void
    {
        WP_CLI::line($message);
    }

    /**
     * Render items through WP-CLI's formatter when present, degrading to a JSON dump otherwise so a
     * non-standard WP-CLI build still produces usable output rather than nothing.
     *
     * @param array<int,array<string,mixed>> $items
     * @param array<int,string>              $fields
     */
    public function renderItems(string $format, array $items, array $fields): void
    {
        if (\function_exists('WP_CLI\\Utils\\format_items')) {
            \WP_CLI\Utils\format_items($format, $items, $fields);

            return;
        }

        WP_CLI::line((string) wp_json_encode($items));
    }
}
