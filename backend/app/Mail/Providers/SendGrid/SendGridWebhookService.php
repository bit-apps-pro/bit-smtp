<?php

namespace BitApps\SMTP\Mail\Providers\SendGrid;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Providers\AbstractWebhookProvisioner;
use RuntimeException;

final class SendGridWebhookService extends AbstractWebhookProvisioner
{
    private const ENDPOINT = 'https://api.sendgrid.com/v3/user/webhooks/event/settings';

    /**
     * Create the Event Webhook, or reuse an existing webhook with the same endpoint URL.
     *
     * @return array{created: bool, id: string, public_key: string}
     */
    public function ensure(Connection $connection): array
    {
        $this->assertProvider($connection, 'sendgrid');

        $url = $this->webhookUrl($connection);
        $this->applyAuth($connection, 'application/json');

        $existing = $this->client->get(self::ENDPOINT);
        $this->assertOk($existing, $url);

        $existingId = $this->matchWebhookId($existing->getBody(), 'webhooks', 'url', 'id', $url);
        $created    = false;
        if ($existingId === null) {
            $response = $this->client->post(self::ENDPOINT, [
                'enabled'       => true,
                'url'           => $url,
                'friendly_name' => $connection->getName() . ' Bit SMTP',
                'processed'     => true,
                'dropped'       => true,
                'deferred'      => true,
                'bounce'        => true,
                'delivered'     => true,
                'spam_report'   => true,
            ]);
            $this->assertOk($response, $url);

            $body       = $response->getBody();
            $existingId = $this->requireCreatedId(
                \is_array($body) && !empty($body['id']) ? (string) $body['id'] : null,
                'SendGrid'
            );
            $created = true;
        }

        $signed = $this->client->patch(self::ENDPOINT . '/signed/' . rawurlencode($existingId), ['enabled' => true]);
        $this->assertOk($signed, $url);

        $publicKey = \is_array($signed->getBody()) ? (string) ($signed->getBody()['public_key'] ?? '') : '';
        if ($publicKey === '') {
            throw new RuntimeException('SendGrid enabled webhook signing but returned no public key.');
        }

        return ['created' => $created, 'id' => $existingId, 'public_key' => $publicKey];
    }
}
