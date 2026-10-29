<?php

declare(strict_types=1);

namespace BitApps\SMTP\HTTP;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Detection\ClientIpResolver;

\defined('ABSPATH') || exit();

/**
 * Unslashed, sanitized reads of the current request's $_SERVER values.
 */
final class ServerRequest
{
    /**
     * URL path of the request with percent-encoding preserved, or null when it cannot be parsed.
     */
    public static function path(): ?string
    {
        $uri  = isset($_SERVER['REQUEST_URI']) ? sanitize_url(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $path = wp_parse_url($uri, PHP_URL_PATH);

        return \is_string($path) ? $path : null;
    }

    /**
     * Upper-cased HTTP method, or '' when absent.
     */
    public static function method(): string
    {
        return strtoupper(self::value('REQUEST_METHOD'));
    }

    /**
     * Declared request body size in bytes, 0 when absent or malformed.
     */
    public static function contentLength(): int
    {
        return absint(self::value('CONTENT_LENGTH'));
    }

    /**
     * The client's User-Agent header as plain text.
     */
    public static function userAgent(): string
    {
        return self::value('HTTP_USER_AGENT');
    }

    /**
     * Validated client IP (forwarded headers honoured only from trusted proxies), or null when invalid.
     */
    public static function clientIp(): ?string
    {
        $ip = ClientIpResolver::checkIP();

        return $ip === false ? null : $ip;
    }

    /**
     * A scalar $_SERVER entry as sanitized plain text, or '' when absent.
     */
    public static function value(string $key): string
    {
        return isset($_SERVER[$key]) && \is_string($_SERVER[$key]) ? sanitize_text_field(wp_unslash($_SERVER[$key])) : '';
    }
}
