<?php

namespace BitApps\SMTP\Mail\Tracking;

\defined('ABSPATH') || exit();

/**
 * Thin click-endpoint handler: records a click fire and resolves the destination to redirect to. The
 * destination is the token's own signed 'u' and is returned ONLY after HMAC verification — the
 * signature is the allowlist, so the redirect provably points at a URL this site itself emitted and
 * can never be turned into an open redirect.
 */
final class ClickTracker
{
    private EngagementRecorder $recorder;

    public function __construct(?EngagementRecorder $recorder = null)
    {
        $this->recorder = $recorder ?? new EngagementRecorder();
    }

    /**
     * Record the click for $token and return its verified redirect destination (or null so the router
     * falls back to the site home). Verifies the token ONCE and reuses the payload for both recording
     * and destination resolution.
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
     * The verified redirect target for a click token: its 'u' only after the signature verifies and a
     * defense-in-depth http(s) recheck. Returns null for a forged/tampered token or a non-http(s)
     * destination. Pure and DB-free, so a throttled hit can still redirect correctly without a write.
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
     * Resolve the redirect target from an already-verified payload: its 'u' after an http(s) recheck,
     * or null for a missing/non-http(s) destination. The signature check is the caller's job.
     *
     * @param array<string,mixed> $payload
     */
    private static function resolveUrl(array $payload): ?string
    {
        $url = $payload['u'] ?? null;
        if (!\is_string($url)) {
            return null;
        }

        // The URL was captured from an HTML href, so entities (&amp; -> &) must be decoded before we
        // redirect, or the destination's query string is mangled. Scheme is re-checked after decoding.
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5);
        if (stripos($url, 'http://') !== 0 && stripos($url, 'https://') !== 0) {
            return null;
        }

        return $url;
    }
}
