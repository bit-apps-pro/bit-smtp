<?php

declare(strict_types=1);

namespace BitApps\SMTP\HTTP\Tracking;

use BitApps\SMTP\Mail\Tracking\ClickTracker;
use BitApps\SMTP\Mail\Tracking\OpenTracker;
use BitApps\SMTP\Mail\Tracking\TrackingRoutes;

/**
 * Front-end for public open/click endpoints (benign response regardless of token validity).
 */
final class TrackingRouter
{
    private const ROUTE_PATTERN = '#^' . TrackingRoutes::BASE . '/(' . TrackingRoutes::OPEN . '|' . TrackingRoutes::CLICK . ')/([^/]+)$#';

    /**
     * Max token length (detect oversized abuse probes before crypto work).
     */
    private const MAX_TOKEN_LENGTH = 4096;

    private const THROTTLE_KEY_PREFIX = 'bit_smtp_track_throttle_';

    /**
     * Per-IP write budget per window (defense-in-depth, doesn't block response).
     */
    private const THROTTLE_LIMIT = 120;

    private const THROTTLE_WINDOW = 60;

    /**
     * 1x1 transparent GIF base64-encoded (emitted for all open hits).
     */
    private const PIXEL_GIF_BASE64 = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    /**
     * Extract endpoint type/token from path (pure, testable in isolation, four-segment only).
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
     * template_redirect hook (match + record hit, return benign response, or pass through).
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

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            status_header(405);

            exit;
        }

        $token  = $matched['token'];
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
     * Check if connecting IP has exceeded write budget; bucket on hashed REMOTE_ADDR (not forwarded headers).
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
