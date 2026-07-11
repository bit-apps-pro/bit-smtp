<?php

namespace BitApps\SMTP\Mail\Http;

/**
 * Immutable value object for a completed ApiClient request.
 */
class ApiResponse
{
    private int $status;

    /**
     * @var array|string
     */
    private $body;

    private array $headers;

    /**
     * @param array|string $body
     */
    public function __construct(int $status, $body, array $headers = [])
    {
        $this->status  = $status;
        $this->body    = $body;
        $this->headers = $headers;
    }

    public function isOk(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @return array|string
     */
    public function getBody()
    {
        return $this->body;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * @return null|string
     */
    public function getHeader(string $key)
    {
        foreach ($this->headers as $name => $value) {
            if (strcasecmp((string) $name, $key) === 0) {
                return \is_array($value) ? implode(';', $value) : (string) $value;
            }
        }

    }
}
