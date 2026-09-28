<?php

namespace BitApps\SMTP\Mail\Tracking;

use BitApps\SMTP\HTTP\Services\LogService;
use Throwable;

\defined('ABSPATH') || exit();

/**
 * Record tracking hit as engagement: verify token, classify as human/automated, silent no-op on invalid token.
 */
final class EngagementRecorder
{
    public const TYPE_OPEN = 'open';

    public const TYPE_CLICK = 'click';

    /**
     * Age constant for unknown log send time; large enough to classify as human, not automated.
     */
    private const UNKNOWN_AGE = PHP_INT_MAX;

    private LogService $logs;

    public function __construct(?LogService $logs = null)
    {
        $this->logs = $logs ?? new LogService();
    }

    /**
     * Record open/click fire for signed token; no-op on invalid token or missing log.
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
        }
    }

    /**
     * Record fire with pre-verified token to avoid double verification; same no-op and error-handling contract as record().
     *
     * @param array<string,mixed> $payload the verified TokenSigner payload
     * @param array<string,mixed> $request
     */
    public function recordVerified(array $payload, string $type, array $request): void
    {
        try {
            $this->fold($payload, $type, $request);
        } catch (Throwable $e) {
        }
    }

    /**
     * Resolve log from verified payload and fold engagement fire onto it; silent no-op on missing log.
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
     * Calculate seconds since log was sent; returns UNKNOWN_AGE for missing/unparseable timestamps.
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
