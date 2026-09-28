<?php

declare(strict_types=1);

namespace BitApps\SMTP\HTTP\Webhook;

use BitApps\SMTP\HTTP\Controllers\WebhookController;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;

/**
 * Front-end for delivery-webhook endpoint (match, enforce limits, delegate to controller).
 */
final class WebhookRouter
{
    private const MAX_BODY_BYTES = 1048576;

    private const ROUTE_PATTERN = '#^bit-smtp/([^/]+)/([^/]+)$#';

    /**
     * Extract connection id/secret from path (pure, testable in isolation).
     *
     * @return array{id: string, secret: string}|null
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

        return ['id' => $matches[1], 'secret' => $matches[2]];
    }

    /**
     * template_redirect hook (match + run request end-to-end, or pass through).
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

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            status_header(405);

            exit;
        }

        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY_BYTES) {
            status_header(413);

            exit;
        }

        $raw = (string) file_get_contents('php://input');
        if (\strlen($raw) > self::MAX_BODY_BYTES) {
            status_header(413);

            exit;
        }

        $request = WebhookRequest::fromRaw($raw, $this->headers());
        $code    = (new WebhookController())->handle($matched['id'], $matched['secret'], $request);

        status_header($code);

        exit;
    }

    private function headers(): array
    {
        if (\function_exists('getallheaders')) {
            $headers = getallheaders();
            if (\is_array($headers) && $headers !== []) {
                return $headers;
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strncmp($key, 'HTTP_', 5) !== 0 || !\is_string($value)) {
                continue;
            }
            $name           = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$name] = $value;
        }

        return $headers;
    }
}
