<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Webhook\Signatures;

use BitApps\SMTP\Mail\Webhook\Signatures\Contracts\WebhookSignatureVerifierInterface;

/**
 * Resolves the signature verifier for a provider, or null when that provider has no signed webhook.
 */
final class WebhookSignatureVerifierFactory
{
    /**
     * Provider → verifier map; absent providers (postmark, brevo, mailjet, sparkpost) offer no webhook signing.
     *
     * @var array<string, class-string<WebhookSignatureVerifierInterface>>
     */
    private const VERIFIERS = [
        'sendgrid'   => SendGridWebhookSignatureVerifier::class,
        'mailgun'    => MailgunWebhookSignatureVerifier::class,
        'resend'     => ResendWebhookSignatureVerifier::class,
        'zeptomail'  => ZeptoWebhookSignatureVerifier::class,
        'amazon_ses' => SnsSignatureVerifier::class,
    ];

    public function forProvider(string $provider): ?WebhookSignatureVerifierInterface
    {
        if (!isset(self::VERIFIERS[$provider])) {
            return null;
        }

        $verifier = self::VERIFIERS[$provider];

        return new $verifier();
    }
}
