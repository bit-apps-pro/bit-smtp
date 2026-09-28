<?php

namespace BitApps\SMTP\Mail\Tracking;

\defined('ABSPATH') || exit();

/**
 * Click endpoint handler: record click and return verified signed destination URL to prevent open redirects.
 */
final class ClickTracker
{
    private EngagementRecorder $recorder;

    public function __construct(?EngagementRecorder $recorder = null)
    {
        $this->recorder = $recorder ?? new EngagementRecorder();
    }

    /**
     * Record click for token and return its verified redirect destination, or null to fall back to site home.
     *
     * @param array<string,mixed> $request
     */
    public function handle(string $token, array $request): ?string
    {
        $payload = TokenSigner::verify($token);
        if ($payload === null) {
            return null;
        }

        $this->recorder->recordVerified($payload, EngagementRecorder::TYPE_CLICK, $request);

        return self::resolveUrl($payload);
    }

    /**
     * Resolve verified redirect target for click token after signature and protocol verification; returns null if invalid.
     */
    public static function destination(string $token): ?string
    {
        $payload = TokenSigner::verify($token);
        if ($payload === null) {
            return null;
        }

        return self::resolveUrl($payload);
    }

    /**
     * Resolve redirect target from payload; decode HTML entities captured from href.
     *
     * @param array<string,mixed> $payload
     */
    private static function resolveUrl(array $payload): ?string
    {
        $url = $payload['u'] ?? null;
        if (!\is_string($url)) {
            return null;
        }

        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5);
        if (stripos($url, 'http://') !== 0 && stripos($url, 'https://') !== 0) {
            return null;
        }

        return $url;
    }
}
