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
     * @var array<string, class-string<WebhookSignatureVerifierInterface>> single source of truth for provider → verifier
     */
    private const VERIFIERS = [
        'sendgrid'  => SendGridWebhookSignatureVerifier::class,
        'mailgun'   => MailgunWebhookSignatureVerifier::class,
        'resend'    => ResendWebhookSignatureVerifier::class,
        'zeptomail' => ZeptoWebhookSignatureVerifier::class,
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
