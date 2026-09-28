<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Webhook;

use BitApps\SMTP\Mail\Connections\Connection;
use RuntimeException;

/**
 * Compose webhook URL: same-origin, https-only, pins to site preventing SSRF, embeds per-connection secret (sensitive).
 */
final class WebhookUrl
{
    public static function forConnection(Connection $connection): string
    {
        $url = home_url('/bit-smtp/' . $connection->getId() . '/' . $connection->getWebhookSecret());

        if (strpos($url, 'https://') !== 0) {
            throw new RuntimeException('A public HTTPS site URL is required before registering a delivery webhook.');
        }

        return $url;
    }
}
