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

    public function logMailSuccess(array $mailData, SendContext $context): void
    {
        if ($context->isRetrying() && $context->getRetryLogId() > 0) {
            $this->logger->update($context->getRetryLogId(), Log::SUCCESS, $mailData);
        } else {
            $this->queue(Log::SUCCESS, $mailData, $context);
        }

        $context->setFailed(false);
        $context->setRetrying(false);
    }

    public function logMailFailed(WP_Error $error, SendContext $context): void
    {
        if ($context->isDebug() && $error->get_error_data()['phpmailer_exception_code'] == PHPMailer::STOP_CRITICAL) {
            $message = __('SMTP configuration is not correct. PHPMailer could not connect to the SMTP server', 'bit-smtp');
            $error->add('wp_mail_failed', $message, $error->get_error_data());
            $context->appendDebug($message . "\n");
        }

        if ($context->isRetrying() && $context->getRetryLogId() > 0) {
            $this->logger->update($context->getRetryLogId(), Log::ERROR, $error->get_error_data(), $error->get_error_messages());
        } else {
            $this->queue(Log::ERROR, $error, $context);
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
     * @param array|WP_Error $data
     */
    private function queue(int $status, $data, SendContext $context): void
    {
        $this->pendingLogs[] = [
            'status' => $status,
            'data'   => $data,
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
