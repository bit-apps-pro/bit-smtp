<?php

namespace BitApps\SMTP\Mail\Dispatch;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Model\Log;
use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;

/**
 * Records wp_mail outcomes. Buffers logs in a memory-aware pending queue and bulk-flushes them,
 * except on the retry path where the originating log row is updated in place.
 */
class MailEventLogger
{
    private const MEMORY_THRESHOLD = 104857600; // 100MB

    private LogService $logger;

    /**
     * @var array<int,array{status: int, data: array|WP_Error}>
     */
    private array $pendingLogs = [];

    public function __construct(LogService $logger)
    {
        $this->logger = $logger;
    }

    public function logMailSuccess(array $mailData, SendContext $context, ?string $connection = null, ?string $messageId = null, ?string $trackingId = null, ?string $connectionId = null, ?string $deliveryStatus = null): void
    {
        if ($context->isRetrying() && $context->getRetryLogId() > 0) {
            $this->logger->update($context->getRetryLogId(), Log::SUCCESS, $mailData, null, $connection, $messageId, $trackingId, $connectionId, $deliveryStatus);
        } else {
            $this->queue(Log::SUCCESS, $mailData, $context, $connection, $messageId, $trackingId, $connectionId, $deliveryStatus);
        }

        $context->setFailed(false);
        $context->setRetrying(false);
    }

    public function logMailFailed(WP_Error $error, SendContext $context, ?string $connection = null, ?string $messageId = null, ?string $trackingId = null, ?string $connectionId = null): void
    {
        if ($context->isDebug() && $this->isSmtpConnectionFailure($error)) {
            $message = __('SMTP configuration is not correct. PHPMailer could not connect to the SMTP server', 'bit-smtp');
            $error->add('wp_mail_failed', $message, $error->get_error_data());
            $context->appendDebug($message . "\n");
        }

        if ($context->isRetrying() && $context->getRetryLogId() > 0) {
            $this->logger->update($context->getRetryLogId(), Log::ERROR, $error->get_error_data(), $error->get_error_messages(), $connection, $messageId, $trackingId, $connectionId);
        } else {
            $this->queue(Log::ERROR, $error, $context, $connection, $messageId, $trackingId, $connectionId);
        }

        $context->setFailed(true);
        $context->setRetrying(false);
    }

    /**
     * Flush any logs still buffered (e.g. from the owning object's destruct).
     */
    public function flushPendingLogs(): void
    {
        if (empty($this->pendingLogs)) {
            return;
        }

        $this->logger->bulkInsert($this->pendingLogs);
        $this->pendingLogs = [];
    }

    /**
     * True only for a PHPMailer connection-level failure. API providers short-circuit pre_wp_mail
     * before WordPress lazy-loads PHPMailer, so the class guard keeps the constant access from
     * fataling on their failure path (their code carries an HTTP status, never STOP_CRITICAL).
     */
    private function isSmtpConnectionFailure(WP_Error $error): bool
    {
        $data = $error->get_error_data();
        if (!\is_array($data) || !isset($data['phpmailer_exception_code'])) {
            return false;
        }

        return class_exists(PHPMailer::class, false)
            && $data['phpmailer_exception_code'] == PHPMailer::STOP_CRITICAL;
    }

    /**
     * @param array|WP_Error $data
     */
    private function queue(int $status, $data, SendContext $context, ?string $connection, ?string $messageId = null, ?string $trackingId = null, ?string $connectionId = null, ?string $deliveryStatus = null): void
    {
        $this->pendingLogs[] = [
            'status'              => $status,
            'data'                => $data,
            'connection'          => $connection,
            'connection_id'       => $connectionId,
            'message_id'          => $messageId,
            'tracking_id'         => $trackingId,
            'delivery_status'     => $deliveryStatus,
            'delivery_updated_at' => $deliveryStatus !== null ? gmdate('Y-m-d H:i:s') : null,
        ];

        if ($this->shouldFlushLogs($context)) {
            $this->flushPendingLogs();
        }
    }

    /**
     * Flush immediately unless batching, in which case defer until free memory dips below threshold.
     */
    private function shouldFlushLogs(SendContext $context): bool
    {
        if ($context->isBatch() === false) {
            return true;
        }

        $limit = \ini_get('memory_limit');
        if ($limit === '-1') {
            return false;
        }

        $limit = $this->getBytes($limit);
        $used  = memory_get_usage(true);

        return ($limit - $used) < self::MEMORY_THRESHOLD || self::MEMORY_THRESHOLD > $limit;
    }

    /**
     * Convert a PHP memory-limit string (e.g. "128M") to bytes.
     */
    private function getBytes(string $val): int
    {
        $val  = trim($val);
        $last = strtolower($val[\strlen($val) - 1]);
        $val  = (int) $val;

        switch ($last) {
            case 'g':
                $val *= 1024;
                // no break
            case 'm':
                $val *= 1024;
                // no break
            case 'k':
                $val *= 1024;
        }

        return $val;
    }
}
