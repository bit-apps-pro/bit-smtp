<?php

namespace BitApps\SMTP\CLI;

use BitApps\SMTP\HTTP\Services\LogCsvExporter;
use BitApps\SMTP\HTTP\Services\LogService;

\defined('ABSPATH') || exit();

/**
 * Export filtered logs to RFC-4180 CSV (safe columns, formula-injection protected) via LogCsvExporter.
 */
final class ExportCommand
{
    private LogService $logService;

    private LogCsvExporter $exporter;

    public function __construct(LogService $logService, LogCsvExporter $exporter)
    {
        $this->logService = $logService;
        $this->exporter   = $exporter;
    }

    /**
     * Export filtered logs to CSV (truncation-flagged) and write to file or STDOUT.
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assocArgs
     */
    public function run(array $args, array $assocArgs, CliReporter $reporter): void
    {
        $filters = [];
        $status  = isset($assocArgs['status']) ? trim((string) $assocArgs['status']) : '';
        if ($status !== '') {
            $filters['status'] = $status;
        }

        $export    = $this->logService->exportRowsWithTruncation($filters);
        $rows      = $export['rows'];
        $truncated = $export['truncated'];

        $csv  = $this->exporter->toCsv($rows);
        $file = isset($assocArgs['file']) ? trim((string) $assocArgs['file']) : '';

        if ($file !== '') {
            if (@file_put_contents($file, $csv) === false) {
                $reporter->error(\sprintf('Could not write the export to %s', $file));

                return;
            }

            if ($truncated) {
                $reporter->warning(\sprintf('Export truncated to the first %d rows.', LogService::MAX_EXPORT_ROWS));
            }
            $reporter->success(\sprintf('Exported %d log(s) to %s', \count($rows), $file));

            return;
        }

        if ($truncated) {
            $reporter->warning(\sprintf('Export truncated to the first %d rows.', LogService::MAX_EXPORT_ROWS));
        }
        $reporter->line($csv);
    }
}
