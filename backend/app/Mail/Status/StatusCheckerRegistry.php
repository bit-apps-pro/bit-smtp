<?php

namespace BitApps\SMTP\Mail\Status;

use BitApps\SMTP\Mail\Contracts\MessageStatusCheckerInterface;
use BitApps\SMTP\Mail\Http\ApiClient;

/**
 * Maps a provider slug to its delivery-status checker and knows how to pull the provider's
 * message id out of a successful send's debug payload. Providers without support yield null,
 * so callers gracefully fall back to plain send-accepted behaviour.
 */
final class StatusCheckerRegistry
{
    private const BREVO = 'brevo';

    private ApiClient $client;

    public function __construct(ApiClient $client)
    {
        $this->client = $client;
    }

    public function checkerFor(string $provider): ?MessageStatusCheckerInterface
    {
        if ($provider === self::BREVO) {
            return new BrevoStatusChecker($this->client);
        }

        return null;
    }

    public function messageIdFrom(string $provider, array $debug): ?string
    {
        if ($provider !== self::BREVO) {
            return null;
        }

        $body = $debug['body'] ?? null;

        return \is_array($body) ? ($body['messageId'] ?? null) : null;
    }
}
