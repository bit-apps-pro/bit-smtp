<?php

namespace BitApps\SMTP\Mail\Webhook;

/**
 * Immutable value object wrapping a raw inbound webhook HTTP request.
 */
class WebhookRequest
{
    private string $rawBody;

    /**
     * @var array<string, string> headers keyed by lower-cased name
     */
    private array $headers;

    private function __construct(string $rawBody, array $headers)
    {
        $this->rawBody = $rawBody;
        $this->headers = $headers;
    }

    public static function fromRaw(string $rawBody, array $headers): self
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = (string) $value;
        }

        return new self($rawBody, $normalized);
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function decoded(): array
    {
        $decoded = json_decode($this->rawBody, true);

        return \is_array($decoded) ? $decoded : [];
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Parse an `Authorization: Basic <base64>` header directly from the header string, since
     * FrankenPHP/CGI often omits $_SERVER['PHP_AUTH_USER'].
     *
     * @return array{user: string, pass: string}|null
     */
    public function basicAuth(): ?array
    {
        $header = $this->header('authorization');
        if ($header === null || stripos($header, 'basic ') !== 0) {
            return null;
        }

        $decoded = base64_decode(trim(substr($header, 6)), true);
        if ($decoded === false || strpos($decoded, ':') === false) {
            return null;
        }

        [$user, $pass] = explode(':', $decoded, 2);

        return ['user' => $user, 'pass' => $pass];
    }
}
