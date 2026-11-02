<?php

namespace BitApps\SMTP\Mail\Dispatch;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Model\Log;
use Closure;
use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;

/**
 * Record wp_mail outcomes with buffering and bulk-flush, or in-place update on retry.
 */
class MailEventLogger
{
    private const MEMORY_THRESHOLD = 104857600;

    private LogService $logger;

    /**
     * @var Closure(): ?int
     */
    private Closure $memoryHeadroom;

    /**
     * @var array<int,array{status: int, data: array|WP_Error}>
     */
    private array $pendingLogs = [];

    /**
     * Wire the log store and a reader of bytes left before the memory limit (null when unlimited).
     *
     * @param null|Closure(): ?int $memoryHeadroom
     */
    public function __construct(LogService $logger, ?Closure $memoryHeadroom = null)
    {
        $this->logger         = $logger;
        $this->memoryHeadroom = $memoryHeadroom ?? static fn (): ?int => self::phpMemoryHeadroom();
    }

    /**
     * Log a successful mail send; clears the one-shot resend parent so later ordinary sends are not mislabeled as resend children.
     */
    public function logMailSuccess(array $mailData, SendContext $context, ?string $connection = null, ?string $messageId = null, ?string $trackingId = null, ?string $connectionId = null, ?string $deliveryStatus = null): void
    {
        $decision = $context->getRoutingDecision();
        if ($context->isRetrying() && $context->getRetryLogId() > 0 && $decision !== null) {
            $this->logger->update($context->getRetryLogId(), Log::SUCCESS, $mailData, null, $connection, $messageId, $trackingId, $connectionId, $deliveryStatus, $decision->sourcePlugin(), $decision->type(), $decision->ruleIndex());
        } elseif ($context->isRetrying() && $context->getRetryLogId() > 0) {
            $this->logger->update($context->getRetryLogId(), Log::SUCCESS, $mailData, null, $connection, $messageId, $trackingId, $connectionId, $deliveryStatus);
        } else {
            $this->queue(Log::SUCCESS, $mailData, $context, $connection, $messageId, $trackingId, $connectionId, $deliveryStatus);
        }

        $context->setFailed(false);
        $context->setRetrying(false);
        $context->setResendParentId(null);
    }

    public function logMailFailed(WP_Error $error, SendContext $context, ?string $connection = null, ?string $messageId = null, ?string $trackingId = null, ?string $connectionId = null, ?string $failureClass = null): void
    {
        if ($context->isDebug() && $this->isSmtpConnectionFailure($error)) {
            $message = __('SMTP configuration is not correct. PHPMailer could not connect to the SMTP server', 'bit-smtp');
            $error->add('wp_mail_failed', $message, $error->get_error_data());
            $context->appendDebug($message . "\n");
        }

        $decision = $context->getRoutingDecision();
        if ($context->isRetrying() && $context->getRetryLogId() > 0 && $decision !== null) {
            $this->logger->update($context->getRetryLogId(), Log::ERROR, $error->get_error_data(), $error->get_error_messages(), $connection, $messageId, $trackingId, $connectionId, null, $decision->sourcePlugin(), $decision->type(), $decision->ruleIndex(), $failureClass);
        } elseif ($context->isRetrying() && $context->getRetryLogId() > 0) {
            $this->logger->update($context->getRetryLogId(), Log::ERROR, $error->get_error_data(), $error->get_error_messages(), $connection, $messageId, $trackingId, $connectionId, null, null, null, null, $failureClass);
        } else {
            $this->queue(Log::ERROR, $error, $context, $connection, $messageId, $trackingId, $connectionId, null, $failureClass);
        }

        $context->setFailed(true);
        $context->setRetrying(false);
        $context->setResendParentId(null);
    }

    /**
     * Flush any buffered logs.
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
     * True only for a PHPMailer connection failure (class guard prevents fatalizing on API provider paths).
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
     * Queues a log entry; only manual resend carries a parent id to avoid mislabeling ordinary and retry-worker sends.
     *
     * @param array|WP_Error $data
     */
    private function queue(int $status, $data, SendContext $context, ?string $connection, ?string $messageId = null, ?string $trackingId = null, ?string $connectionId = null, ?string $deliveryStatus = null, ?string $failureClass = null): void
    {
        $log = [
            'status'              => $status,
            'data'                => $data,
            'connection'          => $connection,
            'connection_id'       => $connectionId,
            'message_id'          => $messageId,
            'tracking_id'         => $trackingId,
            'delivery_status'     => $deliveryStatus,
            'delivery_updated_at' => $deliveryStatus !== null ? gmdate('Y-m-d H:i:s') : null,
            'failure_class'       => $failureClass,
        ];

        $decision = $context->getRoutingDecision();
        if ($decision !== null) {
            $log['source_plugin']      = $decision->sourcePlugin();
            $log['routing_type']       = $decision->type();
            $log['routing_rule_index'] = $decision->ruleIndex();
        }

        $resendParentId = $context->getResendParentId();
        if ($resendParentId !== null) {
            $log['resend_parent_id'] = $resendParentId;
        }

        $this->pendingLogs[] = $log;

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

        $headroom = ($this->memoryHeadroom)();

        return $headroom !== null && $headroom < self::MEMORY_THRESHOLD;
    }

    /**
     * Bytes left before PHP's memory_limit, or null when the limit is unlimited (-1).
     */
    private static function phpMemoryHeadroom(): ?int
    {
        $limit = \ini_get('memory_limit');
        if ($limit === false || $limit === '-1') {
            return null;
        }

        return self::getBytes($limit) - memory_get_usage(true);
    }

    /**
     * Convert a PHP memory-limit string (e.g. "128M") to bytes.
     */
    private static function getBytes(string $val): int
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
