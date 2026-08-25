<?php

namespace BitApps\SMTP\Tests\Unit\CLI;

use BitApps\SMTP\CLI\ExportCommand;
use BitApps\SMTP\HTTP\Services\LogCsvExporter;
use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use BitApps\SMTP\Tests\Fake\FakeCliReporter;
use Mockery;

/**
 * Covers the export command logic: it reuses LogService::exportRowsWithTruncation + the shared
 * LogCsvExporter to build a bounded, truncation-flagged CSV, then either dumps it cleanly to STDOUT or
 * writes it to a file. The safe-column projection and formula defusal are covered by LogCsvExporterTest.
 *
 * @internal
 *
 * @coversNothing
 */
final class ExportCommandTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];
    }

    public function testDumpsCsvToStdoutWithoutASuccessLineSoPipingStaysClean(): void
    {
        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('exportRowsWithTruncation')->once()->with([])
            ->andReturn(['rows' => [$this->log(1, 'Hello')], 'truncated' => false]);

        $reporter = new FakeCliReporter();
        (new ExportCommand($logService, new LogCsvExporter()))->run([], [], $reporter);

        $this->assertCount(1, $reporter->lines);
        $this->assertStringContainsString('id,created_at,status', $reporter->lines[0]);
        $this->assertStringContainsString('Hello', $reporter->lines[0]);
        $this->assertSame([], $reporter->success, 'STDOUT dump must not print a Success: line that would corrupt the CSV');
    }

    public function testWritesCsvToTheGivenFileAndReportsSuccess(): void
    {
        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('exportRowsWithTruncation')->once()
            ->andReturn(['rows' => [$this->log(1, 'Hello'), $this->log(2, 'World')], 'truncated' => false]);

        $file     = tempnam(sys_get_temp_dir(), 'bitsmtp-cli-export');
        $reporter = new FakeCliReporter();

        try {
            (new ExportCommand($logService, new LogCsvExporter()))->run([], ['file' => $file], $reporter);

            $this->assertCount(1, $reporter->success);
            $this->assertStringContainsString($file, $reporter->success[0]);
            $this->assertSame([], $reporter->lines, 'a file export must not also dump to STDOUT');

            $written = (string) file_get_contents($file);
            $this->assertStringContainsString('Hello', $written);
            $this->assertStringContainsString('World', $written);
        } finally {
            @unlink($file);
        }
    }

    public function testPassesTheStatusFilterThroughToExport(): void
    {
        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('exportRowsWithTruncation')->once()->with(['status' => 'failed'])
            ->andReturn(['rows' => [$this->log(1, 'X')], 'truncated' => false]);

        $reporter = new FakeCliReporter();
        (new ExportCommand($logService, new LogCsvExporter()))->run([], ['status' => 'failed'], $reporter);

        $this->assertCount(1, $reporter->lines);
    }

    public function testFlagsTruncationAndClampsToTheCapWhenOverTheLimit(): void
    {
        // The service returns the already-clamped rows + truncated flag; reuse one Log so the fixture is cheap.
        $capped = array_fill(0, LogService::MAX_EXPORT_ROWS, $this->log(1, 'row'));

        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('exportRowsWithTruncation')->once()
            ->andReturn(['rows' => $capped, 'truncated' => true]);

        $reporter = new FakeCliReporter();
        (new ExportCommand($logService, new LogCsvExporter()))->run([], [], $reporter);

        $this->assertCount(1, $reporter->warning);
        $this->assertStringContainsString((string) LogService::MAX_EXPORT_ROWS, $reporter->warning[0]);

        // Header + exactly MAX_EXPORT_ROWS data rows.
        $lineCount = substr_count(rtrim($reporter->lines[0], "\r\n"), "\n") + 1;
        $this->assertSame(LogService::MAX_EXPORT_ROWS + 1, $lineCount);
    }

    public function testReportsAnErrorWhenTheFileCannotBeWritten(): void
    {
        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('exportRowsWithTruncation')->once()
            ->andReturn(['rows' => [$this->log(1, 'X')], 'truncated' => false]);

        $reporter = new FakeCliReporter();
        (new ExportCommand($logService, new LogCsvExporter()))->run([], ['file' => '/no/such/dir/out.csv'], $reporter);

        $this->assertCount(1, $reporter->error);
        $this->assertSame([], $reporter->success);
    }

    private function log(int $id, string $subject): Log
    {
        $log          = new Log();
        $log->id      = $id;
        $log->status  = 1;
        $log->subject = $subject;
        $log->to_addr = '["to@example.test"]';

        return $log;
    }
}
