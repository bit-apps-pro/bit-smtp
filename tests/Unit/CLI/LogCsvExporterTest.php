<?php

namespace BitApps\SMTP\Tests\Unit\CLI;

use BitApps\SMTP\HTTP\Services\LogCsvExporter;
use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * Covers the shared LogCsvExporter extracted from LogController: the safe-column header/projection,
 * status humanization, recipient flattening, and CSV formula-injection defusal -- the exact behavior
 * both the REST export and the WP-CLI export/logs commands now depend on.
 *
 * @internal
 *
 * @coversNothing
 */
final class LogCsvExporterTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];
    }

    public function testCsvHeaderIsExactlyTheSafeColumns(): void
    {
        $csv    = (new LogCsvExporter())->toCsv([$this->log(['id' => 1])]);
        $header = str_getcsv(strtok($csv, "\n"), ',', '"', '');

        $this->assertSame(LogService::EXPORT_SAFE_COLUMNS, $header);
        foreach (['details', 'debug_info', 'body', 'message', 'tracking_id'] as $forbidden) {
            $this->assertNotContains($forbidden, $header);
        }
    }

    public function testRowCellsHumanizesStatusAndFlattensRecipients(): void
    {
        $log          = new Log();
        $log->to_addr = ['a@example.test', 'b@example.test'];

        $sent   = (new LogCsvExporter())->rowCells($this->log(['id' => 1, 'status' => 1]));
        $failed = (new LogCsvExporter())->rowCells($this->log(['id' => 2, 'status' => 0]));

        $this->assertSame('sent', $sent['status']);
        $this->assertSame('failed', $failed['status']);
        $this->assertSame('a@example.test; b@example.test', (new LogCsvExporter())->rowCells($log)['to_addr']);
    }

    public function testFormulaTriggeringCellIsDefused(): void
    {
        $csv  = (new LogCsvExporter())->toCsv([$this->log(['id' => 1, 'subject' => '=SUM(A1:A2)'])]);
        $rows = array_map(
            static fn (string $line): array => str_getcsv($line, ',', '"', ''),
            array_filter(explode("\n", trim($csv)))
        );
        $subjectIndex = array_search('subject', LogService::EXPORT_SAFE_COLUMNS, true);

        $this->assertSame("'=SUM(A1:A2)", $rows[1][$subjectIndex]);
    }

    /**
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
