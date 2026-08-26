<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\Brevo;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Providers\AbstractWebhookProvisioner;

final class BrevoWebhookService extends AbstractWebhookProvisioner
{
    private const ENDPOINT = 'https://api.brevo.com/v3/webhooks';

    /**
     * Create the transactional delivery webhook, or reuse an existing one with the same endpoint URL.
     *
     * @return array{created: bool, id: string}
     */
    public function ensure(Connection $connection): array
    {
        $this->assertProvider($connection, 'brevo');

        $url = $this->webhookUrl($connection);
        $this->applyAuth($connection, 'application/json');

        $existing = $this->client->get(self::ENDPOINT, ['type' => 'transactional']);
        $this->assertOk($existing, $url);

        $existingId = $this->matchWebhookId($existing->getBody(), 'webhooks', 'url', 'id', $url);
        if ($existingId !== null) {
            return ['created' => false, 'id' => $existingId];
        }

        $response = $this->client->post(self::ENDPOINT, [
            'url'         => $url,
            'description' => $connection->getName() . ' Bit SMTP',
            'type'        => 'transactional',
            'events'      => ['delivered', 'hardBounce', 'softBounce', 'blocked', 'invalid', 'deferred'],
        ]);
        $this->assertOk($response, $url);

        $body = $response->getBody();
        $id   = $this->requireCreatedId(
            \is_array($body) && !empty($body['id']) ? (string) $body['id'] : null,
            'Brevo'
        );

        return ['created' => true, 'id' => $id];
    }

    public function deregister(Connection $connection): void
    {
        $this->assertProvider($connection, 'brevo');
        $this->deregisterMatchedWebhook($connection, self::ENDPOINT, ['type' => 'transactional'], 'webhooks', 'url', 'id', self::ENDPOINT . '/');
    }
}
