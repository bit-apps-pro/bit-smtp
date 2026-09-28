<?php

namespace BitApps\SMTP\HTTP\Services;

use BitApps\SMTP\Model\Log;

\defined('ABSPATH') || exit();

/**
 * Render export-safe log rows as RFC-4180 CSV with formula-injection defusal.
 */
final class LogCsvExporter
{
    /**
     * Render rows as RFC-4180 CSV with formula-injection defusal; fputcsv handles quoting.
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
     * Project log to export-safe cells (humanize status, flatten recipients).
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
            'cc'              => $this->flattenRecipients($row->cc),
            'bcc'             => $this->flattenRecipients($row->bcc),
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
     * Defuse formula injection by prefixing formula triggers (=, +, -, @, tab, CR) with quote.
     */
    private function defuseCsvValue(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        return \in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }
}
