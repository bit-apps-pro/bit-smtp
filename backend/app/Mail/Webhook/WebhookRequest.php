<?php

namespace BitApps\SMTP\Mail\Webhook;

/**
 * Immutable value object wrapping a raw inbound webhook HTTP request body.
 */
class WebhookRequest
{
    private string $rawBody;

    private array $headers;

    private function __construct(string $rawBody, array $headers)
    {
        $this->rawBody = $rawBody;
        $this->headers = $headers;
    }

    public static function fromRaw(string $rawBody, array $headers = []): self
    {
        return new self($rawBody, $headers);
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return \is_array($value) ? null : (string) $value;
            }
        }

        return null;
    }

    public function decoded(): array
    {
        $decoded = json_decode($this->rawBody, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
