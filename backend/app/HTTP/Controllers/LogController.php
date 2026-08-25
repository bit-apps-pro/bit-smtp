<?php

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Requests\DeleteLogRequest;
use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Plugin;

final class LogController
{
    /**
     * Filename the browser saves the exported CSV under.
     */
    private const EXPORT_FILENAME = 'bit-smtp-logs.csv';

    private $logger;

    /**
     * @var MailConfigService
     */
    private $mailConfig;

    public function __construct()
    {
        $this->logger     = Plugin::instance()->logger();
        $this->mailConfig = Plugin::instance()->mailConfigService();
    }

    public function all(Request $request)
    {
        $pageNo = \intval($request->pageNo) ?? 1;
        $limit  = \intval($request->limit)  ?? 14;

        $filters = $this->extractLogFilters($request);

        $result         = $this->logger->all((($pageNo - 1) * $limit), $limit, $filters);
        $result['logs'] = $this->enrichLogs($result['logs']);

        return Response::success($result);
    }

    /**
     * Export the current (filter-scoped) log view as an RFC-4180 CSV of safe metadata columns only —
     * never message body, credentials, or debug detail — for the browser to download. The `truncated`
     * flag lets the UI warn the user when the result was clamped to LogService::MAX_EXPORT_ROWS.
     */
    public function export(Request $request): Response
    {
        $filters = $this->extractLogFilters($request);
        // Fetch one past the cap so a result that is exactly at the cap isn't falsely flagged truncated.
        $rows      = $this->logger->exportRows($filters, LogService::MAX_EXPORT_ROWS + 1);
        $truncated = \count($rows) > LogService::MAX_EXPORT_ROWS;
        if ($truncated) {
            $rows = \array_slice($rows, 0, LogService::MAX_EXPORT_ROWS);
        }

        return Response::success([
            'csv'       => $this->toCsv($rows),
            'filename'  => self::EXPORT_FILENAME,
            'count'     => \count($rows),
            'truncated' => $truncated,
        ]);
    }

    public function details(Request $request)
    {
        $logId = \intval($request->id);
        $log   = $this->logger->get($logId);

        if (!$log instanceof Log) {
            return Response::success($log);
        }

        $data                      = $log->jsonSerialize();
        $data['delivery_verified'] = $this->isDeliveryVerified($log, $this->verifiedConnectionMap());
        $data['delivery_events']   = $this->logger->deliveryEvents($logId);
        // Resend history: the log this one was resent from (if any) and the resends launched from it.
        $data['resend_of'] = $log->resend_parent_id !== null ? (int) $log->resend_parent_id : null;
        $data['resends']   = $this->logger->resendChildren($logId);

        return Response::success($data);
    }

    public function delete(DeleteLogRequest $request)
    {
        $validatedIds = array_map(function ($id) {
            return \intval($id);
        }, $request->ids);
        $status = $this->logger->delete($validatedIds);
        if ($status) {
            return Response::success([])->message(__('Log deleted', 'bit-smtp'));
        }

        return Response::error([])->message(__('Failed to delete log', 'bit-smtp'));
    }

    public function updateRetention(Request $request)
    {
        $days   = \intval($request->period);
        $status = $this->logger->updateRetention($days);
        if ($status) {
            return Response::success([])->message(__('Log retention period updated successfully', 'bit-smtp'));
        }

        return Response::error([])->message(__('Failed to update log retention period', 'bit-smtp'));
    }

    public function isEnabled(Request $request)
    {
        $enabled = $this->logger->isEnabled();

        return Response::success(['enabled' => $enabled]);
    }

    public function toggle(Request $request)
    {
        $enabled = isset($request->enabled) ? (bool) $request->enabled : false;
        $status  = $this->logger->setEnabled($enabled);
        if ($status) {
            return Response::success(['enabled' => $enabled])->message(__('Logging updated', 'bit-smtp'));
        }

        return Response::error([])->message(__('Failed to update logging setting', 'bit-smtp'));
    }

    /**
     * Whitelists and sanitizes the `logs/all` filter keys the frontend may send; per-value validation
     * (delivery_status set membership, date format) is LogService's job, not the controller's.
     *
     * @return array<string,string>
     */
    private function extractLogFilters(Request $request): array
    {
        $filters = [];
        foreach (['to_addr', 'status', 'delivery_status', 'connection_id', 'source_plugin', 'date_from', 'date_to'] as $key) {
            if (isset($request->{$key}) && !empty($request->{$key})) {
                $filters[$key] = sanitize_text_field($request->{$key});
            }
        }

        return $filters;
    }

    /**
     * Render export rows as an RFC-4180 CSV string (header + data) via fputcsv for correct quoting,
     * with each data cell defused against spreadsheet formula injection. The escape argument is empty
     * so quoting follows pure RFC-4180 (double the inner quote) rather than backslash-escaping.
     *
     * @param array<int,Log> $rows
     */
    private function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+b');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, LogService::EXPORT_SAFE_COLUMNS, ',', '"', '');
        foreach ($rows as $row) {
            // Project through EXPORT_SAFE_COLUMNS (the header) so cell order can never drift from it.
            $cellsByColumn = $this->exportRowCells($row);
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
    private function exportRowCells(Log $row): array
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

    /**
     * Serialize each log to its array shape plus a derived `delivery_verified` flag, leaving every
     * existing field the frontend consumes intact.
     *
     * @param mixed $logs Log|array<int,Log>|false as returned by the query builder
     *
     * @return array<int,array<string,mixed>>
     */
    private function enrichLogs($logs): array
    {
        if ($logs instanceof Log) {
            $logs = [$logs];
        }

        if (!\is_array($logs)) {
            return [];
        }

        $verifiedMap = $this->verifiedConnectionMap();

        return array_map(function (Log $log) use ($verifiedMap) {
            $data                      = $log->jsonSerialize();
            $data['delivery_verified'] = $this->isDeliveryVerified($log, $verifiedMap);

            return $data;
        }, $logs);
    }

    /**
     * Build a [connectionId => webhookVerified] map once per request so a row's delivery status is
     * only ever surfaced for a connection whose webhook is proven live. Keyed on the stable connection
     * id — the same value a log row's `connection_id` column stores, not the mutable label.
     *
     * @return array<string,bool>
     */
    private function verifiedConnectionMap(): array
    {
        $map = [];
        foreach ($this->mailConfig->load()->getConnections() as $connection) {
            $map[$connection->getId()] = $connection->isWebhookVerified();
        }

        return $map;
    }

    /**
     * A log carries real delivery status only when its sending connection is webhook-verified AND it
     * holds a correlation key the receiver can match provider events against.
     *
     * @param array<string,bool> $verifiedMap
     */
    private function isDeliveryVerified(Log $log, array $verifiedMap): bool
    {
        // A status stamped at send time (a provider with no async delivery feed) is authoritative on
        // its own; otherwise a row's status is trustworthy only when its connection is webhook-verified
        // AND it carries a key the receiver can correlate provider events against.
        if ($log->delivery_status !== null && $log->delivery_status !== '') {
            return true;
        }

        $connectionVerified = $verifiedMap[(string) $log->connection_id] ?? false;

        return $connectionVerified && $this->hasCorrelationKey($log);
    }

    private function hasCorrelationKey(Log $log): bool
    {
        return ($log->message_id !== null && $log->message_id !== '')
            || ($log->tracking_id !== null && $log->tracking_id !== '');
    }
}
