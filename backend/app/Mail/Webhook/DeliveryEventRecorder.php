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
        // Scope on the connection's stored label (name, or provider when unnamed) — the mail-log
        // `connection` column holds that label, not the connection id.
        $log = $this->logService->findForCorrelation(
            $connection->label(),
            $keys['message_id'],
            $keys['tracking_id']
        );

        if ($log === null) {
            return false;
        }

        $logId = (int) $log->id;
        $this->logService->recordDeliveryEvent($logId, $event, $event->hash($logId));

        $rollup = DeliveryRollup::compute($this->logService->deliveryRows($logId));
        $this->logService->updateDeliveryRollup($logId, $rollup['status'], $rollup['updated_at']);

        return true;
    }
}
