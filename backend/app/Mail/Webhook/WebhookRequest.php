<?php

namespace BitApps\SMTP\Mail\Webhook;

/**
 * Immutable value object wrapping a raw inbound webhook HTTP request body.
 */
class WebhookRequest
{
    private string $rawBody;

    private function __construct(string $rawBody)
    {
        $this->rawBody = $rawBody;
    }

    public static function fromRaw(string $rawBody): self
    {
        return new self($rawBody);
    }

    public function decoded(): array
    {
        $decoded = json_decode($this->rawBody, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
