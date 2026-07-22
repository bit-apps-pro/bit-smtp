<?php

declare(strict_types=1);

namespace BitApps\SMTP\HTTP\Webhook;

use BitApps\SMTP\HTTP\Controllers\WebhookController;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;

/**
 * Front-end HTTP entry point for the delivery-webhook endpoint (bit-smtp/{id}/{secret}): matches the
 * request on parse_request, enforces method + body-size limits, then delegates to WebhookController.
 */
final class WebhookRouter
{
    private const MAX_BODY_BYTES = 1048576;

    private const ROUTE_PATTERN = '#^bit-smtp/([^/]+)/([^/]+)$#';

    /**
     * Extract the connection id + secret from a request path, or null when it is not a webhook URL.
     * Pure (no WordPress) so it can be unit-tested in isolation.
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
     * parse_request glue: on a webhook match, runs the request end-to-end and terminates; otherwise
     * returns so WordPress keeps handling the request.
     *
     * @param mixed $wp WP object passed by parse_request (unused)
     */
    public function match($wp = null): void
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

        // Reject on the declared size BEFORE reading, so a hostile Content-Length never buffers a huge
        // payload into memory.
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY_BYTES) {
            status_header(413);

            exit;
        }

        $raw = (string) file_get_contents('php://input');
        if (\strlen($raw) > self::MAX_BODY_BYTES) {
            status_header(413);

            exit;
        }

        $request = WebhookRequest::fromRaw($raw, $this->requestHeaders());
        $code    = (new WebhookController())->handle($matched['id'], $matched['secret'], $request);

        status_header($code);

        exit;
    }

    /**
     * Reconstruct inbound headers from $_SERVER, since getallheaders() is unavailable off Apache.
     * WebhookRequest lower-cases the keys, so the HTTP_* de-prefixing here is case-insensitive.
     *
     * @return array<string, string>
     */
    private function requestHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            $key = (string) $key;
            if (strpos($key, 'HTTP_') === 0) {
                $name           = str_replace('_', '-', substr($key, 5));
                $headers[$name] = (string) $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['CONTENT-TYPE'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        return $headers;
    }
}
