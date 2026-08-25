<?php

namespace BitApps\SMTP\Mail\Tracking;

\defined('ABSPATH') || exit();

/**
 * Thin open-endpoint handler: records an open fire for the signed token. The router emits the pixel
 * regardless of the outcome, so a bad token records nothing yet still returns a normal response.
 */
final class OpenTracker
{
    private EngagementRecorder $recorder;

    public function __construct(?EngagementRecorder $recorder = null)
    {
        $this->recorder = $recorder ?? new EngagementRecorder();
    }

    /**
     * Record an open fire for $token; a bad/unknown token is a silent no-op.
     *
     * @param array<string,mixed> $request
     */
    public function handle(string $token, array $request): void
    {
        $this->recorder->record($token, EngagementRecorder::TYPE_OPEN, $request);
    }
}
