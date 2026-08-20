<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Webhook;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Mail\Connections\Connection;

/**
 * Correlates an inbound provider delivery event to its log, records it idempotently, and folds the
 * log's events into a fresh parent rollup.
 */
final class DeliveryEventRecorder
{
    private LogService $logService;

    public function __construct(LogService $logService)
    {
        $this->logService = $logService;
    }

    /**
     * @return bool true when the event was correlated and recorded, false when it matched no log
     */
    public function record(DeliveryEvent $event, Connection $connection): bool
    {
        $keys = $event->correlationKeys();
        // Scope on the connection's stable id — the mail-log `connection_id` column — so a renamed or
        // unnamed same-provider connection can't break or mis-attribute correlation.
        $log = $this->logService->findForCorrelation(
            $connection->getId(),
            $keys['message_id'],
            $keys['tracking_id']
        );

        if ($log === null) {
            return false;
        }

        $logId = (int) $log->id;
        $this->logService->recordDeliveryEvent($logId, $event, $event->hash($logId));

        $rollup = DeliveryRollup::compute($this->logService->deliveryEvents($logId));
        $this->logService->updateDeliveryRollup($log, $rollup['status'], $rollup['updated_at']);

        return true;
    }
}
