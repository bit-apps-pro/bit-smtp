<?php

namespace BitApps\SMTP\Mail\Dispatch;

use BitApps\SMTP\Settings\PluginSettings;

/**
 * Cron-driven retry queue consumer: claims due rows, dispatches, deletes or reschedules.
 */
class RetryWorker
{
    private const BASE_SECONDS = 300;

    private const CAP_SECONDS = 21600;

    private const JITTER_RATIO = 0.15;

    private const DEFAULT_BATCH = 20;

    private RetryQueue $queue;

    private WpMailBridge $bridge;

    /**
     * Read once at construction; enables cheap backoffDelay() calculation without re-reading options.
     */
    private string $backoffMode;

    /**
     * Admin's retry-class filter (empty = all retryable); dropped classes stop rescheduling on next claim.
     *
     * @var string[]
     */
    private array $retryOnClasses;

    public function __construct(RetryQueue $queue, WpMailBridge $bridge)
    {
        $this->queue          = $queue;
        $this->bridge         = $bridge;
        $settings             = PluginSettings::make();
        $this->backoffMode    = (string) $settings->get('retry_backoff', 'exponential');
        $this->retryOnClasses = (array) $settings->get('retry_on_classes', []);
    }

    /**
     * Claim due rows and resolve: remove delivered, reschedule retryable, drop terminal failures.
     */
    public function process(int $batch = self::DEFAULT_BATCH): void
    {
        foreach ($this->queue->claimDue($batch) as $row) {
            if (!$this->queue->renewClaim($row['id'], $row['claim_token'])) {
                continue;
            }

            $outcome = $this->bridge->dispatchRetry($row['message'], $row['mail_data'], $row['connection_ids']);

            if ($outcome['succeeded']) {
                $this->queue->delete($row['id']);

                continue;
            }

            $attempts     = $row['attempts'] + 1;
            $failureClass = $outcome['failure_class'];

            if ($failureClass !== null && $attempts < $row['max_attempts'] && FailureCategory::isRetryableWithin($failureClass, $this->retryOnClasses)) {
                $this->queue->reschedule($row['id'], $attempts, $failureClass, $this->backoffDelay($attempts));

                continue;
            }

            $this->queue->delete($row['id']);
        }
    }

    /**
     * This worker's configured backoff delay (seconds) for the given attempt number.
     */
    public function backoffDelay(int $attempt): int
    {
        return self::computeDelay($attempt, $this->backoffMode);
    }

    /**
     * Pure base/cap/jitter backoff formula; callable without instance, already jittered (no double-jitter).
     */
    public static function computeDelay(int $attempt, string $mode): int
    {
        $exponent = max(0, $attempt - 1);
        $seconds  = $mode === 'fixed' ? self::BASE_SECONDS : self::BASE_SECONDS * (2 ** $exponent);
        $capped   = min(self::CAP_SECONDS, $seconds);

        return self::applyJitter($capped);
    }

    /**
     * Apply +/- JITTER_RATIO spread to prevent synchronized wakeups hammering the provider.
     */
    private static function applyJitter(int $seconds): int
    {
        $spread   = (int) round($seconds * self::JITTER_RATIO);
        $jittered = $spread > 0 ? $seconds + random_int(-$spread, $spread) : $seconds;

        return max(1, min(self::CAP_SECONDS, $jittered));
    }
}
