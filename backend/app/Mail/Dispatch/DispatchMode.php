<?php

namespace BitApps\SMTP\Mail\Dispatch;

/**
 * Why a dispatch runs, deciding its failure side effects; health is recorded in every mode as real signal.
 */
final class DispatchMode
{
    private bool $enqueuesRetry;

    private bool $notifiesOutcome;

    private function __construct(bool $enqueuesRetry, bool $notifiesOutcome)
    {
        $this->enqueuesRetry   = $enqueuesRetry;
        $this->notifiesOutcome = $notifiesOutcome;
    }

    /**
     * Real mail (routed wp_mail or manual resend): queue retries and alert on failure.
     */
    public static function live(): self
    {
        return new self(true, true);
    }

    /**
     * Retry-worker re-dispatch: the worker owns rescheduling, but outcomes still alert.
     */
    public static function workerRetry(): self
    {
        return new self(false, true);
    }

    /**
     * Admin test send: the result is shown to the admin, so no retry and no failure incident.
     */
    public static function adminTest(): self
    {
        return new self(false, false);
    }

    /**
     * Whether a retryable failure may be queued for automatic retry.
     */
    public function enqueuesRetry(): bool
    {
        return $this->enqueuesRetry;
    }

    /**
     * Whether the outcome feeds the failure notifier (incidents and alerts).
     */
    public function notifiesOutcome(): bool
    {
        return $this->notifiesOutcome;
    }
}
