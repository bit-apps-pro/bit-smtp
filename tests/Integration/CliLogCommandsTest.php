<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\CLI\ExportCommand;
use BitApps\SMTP\CLI\LogsCommand;
use BitApps\SMTP\HTTP\Services\LogCsvExporter;
use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Tests\Fake\FakeCliReporter;

/**
 * Drives the logs/export CLI commands against the real log table: they honor the status filter, emit
 * only the safe metadata columns, and (for export) write a defused CSV to a file -- the same
 * LogService::exportRows + LogCsvExporter path the REST export uses.
 *
 * @internal
 *
 * @coversNothing
 */
final class CliLogCommandsTest extends IntegrationTestCase
{
    private LogService $service;

    private LogCsvExporter $exporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables((new Log())->getTable());
        $this->service  = new LogService();
        $this->exporter = new LogCsvExporter();
    }

    public function testLogsRendersOnlyRowsMatchingTheStatusFilterWithSafeColumns(): void
    {
        $this->seed(Log::SUCCESS, 'ok@example.test', 'Delivered');
        $this->seed(Log::ERROR, 'bad@example.test', 'Bounced');

        $reporter = new FakeCliReporter();
        (new LogsCommand($this->service, $this->exporter))->run([], ['status' => 'failed'], $reporter);

        $this->assertCount(1, $reporter->rendered);
        $this->assertSame(LogService::EXPORT_SAFE_COLUMNS, $reporter->rendered[0]['fields']);

        $items = $reporter->rendered[0]['items'];
        $this->assertCount(1, $items);
        $this->assertSame('failed', $items[0]['status']);
        $this->assertArrayNotHasKey('details', $items[0]);
        $this->assertArrayNotHasKey('debug_info', $items[0]);
    }

    public function testExportWritesADefusedSafeCsvToFile(): void
    {
        $this->seed(Log::SUCCESS, 'x@example.test', '=SUM(A1:A2)');
        $this->seed(Log::SUCCESS, 'y@example.test', 'Plain');

        $file     = tempnam(sys_get_temp_dir(), 'bitsmtp-cli-export');
        $reporter = new FakeCliReporter();

        try {
            (new ExportCommand($this->service, $this->exporter))->run([], ['file' => $file], $reporter);

            $this->assertCount(1, $reporter->success);
            $csv = (string) file_get_contents($file);

            $header = str_getcsv(strtok($csv, "\n"), ',', '"', '');
            $this->assertSame(LogService::EXPORT_SAFE_COLUMNS, $header);
            // Formula-injection defusal survives the round-trip through the file.
            $this->assertStringContainsString("'=SUM(A1:A2)", $csv);
        } finally {
            @unlink($file);
        }
    }

    private function seed(int $status, string $to, string $subject): void
    {
        $inserted = $GLOBALS['wpdb']->insert(
            (new Log())->getTable(),
            [
                'status'         => $status,
                'subject'        => $subject,
                'to_addr'        => wp_json_encode([$to]),
                'connection'     => 'Primary SMTP',
                'sender'         => 'from@example.test',
                'created_at'     => '2026-02-01 10:00:00',
                'created_at_utc' => '2026-02-01 10:00:00',
                'updated_at'     => '2026-02-01 10:00:00',
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        $this->assertSame(1, $inserted);
    }
}
