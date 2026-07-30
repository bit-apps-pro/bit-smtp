<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Webhook;

use BitApps\SMTP\Mail\Connections\Connection;
use RuntimeException;

/**
 * Composes the single, same-origin, https-only URL a provider posts delivery events to. The https
 * guarantee also pins the target to this site, so provisioning can't be aimed at an internal host
 * (SSRF). The path embeds the per-connection secret, so a composed URL must be treated as sensitive.
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
