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
 * Shared helpers for provisioners that register a delivery webhook through a provider's API.
 * Concrete provisioners implement ensure() themselves — provider APIs differ too much for one
 * template (single list-then-create call vs. multi-request vs. form-encoded).
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
     * Best-effort deregister for the providers with a single REST webhook object per URL: list, find
     * the one whose target equals this connection's webhook URL, and DELETE it by that id. Only ever
     * removes a webhook matching our own URL, so a sibling connection's registration is untouched;
     * swallows every failure so a delete is never blocked.
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
     * Guard that this provisioner was handed its own provider's connection. Defensive: the factory
     * only ever pairs them, so this fires only for a direct, mismatched caller.
     */
    protected function assertProvider(Connection $connection, string $expected): void
    {
        if ($connection->getProvider() !== $expected) {
            // translators: 1: provider slug, 2: provider slug
            throw new RuntimeException(esc_html(\sprintf(__('%1$s webhook creation requires a %2$s connection.', 'bit-smtp'), $expected, $expected)));
        }
    }

    /**
     * The same-origin, https-only public URL the provider will POST events to (throws otherwise).
     */
    protected function webhookUrl(Connection $connection): string
    {
        return WebhookUrl::forConnection($connection);
    }

    /**
     * Sign the shared ApiClient using the provider's auth strategy. The request is a header-harvesting
     * stand-in — only its signed headers reach the client; the real HTTP verb comes from the get()/post()
     * calls that follow. ApiClient sends headers verbatim and never sets a content type, so it is pinned
     * to $contentType here (JSON for most, application/x-www-form-urlencoded for Mailgun). For a form
     * body, do NOT drive it through ApiClient::postForm() — that resets headers and would strip this auth;
     * POST the pre-encoded body via post() instead.
     */
    protected function applyAuth(Connection $connection, string $contentType): void
    {
        $request = new ApiRequest('GET', '', '', $contentType);
        $this->auth->apply($request, $connection);

        $this->client->setHeaders($request->headers);
    }

    /**
     * Throw the redacted API error unless the response is a success.
     */
    protected function assertOk(ApiResponse $response, string $webhookUrl): void
    {
        if (!$response->isOk()) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- apiError() returns a RuntimeException with an internally built, redacted message; the args are not output.
            throw $this->apiError($response, $webhookUrl);
        }
    }

    /**
     * Find the id of an already-registered webhook pointing at $url within a provider's list response,
     * or null when none matches. The list lives under $listKey; each entry's target URL under $urlKey and
     * its id under $idKey.
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
     * Return the id a create call reported, or throw when the provider registered the webhook but gave
     * back no id (a soft failure that must surface, not silently return an unusable result).
     */
    protected function requireCreatedId(?string $id, string $providerLabel): string
    {
        if ($id === null || $id === '') {
            throw new RuntimeException(esc_html(\sprintf('%s created the webhook but returned no webhook ID.', $providerLabel)));
        }

        return $id;
    }

    /**
     * A RuntimeException carrying the HTTP status, with the webhook URL (which embeds the
     * per-connection secret) redacted so it can never reach a log.
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
     * Pull a human-readable error string from the varied provider error shapes — SparkPost/SendGrid
     * `errors[0].message`, plus the flat `message`/`Message`/`ErrorMessage`/`error` the others use — or
     * '' when none is present, so a failure carries provider detail rather than a bare HTTP status.
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
