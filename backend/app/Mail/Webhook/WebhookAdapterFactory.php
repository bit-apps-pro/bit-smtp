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
    /**
     * Brevo webhook correlation verified live; message-id and X-Mailin-custom round-trip correctly.
     */
    public const BREVO_WEBHOOK_ENABLED = true;

    /**
     * Provider → adapter map; SES uses SNS integration (no direct webhook API).
     *
     * @var array<string, class-string<WebhookAdapterInterface>>
     */
    private const ADAPTERS = [
        'postmark'   => PostmarkWebhookAdapter::class,
        'brevo'      => BrevoWebhookAdapter::class,
        'sendgrid'   => SendGridWebhookAdapter::class,
        'mailgun'    => MailgunWebhookAdapter::class,
        'resend'     => ResendWebhookAdapter::class,
        'mailjet'    => MailjetWebhookAdapter::class,
        'sparkpost'  => SparkPostWebhookAdapter::class,
        'zeptomail'  => ZeptoWebhookAdapter::class,
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
