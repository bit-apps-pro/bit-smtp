<?php

declare(strict_types=1);

namespace BitApps\SMTP\HTTP\Tracking;

use BitApps\SMTP\Mail\Tracking\ClickTracker;
use BitApps\SMTP\Mail\Tracking\OpenTracker;
use BitApps\SMTP\Mail\Tracking\TrackingRoutes;

/**
 * Front-end HTTP entry point for the public open/click endpoints (bit-smtp/track/{open|click}/{token}):
 * matches the request on template_redirect, then always returns a benign response — a 1x1 GIF for an
 * open, a 302 for a click — whether or not the token is valid, so token validity can never be probed.
 * The four-segment path cannot collide with the two-segment webhook or three-segment OAuth routes.
 */
final class TrackingRouter
{
    // Derived from TrackingRoutes so the matcher and the URL builder share one path definition; the
    // segments are metacharacter-free literals, so no preg_quote is needed to keep it a compile-time const.
    private const ROUTE_PATTERN = '#^' . TrackingRoutes::BASE . '/(' . TrackingRoutes::OPEN . '|' . TrackingRoutes::CLICK . ')/([^/]+)$#';

    /**
     * Tokens longer than this are rejected before any crypto work — a signed token is far shorter, so
     * an oversized value is only ever an abuse probe.
     */
    private const MAX_TOKEN_LENGTH = 4096;

    private const THROTTLE_KEY_PREFIX = 'bit_smtp_track_throttle_';

    /**
     * Per-IP write budget per window. Defense-in-depth only: storage is already bounded by the
     * ON DUPLICATE KEY fold, so an exceeded budget skips the DB write but still returns the response.
     */
    private const THROTTLE_LIMIT = 120;

    private const THROTTLE_WINDOW = 60;

    /**
     * A 1x1 transparent GIF (43 bytes), base64-encoded. Emitted for every open hit, valid or not.
     */
    private const PIXEL_GIF_BASE64 = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    /**
     * Extract the endpoint type + token from a request path, or null when it is not a tracking URL.
     * Pure (no WordPress) so it can be unit-tested in isolation; matches ONLY the four-segment shape.
     *
     * @return array{type: string, token: string}|null
     */
    public static function parse(?string $path, string $homePath): ?array
    {
        if ($path === null) {
            return null;
        }

        $home      = trim($homePath, '/');
        $candidate = trim($path, '/');
        if ($home !== '') {
            if ($candidate === $home) {
                $candidate = '';
            } elseif (strncmp($candidate, $home . '/', \strlen($home) + 1) === 0) {
                $candidate = substr($candidate, \strlen($home) + 1);
            }
        }

        if (preg_match(self::ROUTE_PATTERN, $candidate, $matches) !== 1) {
            return null;
        }

        return ['type' => $matches[1], 'token' => $matches[2]];
    }

    /**
     * template_redirect glue: on a tracking match, records the hit (throttle- and length-gated) and
     * terminates with the benign response; otherwise returns so WordPress keeps handling the request.
     */
    public function match(): void
    {
        $path     = wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $homePath = wp_parse_url(home_url('/', 'relative'), PHP_URL_PATH);
        $matched  = self::parse(
            \is_string($path) ? $path : null,
            \is_string($homePath) ? $homePath : '/'
        );
        if ($matched === null) {
            return;
        }

        // GET only. A wrong verb still returns a benign status and never writes or leaks validity.
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            status_header(405);

            exit;
        }

        $token = $matched['token'];
        // Skip the write on an oversized token or a throttled IP, but always send the response below.
        $record = \strlen($token) <= self::MAX_TOKEN_LENGTH && !$this->throttled();

        if ($matched['type'] === TrackingRoutes::OPEN) {
            if ($record) {
                (new OpenTracker())->handle($token, $this->clientSignals());
            }

            $this->emitPixel();
        }

        $destination = $record
            ? (new ClickTracker())->handle($token, $this->clientSignals())
            : ClickTracker::destination($token);

        // wp_redirect (not wp_safe_redirect): a click destination is legitimately off-site, and the
        // HMAC on the token has already vouched that this is a URL this site itself emitted.
        wp_redirect($destination ?? home_url('/'));

        exit;
    }

    /**
     * Raw client signals used only to classify a fire as human vs automated.
     *
     * @return array{ip: string|null, user_agent: string}
     */
    private function clientSignals(): array
    {
        $ip = $_SERVER['REMOTE_ADDR']     ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        return [
            'ip'         => \is_string($ip) ? $ip : null,
            'user_agent' => \is_string($ua) ? $ua : '',
        ];
    }

    /**
     * True once the connecting IP has spent its write budget for the current window. Buckets on the
     * hashed REMOTE_ADDR (never a forwarded header, which is spoofable); a missing IP is not throttled.
     */
    private function throttled(): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!\is_string($ip) || $ip === '') {
            return false;
        }

        $key  = self::THROTTLE_KEY_PREFIX . md5($ip);
        $hits = (int) get_transient($key);
        if ($hits >= self::THROTTLE_LIMIT) {
            return true;
        }

        set_transient($key, $hits + 1, self::THROTTLE_WINDOW);

        return false;
    }

    /**
     * Emit the 1x1 transparent GIF with no-store headers and terminate. Never returns.
     */
    private function emitPixel(): void
    {
        $gif = (string) base64_decode(self::PIXEL_GIF_BASE64, true);

        status_header(200);
        header('Content-Type: image/gif');
        header('Content-Length: ' . \strlen($gif));
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
        echo $gif;

        exit;
    }
}
