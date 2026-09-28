<?php

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Requests\DeleteLogRequest;
use BitApps\SMTP\HTTP\Services\LogCsvExporter;
use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Plugin;
use BitApps\SMTP\Settings\PluginSettings;

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

    private LogCsvExporter $csvExporter;

    public function __construct()
    {
        $this->logger      = Plugin::instance()->logger();
        $this->mailConfig  = Plugin::instance()->mailConfigService();
        $this->csvExporter = new LogCsvExporter();
    }

    public function all(Request $request)
    {
        $pageNo = \intval($request->pageNo) ?? 1;
        $limit  = \intval($request->limit)  ?? 14;

        $filters = $this->extractLogFilters($request);

        $result                     = $this->logger->all((($pageNo - 1) * $limit), $limit, $filters);
        $trackingEnabled            = PluginSettings::isTrackingEnabled();
        $result['tracking_enabled'] = $trackingEnabled;
        $result['logs']             = $this->enrichLogs($result['logs'], $trackingEnabled);

        return Response::success($result);
    }

    /**
     * Export filtered logs as RFC-4180 CSV with safe metadata only; truncated flag warns on clamp.
     */
    public function export(Request $request): Response
    {
        $export = $this->logger->exportRowsWithTruncation($this->extractLogFilters($request));

        return Response::success([
            'csv'       => $this->csvExporter->toCsv($export['rows']),
            'filename'  => self::EXPORT_FILENAME,
            'count'     => \count($export['rows']),
            'truncated' => $export['truncated'],
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
        $data['engagement']        = $this->logger->engagementFor($logId);
        $data['resend_of']         = $log->resend_parent_id !== null ? (int) $log->resend_parent_id : null;
        $data['resends']           = $this->logger->resendChildren($logId);

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
     * Whitelist and sanitize frontend filter keys; per-value validation is LogService's job.
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
     * Serialize logs with derived delivery_verified flag, leaving frontend-consumed fields intact.
     *
     * @param mixed $logs Log|array<int,Log>|false as returned by the query builder
     *
     * @return array<int,array<string,mixed>>
     */
    private function enrichLogs($logs, bool $trackingEnabled): array
    {
        if ($logs instanceof Log) {
            $logs = [$logs];
        }

        if (!\is_array($logs)) {
            return [];
        }

        $verifiedMap   = $this->verifiedConnectionMap();
        $trackingFlags = $trackingEnabled
            ? $this->logger->engagementFlagsFor(array_map(static fn (Log $log): int => (int) $log->id, $logs))
            : [];

        return array_map(function (Log $log) use ($verifiedMap, $trackingFlags) {
            $data                      = $log->jsonSerialize();
            $data['delivery_verified'] = $this->isDeliveryVerified($log, $verifiedMap);

            return $data + ($trackingFlags[(int) $log->id] ?? []);
        }, $logs);
    }

    /**
     * Build connectionId => webhookVerified map; delivery status shown only for verified webhooks.
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
     * Send-time status is authoritative; otherwise real delivery status requires webhook-verified connection and correlation key.
     *
     * @param array<string,bool> $verifiedMap
     */
    private function isDeliveryVerified(Log $log, array $verifiedMap): bool
    {
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
