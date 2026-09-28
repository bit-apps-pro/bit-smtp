<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\AuthStrategyInterface;
use BitApps\SMTP\Mail\Contracts\WebhookProvisionerInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Support\ApiRequest;
use BitApps\SMTP\Mail\Webhook\WebhookUrl;
use RuntimeException;

/**
 * Shared helpers for webhook provisioners; each provider implements ensure() due to API differences.
 */
abstract class AbstractWebhookProvisioner implements WebhookProvisionerInterface
{
    protected ApiClient $client;

    protected AuthStrategyInterface $auth;

    public function __construct(ApiClient $client, AuthStrategyInterface $auth)
    {
        $this->client = $client;
        $this->auth   = $auth;
    }

    abstract public function ensure(Connection $connection): array;

    abstract public function deregister(Connection $connection): void;

    /**
     * Best-effort delete of a webhook via list-find-delete, matching only this connection's URL.
     *
     * @param array<string,string> $listQuery query params for the list request (e.g. a stream filter)
     */
    protected function deregisterMatchedWebhook(
        Connection $connection,
        string $listUrl,
        array $listQuery,
        string $listKey,
        string $urlKey,
        string $idKey,
        string $deleteUrlPrefix,
        string $contentType = 'application/json'
    ): void {
        $url = $this->webhookUrl($connection);
        $this->applyAuth($connection, $contentType);

        $list = $this->client->get($listUrl, $listQuery);
        if (!$list->isOk()) {
            return;
        }

        $id = $this->matchWebhookId($list->getBody(), $listKey, $urlKey, $idKey, $url);
        if ($id === null) {
            return;
        }

        $this->client->delete($deleteUrlPrefix . rawurlencode($id));
    }

    /**
     * Ensure the provisioner's provider matches the connection.
     */
    protected function assertProvider(Connection $connection, string $expected): void
    {
        if ($connection->getProvider() !== $expected) {
            // translators: 1: provider slug, 2: provider slug
            throw new RuntimeException(esc_html(\sprintf(__('%1$s webhook creation requires a %2$s connection.', 'bit-smtp'), $expected, $expected)));
        }
    }

    /**
     * Return this connection's webhook URL (same-origin, HTTPS).
     */
    protected function webhookUrl(Connection $connection): string
    {
        return WebhookUrl::forConnection($connection);
    }

    /**
     * Configure client auth via ApiClient without resetting headers.
     */
    protected function applyAuth(Connection $connection, string $contentType): void
    {
        $request = new ApiRequest('GET', '', '', $contentType);
        $this->auth->apply($request, $connection);

        $this->client->setHeaders($request->headers);
    }

    /**
     * Throw a redacted API error if the response is not successful.
     */
    protected function assertOk(ApiResponse $response, string $webhookUrl): void
    {
        if (!$response->isOk()) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- apiError() returns a RuntimeException with an internally built, redacted message; the args are not output.
            throw $this->apiError($response, $webhookUrl);
        }
    }

    /**
     * Find the webhook ID matching the URL in a provider's list response, or null.
     *
     * @param mixed $body
     */
    protected function matchWebhookId($body, string $listKey, string $urlKey, string $idKey, string $url): ?string
    {
        if (!\is_array($body)) {
            return null;
        }

        $items = isset($body[$listKey]) && \is_array($body[$listKey]) ? $body[$listKey] : [];
        foreach ($items as $item) {
            if (\is_array($item) && ($item[$urlKey] ?? '') === $url && !empty($item[$idKey])) {
                return (string) $item[$idKey];
            }
        }

        return null;
    }

    /**
     * Require that webhook creation returned an ID; throw if missing.
     */
    protected function requireCreatedId(?string $id, string $providerLabel): string
    {
        if ($id === null || $id === '') {
            throw new RuntimeException(esc_html(\sprintf('%s created the webhook but returned no webhook ID.', $providerLabel)));
        }

        return $id;
    }

    /**
     * Build a RuntimeException with redacted webhook URL.
     */
    protected function apiError(ApiResponse $response, string $webhookUrl): RuntimeException
    {
        $message = 'Webhook request failed with HTTP ' . $response->getStatus();
        $detail  = $this->errorDetail($response->getBody());
        if ($detail !== '') {
            $message .= ': ' . str_replace($webhookUrl, '[redacted webhook URL]', $detail);
        }

        return new RuntimeException($message);
    }

    /**
     * Extract a human-readable error from varied provider error response shapes.
     *
     * @param array<mixed>|string $body
     */
    private function errorDetail($body): string
    {
        if (!\is_array($body)) {
            return '';
        }

        if (isset($body['errors'][0]['message']) && \is_string($body['errors'][0]['message'])) {
            return $body['errors'][0]['message'];
        }

        foreach (['message', 'Message', 'ErrorMessage', 'error'] as $key) {
            if (isset($body[$key]) && \is_string($body[$key])) {
                return $body[$key];
            }
        }

        return '';
    }
}
