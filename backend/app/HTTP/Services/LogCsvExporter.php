<?php

namespace BitApps\SMTP\HTTP\Services;

use BitApps\SMTP\Model\Log;

\defined('ABSPATH') || exit();

/**
 * Renders export-safe log rows as an RFC-4180 CSV. Shared by the REST export controller and the
 * WP-CLI export/logs commands so both use one safe-column projection and one formula-injection defusal.
 */
final class LogCsvExporter
{
    /**
     * Render export rows as an RFC-4180 CSV string (header + data) via fputcsv for correct quoting,
     * with each data cell defused against spreadsheet formula injection. The escape argument is empty
     * so quoting follows pure RFC-4180 (double the inner quote) rather than backslash-escaping.
     *
     * @param array<int,Log> $rows
     */
    public function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+b');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, LogService::EXPORT_SAFE_COLUMNS, ',', '"', '');
        foreach ($rows as $row) {
            // Project through EXPORT_SAFE_COLUMNS (the header) so cell order can never drift from it.
            $cellsByColumn = $this->rowCells($row);
            $cells         = array_map(
                fn (string $column): string => $this->defuseCsvValue($cellsByColumn[$column] ?? ''),
                LogService::EXPORT_SAFE_COLUMNS
            );
            fputcsv($handle, $cells, ',', '"', '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    /**
     * Project one log to its ordered, export-safe cell values (keys mirror EXPORT_SAFE_COLUMNS); the
     * send status is humanized and recipients flattened, everything else stringified as-is.
     *
     * @return array<string,string>
     */
    public function rowCells(Log $row): array
    {
        return [
            'id'              => (string) $row->id,
            'created_at'      => (string) $row->created_at,
            'status'          => $row->status ? 'sent' : 'failed',
            'to_addr'         => $this->flattenRecipients($row->to_addr),
            'subject'         => (string) $row->subject,
            'connection'      => (string) $row->connection,
            'sender'          => (string) $row->sender,
            'failure_class'   => (string) $row->failure_class,
            'delivery_status' => (string) $row->delivery_status,
            'message_id'      => (string) $row->message_id,
            'retry_count'     => (string) $row->retry_count,
        ];
    }

    /**
     * Flatten a log's recipient list into a single, human-readable CSV cell.
     *
     * @param mixed $recipients array<int,string>|string as the Log model casts it
     */
    private function flattenRecipients($recipients): string
    {
        if (\is_array($recipients)) {
            return implode('; ', array_map('strval', $recipients));
        }

        return \is_scalar($recipients) ? (string) $recipients : '';
    }

    /**
     * Neutralize spreadsheet formula injection: a cell that opens with a formula trigger (=, +, -, @,
     * tab, CR) is prefixed with a single quote so Excel/Sheets treat it as literal text, not a formula.
     */
    private function defuseCsvValue(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        return \in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }
}
