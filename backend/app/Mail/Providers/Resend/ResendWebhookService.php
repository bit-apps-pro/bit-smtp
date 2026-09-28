<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\Resend;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Providers\AbstractWebhookProvisioner;

final class ResendWebhookService extends AbstractWebhookProvisioner
{
    private const ENDPOINT = 'https://api.resend.com/webhooks';

    /**
     * Create the delivery webhook, or reuse an existing one; HMAC secret surfaced for encrypted storage only on creation.
     *
     * @return array{created: bool, id: string, signing_secret?: string}
     */
    public function ensure(Connection $connection): array
    {
        $this->assertProvider($connection, 'resend');

        $url = $this->webhookUrl($connection);
        $this->applyAuth($connection, 'application/json');

        $existing = $this->client->get(self::ENDPOINT);
        $this->assertOk($existing, $url);

        $existingId = $this->matchWebhookId($existing->getBody(), 'data', 'endpoint', 'id', $url);
        if ($existingId !== null) {
            return ['created' => false, 'id' => $existingId];
        }

        $response = $this->client->post(self::ENDPOINT, [
            'endpoint' => $url,
            'events'   => ['email.delivered', 'email.bounced', 'email.delivery_delayed', 'email.failed'],
        ]);
        $this->assertOk($response, $url);

        $body = $response->getBody();
        $id   = $this->requireCreatedId(
            \is_array($body) && !empty($body['id']) ? (string) $body['id'] : null,
            'Resend'
        );

        $result = ['created' => true, 'id' => $id];

        $signingSecret = \is_array($body) ? (string) ($body['signing_secret'] ?? '') : '';
        if ($signingSecret !== '') {
            $result['signing_secret'] = $signingSecret;
        }

        return $result;
    }

    public function deregister(Connection $connection): void
    {
        $this->assertProvider($connection, 'resend');
        $this->deregisterMatchedWebhook($connection, self::ENDPOINT, [], 'data', 'endpoint', 'id', self::ENDPOINT . '/');
    }
}
