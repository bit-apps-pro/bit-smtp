<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\Mailgun;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Providers\AbstractWebhookProvisioner;
use RuntimeException;

/**
 * Mailgun webhook service for per-domain form-encoded webhooks with hostname validation.
 */
final class MailgunWebhookService extends AbstractWebhookProvisioner
{
    private const PROVIDER = 'mailgun';

    /**
     * RFC 1123 hostname pattern with SSRF rejection guard.
     */
    private const HOSTNAME_PATTERN = '/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*)(\.[a-z0-9](-?[a-z0-9])*)+$/iD';

    private const HOST_BY_REGION = ['us' => 'https://api.mailgun.net', 'eu' => 'https://api.eu.mailgun.net'];

    private const DEFAULT_REGION = 'us';

    /**
     * Only the delivery-status events displayed in Logs UI (least privilege).
     */
    private const EVENTS = ['delivered', 'permanent_fail', 'temporary_fail'];

    /**
     * Register webhooks for all tracked events, skipping those already pointing at this URL.
     *
     * @return array{created: bool, id: string}
     */
    public function ensure(Connection $connection): array
    {
        $this->assertProvider($connection, self::PROVIDER);

        $url      = $this->webhookUrl($connection);
        $domain   = $this->validatedDomain($connection);
        $endpoint = $this->host($connection) . '/v3/domains/' . rawurlencode($domain) . '/webhooks';

        $this->applyAuth($connection, 'application/x-www-form-urlencoded');

        $existing = $this->client->get($endpoint);
        $this->assertOk($existing, $url);

        $registered = $this->registeredEventUrls($existing->getBody());

        $created = false;
        foreach (self::EVENTS as $event) {
            if (\in_array($url, $registered[$event], true)) {
                continue;
            }

            $response = $this->client->post($endpoint, http_build_query(['id' => $event, 'url' => $url]));
            $this->assertOk($response, $url);

            $created = true;
        }

        return ['created' => $created, 'id' => $domain];
    }

    /**
     * Delete webhook only if ours is the sole URL for that event; v3 DELETE removes all URLs for the event.
     */
    public function deregister(Connection $connection): void
    {
        $this->assertProvider($connection, self::PROVIDER);

        $url      = $this->webhookUrl($connection);
        $domain   = $this->validatedDomain($connection);
        $endpoint = $this->host($connection) . '/v3/domains/' . rawurlencode($domain) . '/webhooks';

        $this->applyAuth($connection, 'application/x-www-form-urlencoded');

        $existing = $this->client->get($endpoint);
        if (!$existing->isOk()) {
            return;
        }

        $registered = $this->registeredEventUrls($existing->getBody());
        foreach (self::EVENTS as $event) {
            if ($registered[$event] === [$url]) {
                $this->client->delete($endpoint . '/' . rawurlencode($event));
            }
        }
    }

    private function validatedDomain(Connection $connection): string
    {
        $domain = (string) $connection->setting('domain', '');
        if (preg_match(self::HOSTNAME_PATTERN, $domain) !== 1) {
            throw new RuntimeException(esc_html__('A valid Mailgun sending domain is required to register a delivery webhook.', 'bit-smtp'));
        }

        return $domain;
    }

    /**
     * Resolve the Mailgun API host for the connection's region; rejects unknown regions to prevent SSRF.
     */
    private function host(Connection $connection): string
    {
        $region = (string) $connection->setting('region', '') ?: self::DEFAULT_REGION;

        if (!isset(self::HOST_BY_REGION[$region])) {
            throw new RuntimeException(esc_html__('The configured Mailgun region is not supported for delivery webhooks.', 'bit-smtp'));
        }

        return self::HOST_BY_REGION[$region];
    }

    /**
     * @param array<mixed>|string $body
     *
     * @return array<string, string[]> tracked event id => URLs Mailgun already posts that event to
     */
    private function registeredEventUrls($body): array
    {
        $webhooks = \is_array($body) && isset($body['webhooks']) && \is_array($body['webhooks']) ? $body['webhooks'] : [];

        $map = [];
        foreach (self::EVENTS as $event) {
            $entry       = isset($webhooks[$event]) && \is_array($webhooks[$event]) ? $webhooks[$event] : [];
            $urls        = isset($entry['urls'])    && \is_array($entry['urls']) ? $entry['urls'] : [];
            $map[$event] = array_map('strval', $urls);
        }

        return $map;
    }
}
