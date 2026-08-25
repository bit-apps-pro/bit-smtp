<?php

namespace BitApps\SMTP\CLI;

use BitApps\SMTP\HTTP\Services\LogCsvExporter;
use BitApps\SMTP\HTTP\Services\LogService;

\defined('ABSPATH') || exit();

/**
 * `wp bit-smtp export`: write the filtered log view as an RFC-4180 CSV (safe metadata columns only,
 * formula-injection defused) to a file or STDOUT. Reuses LogService::exportRows + LogCsvExporter, the
 * same bounded, non-silently-truncated path the REST export uses.
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
     * Build the CSV for the optional status filter (bounded and truncation-flagged), then write it to
     * `--file` or dump it to STDOUT. On STDOUT the only chatter is the STDERR truncation warning, so
     * `wp bit-smtp export > out.csv` stays a clean CSV.
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
            // Handle the failure ourselves with a clean CLI error; the raw PHP write warning would
            // just be redundant noise on top of it.
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
