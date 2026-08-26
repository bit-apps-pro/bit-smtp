<?php

namespace BitApps\SMTP\Mail\Webhook;

/**
 * Immutable value object wrapping a raw inbound webhook HTTP request body.
 */
class WebhookRequest
{
    private string $rawBody;

    private array $headers;

    /**
     * @var array<string,mixed>|null
     */
    private ?array $decoded = null;

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
        // Memoized: the SES/SNS inbound path decodes the same body several times (verify → control
        // plane → adapter), and this object is immutable, so a single decode is safe to reuse.
        if ($this->decoded === null) {
            $decoded       = json_decode($this->rawBody, true);
            $this->decoded = \is_array($decoded) ? $decoded : [];
        }

        return $this->decoded;
    }
}
