<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Webhook;

use BitApps\SMTP\Mail\Webhook\Adapters\BrevoWebhookAdapter;
use BitApps\SMTP\Mail\Webhook\Adapters\MailgunWebhookAdapter;
use BitApps\SMTP\Mail\Webhook\Adapters\MailjetWebhookAdapter;
use BitApps\SMTP\Mail\Webhook\Adapters\PostmarkWebhookAdapter;
use BitApps\SMTP\Mail\Webhook\Adapters\ResendWebhookAdapter;
use BitApps\SMTP\Mail\Webhook\Adapters\SendGridWebhookAdapter;
use BitApps\SMTP\Mail\Webhook\Adapters\SesSnsWebhookAdapter;
use BitApps\SMTP\Mail\Webhook\Adapters\SparkPostWebhookAdapter;
use BitApps\SMTP\Mail\Webhook\Adapters\ZeptoWebhookAdapter;
use BitApps\SMTP\Mail\Webhook\Contracts\WebhookAdapterInterface;

/**
 * Resolves the webhook adapter for a provider, or null when that provider has no live receiver.
 */
final class WebhookAdapterFactory
{
    // Brevo webhook correlation verified live: the webhook `message-id` equals the send `messageId`
    // and `X-Mailin-custom` round-trips the stamped tracking id, both matched against real payloads.
    public const BREVO_WEBHOOK_ENABLED = true;

    /**
     * @var array<string, class-string<WebhookAdapterInterface>> single source of truth for provider → adapter
     */
    private const ADAPTERS = [
        'postmark'  => PostmarkWebhookAdapter::class,
        'brevo'     => BrevoWebhookAdapter::class,
        'sendgrid'  => SendGridWebhookAdapter::class,
        'mailgun'   => MailgunWebhookAdapter::class,
        'resend'    => ResendWebhookAdapter::class,
        'mailjet'   => MailjetWebhookAdapter::class,
        'sparkpost' => SparkPostWebhookAdapter::class,
        'zeptomail' => ZeptoWebhookAdapter::class,
        // SES has no provider webhook API — it delivers bounce/complaint/delivery over SNS, which the
        // user wires manually to this endpoint; the adapter parses the SNS-wrapped SES notification.
        'amazon_ses' => SesSnsWebhookAdapter::class,
    ];

    public static function supportsProvider(string $provider): bool
    {
        if ($provider === 'brevo') {
            return self::BREVO_WEBHOOK_ENABLED;
        }

        return isset(self::ADAPTERS[$provider]);
    }

    public function forProvider(string $provider): ?WebhookAdapterInterface
    {
        if (!self::supportsProvider($provider)) {
            return null;
        }

        $adapter = self::ADAPTERS[$provider];

        return new $adapter();
    }
}
