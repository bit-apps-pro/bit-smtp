<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\Mailgun;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Providers\AbstractWebhookProvisioner;
use RuntimeException;

/**
 * Mailgun registers delivery webhooks per sending domain, form-encoded, one URL per event id (not a
 * single JSON call like other providers). The {domain} is user input spliced into the request path, so
 * it is validated against the same hostname guard the descriptor transport uses before interpolation.
 */
final class MailgunWebhookService extends AbstractWebhookProvisioner
{
    private const PROVIDER = 'mailgun';

    /**
     * RFC 1123 hostname (a-z/0-9/hyphen labels, 1..253 chars). Mirrors DescriptorApiTransport's SSRF
     * guard: a bad {domain} must be rejected, never interpolated into the request URL.
     */
    private const HOSTNAME_PATTERN = '/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*)(\.[a-z0-9](-?[a-z0-9])*)+$/iD';

    private const HOST_BY_REGION = ['us' => 'https://api.mailgun.net', 'eu' => 'https://api.eu.mailgun.net'];

    private const DEFAULT_REGION = 'us';

    /**
     * Least privilege: only the delivery-status events the Logs UI renders.
     */
    private const EVENTS = ['delivered', 'permanent_fail', 'temporary_fail'];

    /**
     * Register our delivery webhook for each tracked event on the connection's Mailgun domain, skipping
     * any event already pointing at our URL. Idempotent, so re-running only fills in what is missing.
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

    private function validatedDomain(Connection $connection): string
    {
        $domain = (string) $connection->setting('domain', '');
        if (preg_match(self::HOSTNAME_PATTERN, $domain) !== 1) {
            throw new RuntimeException(__('A valid Mailgun sending domain is required to register a delivery webhook.', 'bit-smtp'));
        }

        return $domain;
    }

    private function host(Connection $connection): string
    {
        $region = (string) $connection->setting('region', '') ?: self::DEFAULT_REGION;

        // Closed map: an unknown region is a hard error, never spliced into the host (SSRF guard).
        if (!isset(self::HOST_BY_REGION[$region])) {
            throw new RuntimeException(__('The configured Mailgun region is not supported for delivery webhooks.', 'bit-smtp'));
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
