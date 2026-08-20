<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\Postmark;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Providers\AbstractWebhookProvisioner;

final class PostmarkWebhookService extends AbstractWebhookProvisioner
{
    private const ENDPOINT = 'https://api.postmarkapp.com/webhooks';

    /**
     * Create the outbound-stream delivery webhook, or reuse an existing one with the same endpoint URL.
     *
     * @return array{created: bool, id: string}
     */
    public function ensure(Connection $connection): array
    {
        $this->assertProvider($connection, 'postmark');

        $url = $this->webhookUrl($connection);
        $this->applyAuth($connection, 'application/json');

        $existing = $this->client->get(self::ENDPOINT, ['MessageStream' => 'outbound']);
        $this->assertOk($existing, $url);

        $existingId = $this->matchWebhookId($existing->getBody(), 'Webhooks', 'Url', 'ID', $url);
        if ($existingId !== null) {
            return ['created' => false, 'id' => $existingId];
        }

        $response = $this->client->post(self::ENDPOINT, [
            'Url'           => $url,
            'MessageStream' => 'outbound',
            'Triggers'      => [
                'Delivery'      => ['Enabled' => true],
                'Bounce'        => ['Enabled' => true, 'IncludeContent' => false],
                'SpamComplaint' => ['Enabled' => true, 'IncludeContent' => false],
            ],
        ]);
        $this->assertOk($response, $url);

        $body = $response->getBody();
        $id   = $this->requireCreatedId(
            \is_array($body) && !empty($body['ID']) ? (string) $body['ID'] : null,
            'Postmark'
        );

        return ['created' => true, 'id' => $id];
    }
}
