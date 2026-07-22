<?php

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Requests\DeleteLogRequest;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Plugin;

final class LogController
{
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

        $filters = [];
        if (isset($request->to_addr) && !empty($request->to_addr)) {
            $filters['to_addr'] = sanitize_text_field($request->to_addr);
        }

        $result         = $this->logger->all((($pageNo - 1) * $limit), $limit, $filters);
        $result['logs'] = $this->enrichLogs($result['logs']);

        return Response::success($result);
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
        $connectionVerified = $verifiedMap[(string) $log->connection_id] ?? false;

        return $connectionVerified && $this->hasCorrelationKey($log);
    }

    private function hasCorrelationKey(Log $log): bool
    {
        return ($log->message_id !== null && $log->message_id !== '')
            || ($log->tracking_id !== null && $log->tracking_id !== '');
    }
}
