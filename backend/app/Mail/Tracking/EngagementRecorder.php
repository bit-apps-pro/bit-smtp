<?php

namespace BitApps\SMTP\Mail\Tracking;

use BitApps\SMTP\HTTP\Services\LogService;
use Throwable;

\defined('ABSPATH') || exit();

/**
 * Turns a verified tracking hit into a folded engagement row. Verifies the signed token, resolves the
 * log it belongs to, classifies the fire as human vs automated, and folds it via LogService. A bad
 * token or an unknown/purged log is a silent no-op — nothing is written, so token validity can never
 * be probed. Never throws to its caller: the public endpoint must always be able to respond.
 */
final class EngagementRecorder
{
    public const TYPE_OPEN = 'open';

    public const TYPE_CLICK = 'click';

    /**
     * Age (in seconds) reported when a log's send time is unknown/unparseable: large enough that the
     * prefetch heuristic never fires, so an indeterminate age is counted as human, not automated.
     */
    private const UNKNOWN_AGE = PHP_INT_MAX;

    private LogService $logs;

    public function __construct(?LogService $logs = null)
    {
        $this->logs = $logs ?? new LogService();
    }

    /**
     * Record one open/click fire for $signedToken. No-op on a forged/tampered/absent signature or a
     * token that resolves to no persisted log. $request supplies the raw client signals ('user_agent',
     * 'ip') used only to classify the fire.
     *
     * @param array<string,mixed> $request
     */
    public function record(string $signedToken, string $type, array $request): void
    {
        try {
            $payload = TokenSigner::verify($signedToken);
            if ($payload !== null) {
                $this->fold($payload, $type, $request);
            }
        } catch (Throwable $e) {
            // Fail open: an unauthenticated tracking hit must never surface an error, and a failed
            // record must not block the benign pixel/redirect response.
        }
    }

    /**
     * Record a fire whose token the caller has ALREADY verified, so the signature is not checked twice
     * — the click path verifies once and threads the same payload into recording and redirect
     * resolution. Same no-op-on-unknown-log and never-throw contract as record().
     *
     * @param array<string,mixed> $payload the verified TokenSigner payload
     * @param array<string,mixed> $request
     */
    public function recordVerified(array $payload, string $type, array $request): void
    {
        try {
            $this->fold($payload, $type, $request);
        } catch (Throwable $e) {
            // Fail open: see record() — a failed record must never break the tracking response.
        }
    }

    /**
     * Resolve the log a verified payload points at and fold one engagement fire onto it. A tracking id
     * matching no persisted log is a silent no-op, so token validity can never be probed.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $request
     */
    private function fold(array $payload, string $type, array $request): void
    {
        $trackingId = \is_string($payload['t'] ?? null) ? $payload['t'] : '';
        $log        = $this->logs->findByTrackingId($trackingId);
        if ($log === null) {
            return;
        }

        $target = ($type === self::TYPE_CLICK && \is_string($payload['u'] ?? null)) ? $payload['u'] : '';

        $automated = BotDetector::classify(
            \is_string($request['user_agent'] ?? null) ? $request['user_agent'] : '',
            \is_string($request['ip'] ?? null) ? $request['ip'] : null,
            $this->secondsSinceSend($log->created_at_utc)
        );

        $this->logs->recordEngagement((int) $log->id, $type, $target, $automated);
    }

    /**
     * Seconds between a log's send time (UTC) and now. Returns UNKNOWN_AGE when the timestamp is
     * missing/unparseable, so the prefetch heuristic is skipped rather than misfired.
     */
    private function secondsSinceSend(?string $sentAt): int
    {
        if ($sentAt === null || $sentAt === '') {
            return self::UNKNOWN_AGE;
        }

        $timestamp = strtotime($sentAt . ' UTC');

        return $timestamp === false ? self::UNKNOWN_AGE : time() - $timestamp;
    }
}
