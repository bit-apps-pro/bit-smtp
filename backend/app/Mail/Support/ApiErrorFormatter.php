<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Support;

/**
 * Extracts a human-readable error message from a mail provider's API response.
 */
final class ApiErrorFormatter
{
    /**
     * @param mixed    $body       decoded response body: a string (WP transport error) or an array (API error JSON)
     * @param string[] $errorPaths ordered dot-paths (e.g. "errors.0.message") to try against an array $body
     */
    public function extract($body, array $errorPaths, int $status, string $providerLabel = 'API'): string
    {
        if (\is_string($body)) {
            return trim($body) !== '' ? $body : "Network error (HTTP {$status})";
        }

        if (\is_array($body)) {
            foreach ($errorPaths as $path) {
                $message = $this->resolvePath($body, $path);

                if ($message !== null) {
                    return $message;
                }
            }
        }

        return "{$providerLabel} error HTTP {$status}";
    }

    /**
     * @return null|string the resolved message, or null when the path misses or resolves to an empty value
     */
    private function resolvePath(array $body, string $path): ?string
    {
        $node = $body;

        foreach (explode('.', $path) as $segment) {
            if (!\is_array($node) || !\array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        if (!\is_scalar($node)) {
            return null;
        }

        $node = (string) $node;

        return $node !== '' ? $node : null;
    }
}
