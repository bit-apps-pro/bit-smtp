<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\Mailjet;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Providers\AbstractWebhookProvisioner;

final class MailjetWebhookService extends AbstractWebhookProvisioner
{
    private const ENDPOINT = 'https://api.mailjet.com/v3/REST/eventcallbackurl';

    /**
     * Only delivery-relevant event types for Mailjet webhooks (least privilege).
     */
    private const EVENT_TYPES = ['sent', 'bounce', 'blocked', 'spam'];

    /**
     * Register our callback URL for each delivery event type, skipping any already pointing at it.
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

        $registered = $this->registeredEventTypes($existing->getBody(), $url);

        $created   = false;
        $createdId = '';
        $reusedId  = '';
        foreach (self::EVENT_TYPES as $type) {
            if (isset($registered[$type])) {
                $reusedId = $registered[$type];

                continue;
            }

            $response = $this->client->post(self::ENDPOINT, [
                'EventType' => $type,
                'Url'       => $url,
                'Version'   => 2,
                'Status'    => 'alive',
            ]);
            $this->assertOk($response, $url);

            if (!$created) {
                $body      = $response->getBody();
                $createdId = \is_array($body) && isset($body['Data'][0]['ID']) ? (string) $body['Data'][0]['ID'] : '';
                $created   = true;
            }
        }

        if (!$created) {
            return ['created' => false, 'id' => $reusedId];
        }

        return ['created' => true, 'id' => $this->requireCreatedId($createdId, 'Mailjet')];
    }

    /**
     * Delete only webhook entries pointing to this connection's URL (one per event type).
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

        foreach ($this->registeredEventTypes($existing->getBody(), $url) as $id) {
            $this->client->delete(self::ENDPOINT . '/' . rawurlencode($id));
        }
    }

    /**
     * Map EventType => ID for entries already pointing at this URL (safe to skip).
     *
     * @param array<mixed>|string $body
     *
     * @return array<string,string>
     */
    private function registeredEventTypes($body, string $url): array
    {
        $entries = \is_array($body) && isset($body['Data']) && \is_array($body['Data']) ? $body['Data'] : [];

        $map = [];
        foreach ($entries as $entry) {
            if (\is_array($entry) && ($entry['Url'] ?? '') === $url && isset($entry['EventType'], $entry['ID'])) {
                $map[(string) $entry['EventType']] = (string) $entry['ID'];
            }
        }

        return $map;
    }
}
