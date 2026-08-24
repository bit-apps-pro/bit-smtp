<?php

namespace BitApps\SMTP\Mail\Dispatch;

use BitApps\SMTP\Settings\PluginSettings;

/**
 * Cron-driven consumer of the retry queue: claims due rows, re-dispatches each through
 * WpMailBridge, and either deletes (delivered / terminal) or reschedules (retryable) the row.
 */
class RetryWorker
{
    private const BASE_SECONDS = 300; // 5 minutes

    private const CAP_SECONDS = 21600; // 6 hours

    private const JITTER_RATIO = 0.15;

    private const DEFAULT_BATCH = 20;

    private RetryQueue $queue;

    private WpMailBridge $bridge;

    /**
     * Read once at construction (this class is built fresh per cron tick), so backoffDelay() stays
     * a cheap, single-purpose calculation rather than re-reading options on every claimed row.
     */
    private string $backoffMode;

    public function __construct(RetryQueue $queue, WpMailBridge $bridge)
    {
        $this->queue       = $queue;
        $this->bridge      = $bridge;
        $this->backoffMode = (string) PluginSettings::make()->get('retry_backoff', 'exponential');
    }

    /**
     * Claim up to $batch due rows and resolve each: delivered rows are removed, retryable failures
     * are rescheduled with backoff, everything else (attempts exhausted or no-longer-retryable) is
     * dropped.
     */
    public function process(int $batch = self::DEFAULT_BATCH): void
    {
        foreach ($this->queue->claimDue($batch) as $row) {
            // Re-assert the lock right before sending: a batch can outlive its claim, letting an
            // overlapping cron run reclaim the tail. Skipping a row we no longer own is what stops
            // the same message from being delivered twice.
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

            if ($failureClass !== null && $attempts < $row['max_attempts'] && FailureCategory::isRetryable($failureClass)) {
                $this->queue->reschedule($row['id'], $attempts, $failureClass, $this->backoffDelay($attempts));

                continue;
            }

            // Terminal: attempts exhausted, or the re-classified failure is no longer retryable.
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
     * Pure base/cap/jitter backoff formula, callable without a RetryWorker instance so
     * WpMailBridge can compute an initial enqueue delay without constructing one. Already jittered
     * — callers must not apply jitter a second time.
     */
    public static function computeDelay(int $attempt, string $mode): int
    {
        $exponent = max(0, $attempt - 1);
        $seconds  = $mode === 'fixed' ? self::BASE_SECONDS : self::BASE_SECONDS * (2 ** $exponent);
        $capped   = min(self::CAP_SECONDS, $seconds);

        return self::applyJitter($capped);
    }

    /**
     * +/- JITTER_RATIO random spread so rows enqueued in the same burst don't all wake in the same
     * cron tick and hammer the same provider simultaneously; clamped to [1, CAP_SECONDS].
     */
    private static function applyJitter(int $seconds): int
    {
        $spread   = (int) round($seconds * self::JITTER_RATIO);
        $jittered = $spread > 0 ? $seconds + random_int(-$spread, $spread) : $seconds;

        return max(1, min(self::CAP_SECONDS, $jittered));
    }
}
