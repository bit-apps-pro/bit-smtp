<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Providers\SparkPost;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Providers\AbstractWebhookProvisioner;
use RuntimeException;

final class SparkPostWebhookService extends AbstractWebhookProvisioner
{
    /**
     * Region → API base, mirroring the transmissions transport's host map. A closed map: an unknown
     * region is a hard error, never spliced into the request URL (SSRF guard).
     */
    private const HOST_BY_REGION = [
        'us' => 'https://api.sparkpost.com',
        'eu' => 'https://api.eu.sparkpost.com',
    ];

    private const DEFAULT_REGION = 'us';

    /**
     * Create the delivery webhook, or reuse an existing webhook with the same target URL.
     *
     * @return array{created: bool, id: string}
     */
    public function ensure(Connection $connection): array
    {
        $this->assertProvider($connection, 'sparkpost');

        $url  = $this->webhookUrl($connection);
        $base = $this->endpoint($connection);
        $this->applyAuth($connection, 'application/json');

        $existing = $this->client->get($base);
        $this->assertOk($existing, $url);

        $existingId = $this->matchWebhookId($existing->getBody(), 'results', 'target', 'id', $url);
        if ($existingId !== null) {
            return ['created' => false, 'id' => $existingId];
        }

        $response = $this->client->post($base, [
            'name'   => $connection->getName() . ' Bit SMTP',
            'target' => $url,
            'events' => ['delivery', 'bounce', 'delay', 'policy_rejection', 'out_of_band'],
            'active' => true,
        ]);
        $this->assertOk($response, $url);

        $body = $response->getBody();
        $id   = $this->requireCreatedId(
            \is_array($body) && isset($body['results']['id']) ? (string) $body['results']['id'] : null,
            'SparkPost'
        );

        return ['created' => true, 'id' => $id];
    }

    public function deregister(Connection $connection): void
    {
        $this->assertProvider($connection, 'sparkpost');
        $base = $this->endpoint($connection);
        $this->deregisterMatchedWebhook($connection, $base, [], 'results', 'target', 'id', $base . '/');
    }

    private function endpoint(Connection $connection): string
    {
        $region = (string) $connection->setting('region', '');
        if ($region === '') {
            $region = self::DEFAULT_REGION;
        }

        if (!\array_key_exists($region, self::HOST_BY_REGION)) {
            throw new RuntimeException(esc_html('Unknown SparkPost region: ' . json_encode($region)));
        }

        return self::HOST_BY_REGION[$region] . '/api/v1/webhooks';
    }
}
