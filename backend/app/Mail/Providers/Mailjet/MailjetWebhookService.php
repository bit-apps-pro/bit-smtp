<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\Mailjet;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Providers\AbstractWebhookProvisioner;

final class MailjetWebhookService extends AbstractWebhookProvisioner
{
    private const ENDPOINT = 'https://api.mailjet.com/v3/REST/eventcallbackurl';

    /**
     * Mailjet registers one callback URL per event type, so we provision the delivery-relevant set
     * (least privilege) rather than every event Mailjet emits.
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
     * Map EventType => ID for the entries already pointing at our URL — the ones we can safely skip.
     * A different URL on the same EventType is left untouched (we only ever add ours).
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
