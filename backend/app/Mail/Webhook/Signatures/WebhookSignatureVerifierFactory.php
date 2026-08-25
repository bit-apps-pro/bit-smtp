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
        // postmark/brevo/mailjet/sparkpost are intentionally absent: per each provider's own docs
        // they do NOT cryptographically sign the event payload (they offer HTTP Basic Auth / a
        // shared secret in the callback URL / IP allowlisting only). The per-connection URL secret
        // enforced in WebhookController IS that shared secret, so it is already the correct ceiling
        // for these four -- do not add a "signature" verifier here; there is nothing to verify. (The
        // SparkPost->Bird successor platform does sign, but that is a different API this plugin does
        // not integrate; a Bird verifier would be a separate, provider-gated path.)
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
