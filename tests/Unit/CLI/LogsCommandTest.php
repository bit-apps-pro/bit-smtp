<?php

namespace BitApps\SMTP\Tests\Unit\CLI;

use BitApps\SMTP\CLI\LogsCommand;
use BitApps\SMTP\HTTP\Services\LogCsvExporter;
use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use BitApps\SMTP\Tests\Fake\FakeCliReporter;
use Mockery;

/**
 * Covers the logs command logic: it reuses LogService::exportRows with the status filter and requested
 * limit, projects rows through the shared LogCsvExporter, and renders them in the requested format.
 *
 * @internal
 *
 * @coversNothing
 */
final class LogsCommandTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Log's magic accessors reach the query builder, which reads the wpdb prefix.
        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];
    }

    public function testRendersExportedRowsAsATableByDefaultWithTheSafeColumns(): void
    {
        $rows       = [$this->log(['id' => 1, 'status' => 1, 'subject' => 'Hello']), $this->log(['id' => 2, 'status' => 0])];
        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('exportRows')->once()->with([], 20)->andReturn($rows);

        $reporter = new FakeCliReporter();
        (new LogsCommand($logService, new LogCsvExporter()))->run([], [], $reporter);

        $this->assertCount(1, $reporter->rendered);
        $this->assertSame('table', $reporter->rendered[0]['format']);
        $this->assertSame(LogService::EXPORT_SAFE_COLUMNS, $reporter->rendered[0]['fields']);
        $this->assertCount(2, $reporter->rendered[0]['items']);
        $this->assertSame('sent', $reporter->rendered[0]['items'][0]['status']);
        $this->assertSame('failed', $reporter->rendered[0]['items'][1]['status']);
    }

    public function testPassesTheStatusFilterAndLimitThroughToExportRows(): void
    {
        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('exportRows')->once()->with(['status' => 'failed'], 5)->andReturn([$this->log(['id' => 9])]);

        $reporter = new FakeCliReporter();
        (new LogsCommand($logService, new LogCsvExporter()))->run([], ['status' => 'failed', 'limit' => '5'], $reporter);

        $this->assertCount(1, $reporter->rendered);
    }

    public function testHonorsTheJsonFormatFlag(): void
    {
        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('exportRows')->once()->andReturn([$this->log(['id' => 1])]);

        $reporter = new FakeCliReporter();
        (new LogsCommand($logService, new LogCsvExporter()))->run([], ['format' => 'json'], $reporter);

        $this->assertSame('json', $reporter->rendered[0]['format']);
    }

    public function testFallsBackToTableForAnUnsupportedFormat(): void
    {
        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('exportRows')->once()->andReturn([$this->log(['id' => 1])]);

        $reporter = new FakeCliReporter();
        (new LogsCommand($logService, new LogCsvExporter()))->run([], ['format' => 'bogus'], $reporter);

        $this->assertSame('table', $reporter->rendered[0]['format']);
    }

    public function testDefaultsANonPositiveLimitToTheDefault(): void
    {
        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('exportRows')->once()->with([], 20)->andReturn([$this->log(['id' => 1])]);

        $reporter = new FakeCliReporter();
        (new LogsCommand($logService, new LogCsvExporter()))->run([], ['limit' => '0'], $reporter);

        $this->assertCount(1, $reporter->rendered);
    }

    public function testReportsNoLogsWhenTheResultIsEmpty(): void
    {
        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('exportRows')->once()->andReturn([]);

        $reporter = new FakeCliReporter();
        (new LogsCommand($logService, new LogCsvExporter()))->run([], [], $reporter);

        $this->assertSame(['No logs found.'], $reporter->lines);
        $this->assertSame([], $reporter->rendered);
    }

    /**
     * Build a minimal Log with the given attributes for the shared exporter to project.
     *
     * @param array<string,mixed> $attributes
     */
    private function log(array $attributes): Log
    {
        $log          = new Log();
        $log->to_addr = '["to@example.test"]';
        foreach ($attributes as $name => $value) {
            $log->{$name} = $value;
        }

        return $log;
    }
}
