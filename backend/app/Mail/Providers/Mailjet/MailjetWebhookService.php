<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\Mailjet;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Providers\AbstractWebhookProvisioner;
use RuntimeException;

final class MailjetWebhookService extends AbstractWebhookProvisioner
{
    private const ENDPOINT = 'https://api.mailjet.com/v3/REST/eventcallbackurl';

    /**
     * Only delivery-relevant event types for Mailjet webhooks (least privilege).
     */
    private const EVENT_TYPES = ['sent', 'bounce', 'blocked', 'spam'];

    /**
     * Register our callback URL for each delivery event type, reusing any already pointing at it.
     *
     * @return array{created: bool, id: string}
     */
    public function ensure(Connection $connection): array
    {
        $this->assertProvider($connection, 'mailjet');

        $url = $this->webhookUrl($connection);
        $this->applyAuth($connection, 'application/json');

        $existing = $this->client->get(self::ENDPOINT);
        $this->assertOk($existing, $url);

        $primaries = $this->primaryCallbacksByEventType($existing->getBody());
        $this->assertNoForeignCallback($primaries, $url);

        $created   = false;
        $createdId = '';
        $reusedId  = '';
        foreach (self::EVENT_TYPES as $type) {
            if (isset($primaries[$type])) {
                $reusedId = $primaries[$type]['id'];

                continue;
            }

            $id = $this->create($type, $url);
            if (!$created) {
                $createdId = $id;
                $created   = true;
            }
        }

        if (!$created) {
            return ['created' => false, 'id' => $reusedId];
        }

        return ['created' => true, 'id' => $this->requireCreatedId($createdId, 'Mailjet')];
    }

    /**
     * Delete every webhook entry (primary or backup) pointing to this connection's URL.
     */
    public function deregister(Connection $connection): void
    {
        $this->assertProvider($connection, 'mailjet');

        $url = $this->webhookUrl($connection);
        $this->applyAuth($connection, 'application/json');

        $existing = $this->client->get(self::ENDPOINT);
        if (!$existing->isOk()) {
            return;
        }

        foreach ($this->callbacks($existing->getBody()) as $callback) {
            if ($callback['url'] === $url) {
                $this->client->delete(self::ENDPOINT . '/' . rawurlencode($callback['id']));
            }
        }
    }

    /**
     * Register a new callback for one event type and return its ID.
     */
    private function create(string $type, string $url): string
    {
        $response = $this->client->post(self::ENDPOINT, [
            'EventType' => $type,
            'Url'       => $url,
            'Version'   => 2,
            'Status'    => 'alive',
        ]);
        $this->assertOk($response, $url);

        $body = $response->getBody();

        return \is_array($body) && isset($body['Data'][0]['ID']) ? (string) $body['Data'][0]['ID'] : '';
    }

    /**
     * Refuse before any write when an event already goes elsewhere: Mailjet allows one address per event, and overwriting it would silently cut off that site or app.
     *
     * @param array<string,array{id: string, url: string}> $primaries
     */
    private function assertNoForeignCallback(array $primaries, string $url): void
    {
        foreach (self::EVENT_TYPES as $type) {
            $current = $primaries[$type] ?? null;
            if ($current === null || $current['url'] === $url) {
                continue;
            }

            $host = (string) wp_parse_url($current['url'], PHP_URL_HOST);

            // translators: 1: Mailjet event type, e.g. "bounce"; 2: host name the events currently go to
            throw new RuntimeException(esc_html(\sprintf(__('Mailjet already sends "%1$s" events for this account to %2$s, and allows only one address per event. Remove it in Mailjet (Account settings > Event notifications), or use a separate Mailjet sub-account for this site, then save again.', 'bit-smtp'), $type, $host !== '' ? $host : __('another address', 'bit-smtp'))));
        }
    }

    /**
     * Map EventType => {id, url} for the account's primary (non-backup) callbacks.
     *
     * @param array<mixed>|string $body
     *
     * @return array<string,array{id: string, url: string}>
     */
    private function primaryCallbacksByEventType($body): array
    {
        $map = [];
        foreach ($this->callbacks($body) as $callback) {
            if (!$callback['backup']) {
                $map[$callback['type']] = ['id' => $callback['id'], 'url' => $callback['url']];
            }
        }

        return $map;
    }

    /**
     * Normalize the callback list response into {type, id, url, backup} entries.
     *
     * @param array<mixed>|string $body
     *
     * @return list<array{type: string, id: string, url: string, backup: bool}>
     */
    private function callbacks($body): array
    {
        $entries = \is_array($body) && isset($body['Data']) && \is_array($body['Data']) ? $body['Data'] : [];

        $callbacks = [];
        foreach ($entries as $entry) {
            if (\is_array($entry) && isset($entry['EventType'], $entry['ID'])) {
                $callbacks[] = [
                    'type'   => (string) $entry['EventType'],
                    'id'     => (string) $entry['ID'],
                    'url'    => (string) ($entry['Url'] ?? ''),
                    'backup' => !empty($entry['IsBackup']),
                ];
            }
        }

        return $callbacks;
    }
}
