<?php

namespace BitApps\SMTP\CLI;

\defined('ABSPATH') || exit();

/**
 * Output sink for the WP-CLI commands, abstracting the WP_CLI static surface so command logic stays
 * unit-testable with a fake and never couples to the (test-absent) WP_CLI class directly.
 */
interface CliReporter
{
    /**
     * Emit a terminal success message.
     */
    public function success(string $message): void;

    /**
     * Emit a terminal failure message; under WP-CLI this halts the command, so callers must return
     * right after calling it.
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
