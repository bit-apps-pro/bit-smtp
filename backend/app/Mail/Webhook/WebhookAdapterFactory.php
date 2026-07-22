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
    // Brevo webhook correlation is UNVERIFIED (does message-id == send messageId? does
    // X-Mailin-custom round-trip?). Kill-switch stays off until proven live (Plan B Task 9).
    public const BREVO_WEBHOOK_ENABLED = false;

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
