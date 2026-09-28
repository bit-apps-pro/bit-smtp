<?php

namespace BitApps\SMTP\Mail\Http;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Client\HttpClient;
use Traversable;

/**
 * Provider-agnostic HTTP client for API-based transports; normalizes responses.
 */
class ApiClient
{
    private HttpClient $http;

    private array $headers = [];

    private ?int $timeout = null;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    public function setHeaders(array $headers): self
    {
        $this->headers = $headers;

        return $this;
    }

    /**
     * Return a clone with timeout applied to avoid leaking mutations onto the shared instance.
     */
    public function withTimeout(?int $seconds): self
    {
        $clone          = clone $this;
        $clone->timeout = $seconds;

        return $clone;
    }

    public function addHeader(string $key, string $value): self
    {
        $this->headers[$key] = $value;

        return $this;
    }

    public function withHeaders(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);

        return $this;
    }

    public function get(string $url, array $query = []): ApiResponse
    {
        if (!empty($query)) {
            $separator = strpos($url, '?') === false ? '?' : '&';
            $url .= $separator . http_build_query($query);
        }

        return $this->send('get', $url, null);
    }

    /**
     * @param array|string $body
     */
    public function post(string $url, $body = []): ApiResponse
    {
        return $this->send('post', $url, $body);
    }

    /**
     * POST form-encoded body; resets headers to prevent leaking stale headers.
     *
     * @param array<string,mixed> $fields
     */
    public function postForm(string $url, array $fields): ApiResponse
    {
        $this->headers = ['Content-Type' => 'application/x-www-form-urlencoded'];

        return $this->send('post', $url, http_build_query($fields));
    }

    /**
     * @param array|string $body
     */
    public function put(string $url, $body = []): ApiResponse
    {
        return $this->send('put', $url, $body);
    }

    /**
     * @param array|string $body
     */
    public function patch(string $url, $body = []): ApiResponse
    {
        return $this->send('patch', $url, $body);
    }

    /**
     * @param array|string $body
     */
    public function delete(string $url, $body = []): ApiResponse
    {
        return $this->send('delete', $url, $body);
    }

    /**
     * Send HTTP request; redirection disabled to prevent SSRF via compromised provider redirects.
     *
     * @param array|string|null $body
     */
    private function send(string $method, string $url, $body): ApiResponse
    {
        $options = ['redirection' => 0];
        if ($this->timeout !== null) {
            $options['timeout'] = $this->timeout;
        }

        $result = $this->http->request($url, $method, $this->prepareBody($body), $this->headers, $options);

        if (is_wp_error($result)) {
            return new ApiResponse(0, implode(', ', $result->get_error_messages()), []);
        }

        $headers = $this->normalizeHeaders($this->http->getResponseHeaders());

        return new ApiResponse((int) $this->http->getResponseCode(), $this->decodeBody($result, $headers), $headers);
    }

    /**
     * @param array|string|null $body
     *
     * @return null|string
     */
    private function prepareBody($body)
    {
        if (!\is_array($body)) {
            return $body;
        }

        return empty($body) ? null : json_encode($body);
    }

    /**
     * Normalize JSON decoding and recover cases HttpClient misses.
     *
     * @param mixed $result
     *
     * @return array|string
     */
    private function decodeBody($result, array $headers)
    {
        if (\is_array($result)) {
            return $result;
        }

        if (\is_object($result)) {
            return json_decode(json_encode($result), true);
        }

        if (\is_string($result) && $this->isJsonContentType($headers)) {
            $decoded = json_decode($result, true);
            if (json_last_error() === \JSON_ERROR_NONE && \is_array($decoded)) {
                return $decoded;
            }
        }

        return \is_string($result) ? $result : (string) $result;
    }

    private function isJsonContentType(array $headers): bool
    {
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'content-type') === 0) {
                $value = \is_array($value) ? implode(';', $value) : (string) $value;

                return stripos($value, 'json') !== false;
            }
        }

        return false;
    }

    /**
     * @param mixed $headers
     */
    private function normalizeHeaders($headers): array
    {
        if (\is_array($headers)) {
            return $headers;
        }

        if ($headers instanceof Traversable) {
            return iterator_to_array($headers);
        }

        return [];
    }
}
