<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Support;

/**
 * Mutable value object a transport builds and an auth strategy signs.
 */
final class ApiRequest
{
    public string $method;

    public string $url;

    public array $headers;

    public string $body;

    public string $contentType;

    public function __construct(string $method, string $url, string $body, string $contentType)
    {
        $this->method      = $method;
        $this->url         = $url;
        $this->body        = $body;
        $this->contentType = $contentType;
        $this->headers     = ['Content-Type' => $contentType];
    }

    public function setHeader(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }
}
