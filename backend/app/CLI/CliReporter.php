<?php

namespace BitApps\SMTP\CLI;

\defined('ABSPATH') || exit();

/**
 * Output sink for WP-CLI commands, abstracting the static surface for unit testability.
 */
interface CliReporter
{
    /**
     * Emit a terminal success message.
     */
    public function success(string $message): void;

    /**
     * Emit terminal failure message (halts command, caller must return after).
     */
    public function error(string $message): void;

    /**
     * Emit a non-fatal warning (WP-CLI routes it to STDERR, so it never corrupts piped STDOUT output).
     */
    public function warning(string $message): void;

    /**
     * Emit one plain line of output to STDOUT.
     */
    public function line(string $message): void;

    /**
     * Render tabular data in the requested format (table|csv|json|yaml|ids|count).
     *
     * @param array<int,array<string,mixed>> $items
     * @param array<int,string>              $fields
     */
    public function renderItems(string $format, array $items, array $fields): void;
}
