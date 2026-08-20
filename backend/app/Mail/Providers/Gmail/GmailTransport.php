<?php

namespace BitApps\SMTP\Mail\Providers\Gmail;

use BitApps\SMTP\Mail\Auth\OAuth2Strategy;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\MimeBuilder;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use BitApps\SMTP\Mail\Support\JsonEncoder;
use BitApps\SMTP\Mail\Transport\AbstractOAuth2Transport;

class GmailTransport extends AbstractOAuth2Transport
{
    private const SEND_ENDPOINT = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    private MimeBuilder $mime;

    public function __construct(ApiClient $client, OAuth2TokenProvider $tokens, MimeBuilder $mime)
    {
        parent::__construct($client);
        $this->mime = $mime;
        $this->useStrategy(new OAuth2Strategy($tokens, $this), new JsonEncoder());
    }

    public function authUrl(Connection $connection): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    public function tokenUrl(Connection $connection): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    public function scopes(): array
    {
        return ['https://www.googleapis.com/auth/gmail.send'];
    }

    /**
     * @return array<string,string>
     */
    public function extraAuthParams(): array
    {
        return ['access_type' => 'offline', 'prompt' => 'consent'];
    }

    protected function endpoint(Connection $connection): string
    {
        return self::SEND_ENDPOINT;
    }

    /**
     * @return array
     */
    protected function buildBody(MailMessage $message, Connection $connection)
    {
        return ['raw' => $this->base64url($this->mime->fromMailMessage($message, $connection))];
    }

    /**
     * @param array|string $body
     */
    protected function successFrom(int $status, $body): bool
    {
        return $status === 200;
    }

    /**
     * Gmail has no 2xx-with-error-body case: accepted and successful are the same status check.
     *
     * @param array|string $body
     */
    protected function acceptedFrom(int $status, $body): bool
    {
        return $status === 200;
    }

    /**
     * @param array|string $body
     */
    protected function messageIdFrom(int $status, $body): ?string
    {
        if ($status !== 200 || !\is_array($body) || !isset($body['id'])) {
            return null;
        }

        $messageId = trim((string) $body['id']);

        return $messageId !== '' ? $messageId : null;
    }

    /**
     * @param array|string $body
     */
    protected function errorFrom(int $status, $body): string
    {
        if (\is_array($body) && isset($body['error']['message'])) {
            return (string) $body['error']['message'];
        }

        return 'Gmail error HTTP ' . $status;
    }

    /**
     * Gmail's `raw` field expects unpadded, URL-safe base64 (RFC 4648 §5).
     */
    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
