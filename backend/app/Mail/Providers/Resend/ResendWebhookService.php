<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\Resend;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Providers\AbstractWebhookProvisioner;

final class ResendWebhookService extends AbstractWebhookProvisioner
{
    private const ENDPOINT = 'https://api.resend.com/webhooks';

    /**
     * Create the delivery webhook, or reuse an existing webhook with the same endpoint URL. The signing
     * secret is only ever returned by the API on creation, so a reused webhook carries no such key.
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

        // Live HMAC secret shown only at creation: surface it for encrypted storage upstream, but never
        // log it or fail on its absence — the webhook is registered either way.
        $signingSecret = \is_array($body) ? (string) ($body['signing_secret'] ?? '') : '';
        if ($signingSecret !== '') {
            $result['signing_secret'] = $signingSecret;
        }

        return $result;
    }
}
