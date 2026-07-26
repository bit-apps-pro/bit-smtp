<?php

namespace BitApps\SMTP\Mail\Providers\SendGrid;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\WebhookProvisionerInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use RuntimeException;

final class SendGridWebhookService implements WebhookProvisionerInterface
{
    private const ENDPOINT = 'https://api.sendgrid.com/v3/user/webhooks/event/settings';

    private ApiClient $client;

    public function __construct(ApiClient $client)
    {
        $this->client = $client;
    }

    /**
     * Create the Event Webhook, or reuse an existing webhook with the same endpoint URL.
     *
     * @return array{created: bool, id: string, public_key: string}
     */
    public function ensure(Connection $connection): array
    {
        if ($connection->getProvider() !== 'sendgrid') {
            throw new RuntimeException('SendGrid webhook creation requires a SendGrid connection.');
        }

        $url    = $this->webhookUrl($connection);
        $apiKey = $connection->getCredentials()['api_key']['value'] ?? '';
        if ($apiKey === '') {
            throw new RuntimeException('SendGrid API key is required to create the webhook.');
        }

        $this->authenticate($apiKey);

        $existing = $this->client->get(self::ENDPOINT);
        if (!$existing->isOk()) {
            throw $this->apiError($existing, $url);
        }

        $existingId = $this->findWebhookId($existing->getBody(), $url);
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

            if (!$response->isOk()) {
                throw $this->apiError($response, $url);
            }

            $existingId = $this->findWebhookId($response->getBody(), $url);
            if ($existingId === null) {
                throw new RuntimeException('SendGrid created the webhook but returned no webhook ID.');
            }
            $created = true;
        }

        $signed = $this->client->patch(self::ENDPOINT . '/signed/' . rawurlencode($existingId), ['enabled' => true]);
        if (!$signed->isOk()) {
            throw $this->apiError($signed, $url);
        }
        $publicKey = \is_array($signed->getBody()) ? (string) ($signed->getBody()['public_key'] ?? '') : '';
        if ($publicKey === '') {
            throw new RuntimeException('SendGrid enabled webhook signing but returned no public key.');
        }

        return ['created' => $created, 'id' => $existingId, 'public_key' => $publicKey];
    }

    private function authenticate(string $apiKey): void
    {
        $this->client->setHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type'  => 'application/json',
        ]);
    }

    private function webhookUrl(Connection $connection): string
    {
        $secret = $connection->getWebhookSecret();
        $url    = home_url('/bit-smtp/' . $connection->getId() . '/' . $secret);
        if (strpos($url, 'https://') !== 0) {
            throw new RuntimeException('SendGrid requires a public HTTPS site URL before creating a webhook.');
        }

        return $url;
    }

    /**
     * @param array|string $body
     */
    private function findWebhookId($body, string $url): ?string
    {
        $items = \is_array($body) && isset($body['webhooks']) && \is_array($body['webhooks'])
            ? $body['webhooks']
            : (\is_array($body) ? $body : []);

        foreach ($items as $item) {
            if (\is_array($item) && ($item['url'] ?? '') === $url && !empty($item['id'])) {
                return (string) $item['id'];
            }
        }

        if (\is_array($body) && !empty($body['id']) && ($body['url'] ?? '') === $url) {
            return (string) $body['id'];
        }

        return null;
    }

    private function apiError(ApiResponse $response, string $url): RuntimeException
    {
        $message = 'SendGrid webhook request failed with HTTP ' . $response->getStatus();
        $body    = $response->getBody();
        if (\is_array($body) && isset($body['errors'][0]['message'])) {
            $message .= ': ' . str_replace($url, '[redacted webhook URL]', (string) $body['errors'][0]['message']);
        }

        return new RuntimeException($message);
    }
}
