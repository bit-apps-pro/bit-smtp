<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Webhook;

use BitApps\SMTP\Mail\Webhook\Adapters\BrevoWebhookAdapter;
use BitApps\SMTP\Mail\Webhook\Adapters\PostmarkWebhookAdapter;
use BitApps\SMTP\Mail\Webhook\Contracts\WebhookAdapterInterface;

/**
 * Resolves the webhook adapter for a provider, or null when that provider has no live receiver.
 */
final class WebhookAdapterFactory
{
    // Brevo webhook correlation verified live: the webhook `message-id` equals the send `messageId`
    // and `X-Mailin-custom` round-trips the stamped tracking id, both matched against real payloads.
    public const BREVO_WEBHOOK_ENABLED = true;

    public function forProvider(string $provider): ?WebhookAdapterInterface
    {
        switch ($provider) {
            case 'postmark':
                return new PostmarkWebhookAdapter();
            case 'brevo':
                return self::BREVO_WEBHOOK_ENABLED ? new BrevoWebhookAdapter() : null;
            default:
                return null;
        }
    }
}
