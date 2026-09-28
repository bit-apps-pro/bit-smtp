<?php

namespace BitApps\SMTP\CLI;

use BitApps\SMTP\HTTP\Services\LogCsvExporter;
use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Settings\PluginSettings;

\defined('ABSPATH') || exit();

/**
 * List recent mail logs (safe columns only) filtered by status in requested format.
 */
final class LogsCommand
{
    /**
     * Default row count when `--limit` is omitted.
     */
    private const DEFAULT_LIMIT = 20;

    private LogService $logService;

    private LogCsvExporter $exporter;

    public function __construct(LogService $logService, LogCsvExporter $exporter)
    {
        $this->logService = $logService;
        $this->exporter   = $exporter;
    }

    /**
     * Fetch and render export-safe rows for optional status filter (notice on empty result).
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

        $limit  = $this->limit($assocArgs);
        $format = $this->format($assocArgs);

        $rows = $this->logService->exportRows($filters, $limit);
        if ($rows === []) {
            $reporter->line('No logs found.');

            return;
        }

        $items  = array_map(fn (Log $row): array => $this->exporter->rowCells($row), $rows);
        $fields = LogService::EXPORT_SAFE_COLUMNS;

        if (PluginSettings::isTrackingEnabled()) {
            $flags = $this->logService->engagementFlagsFor(array_map(static fn (Log $row): int => (int) $row->id, $rows));
            foreach ($rows as $index => $row) {
                $items[$index]['opened']  = $flags[(int) $row->id]['opened'] ? 'yes' : 'no';
                $items[$index]['clicked'] = $flags[(int) $row->id]['clicked'] ? 'yes' : 'no';
            }
            $fields = array_merge($fields, ['opened', 'clicked']);
        }

        $reporter->renderItems($format, $items, $fields);
    }

    /**
     * Resolve the `--limit` flag to a positive row count, defaulting when absent or non-positive.
     *
     * @param array<string,string> $assocArgs
     */
    private function limit(array $assocArgs): int
    {
        $limit = isset($assocArgs['limit']) ? (int) $assocArgs['limit'] : self::DEFAULT_LIMIT;

        return $limit > 0 ? $limit : self::DEFAULT_LIMIT;
    }

    /**
     * Resolve the `--format` flag against the supported set, defaulting to `table` for anything else.
     *
     * @param array<string,string> $assocArgs
     */
    private function format(array $assocArgs): string
    {
        $format = isset($assocArgs['format']) ? (string) $assocArgs['format'] : 'table';

        return \in_array($format, ['table', 'csv', 'json'], true) ? $format : 'table';
    }
}
