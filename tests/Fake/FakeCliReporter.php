<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Fake;

use BitApps\SMTP\CLI\CliReporter;

/**
 * In-memory CliReporter that records every call instead of writing to WP-CLI, so command-logic tests
 * can assert on exactly what a command reported without loading the real WP_CLI class.
 */
final class FakeCliReporter implements CliReporter
{
    /**
     * @var array<int,string>
     */
    public array $success = [];

    /**
     * @var array<int,string>
     */
    public array $error = [];

    /**
     * @var array<int,string>
     */
    public array $warning = [];

    /**
     * @var array<int,string>
     */
    public array $lines = [];

    /**
     * @var array<int,array{format:string,items:array<int,array<string,mixed>>,fields:array<int,string>}>
     */
    public array $rendered = [];

    /**
     * Record a success message.
     */
    public function success(string $message): void
    {
        $this->success[] = $message;
    }

    /**
     * Record a terminal error message (does not halt, unlike the real WP_CLI::error).
     */
    public function error(string $message): void
    {
        $this->error[] = $message;
    }

    /**
     * Record a warning message.
     */
    public function warning(string $message): void
    {
        $this->warning[] = $message;
    }

    /**
     * Record a plain output line.
     */
    public function line(string $message): void
    {
        $this->lines[] = $message;
    }

    /**
     * Record a tabular render request verbatim.
     *
     * @param array<int,array<string,mixed>> $items
     * @param array<int,string>              $fields
     */
    public function renderItems(string $format, array $items, array $fields): void
    {
        $this->rendered[] = ['format' => $format, 'items' => $items, 'fields' => $fields];
    }
}
