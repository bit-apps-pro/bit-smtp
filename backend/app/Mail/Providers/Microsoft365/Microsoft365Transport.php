<?php

namespace BitApps\SMTP\Mail\Providers\Microsoft365;

use BitApps\SMTP\Mail\Auth\OAuth2Strategy;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\OAuth2ProviderInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\MimeBuilder;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use BitApps\SMTP\Mail\Support\MimeRawEncoder;
use BitApps\SMTP\Mail\Transport\AbstractOAuth2Transport;

class Microsoft365Transport extends AbstractOAuth2Transport implements OAuth2ProviderInterface
{
    private const SEND_ENDPOINT = 'https://graph.microsoft.com/v1.0/me/sendMail';

    private MimeBuilder $mime;

    public function __construct(ApiClient $client, OAuth2TokenProvider $tokens, MimeBuilder $mime)
    {
        parent::__construct($client);
        $this->mime = $mime;
        $this->useStrategy(new OAuth2Strategy($tokens, $this), new MimeRawEncoder());
    }

    public function authUrl(Connection $connection): string
    {
        return $this->tenantBaseUrl($connection) . '/oauth2/v2.0/authorize';
    }

    public function tokenUrl(Connection $connection): string
    {
        return $this->tenantBaseUrl($connection) . '/oauth2/v2.0/token';
    }

    public function scopes(): array
    {
        return ['https://graph.microsoft.com/Mail.Send', 'offline_access'];
    }

    /**
     * @return array<string,string>
     */
    public function extraAuthParams(): array
    {
        return [];
    }

    protected function endpoint(Connection $connection): string
    {
        return self::SEND_ENDPOINT;
    }

    /**
     * Graph's text/plain sendMail wants standard base64 of the raw MIME (not Gmail's URL-safe base64url).
     *
     * @return array
     */
    protected function buildBody(MailMessage $message, Connection $connection)
    {
        return ['raw' => base64_encode($this->mime->fromMailMessage($message, $connection))];
    }

    /**
     * @param array|string $body
     */
    protected function successFrom(int $status, $body): bool
    {
        return $status === 202;
    }

    /**
     * Graph has no 2xx-with-error-body case: accepted and successful are the same status check.
     *
     * @param array|string $body
     */
    protected function acceptedFrom(int $status, $body): bool
    {
        return $status === 202;
    }

    /**
     * @param array|string $body
     */
    protected function errorFrom(int $status, $body): string
    {
        if (\is_array($body) && isset($body['error']['message'])) {
            return (string) $body['error']['message'];
        }

        // Graph rejects sends from a mailbox-less identity with a bodyless 401/403; spell out the
        // most common causes since the raw status alone reads as an opaque failure.
        if ($status === 401 || $status === 403) {
            return 'Microsoft rejected the send (HTTP ' . $status . '). The connected Microsoft account may not '
                . 'have a mailbox (no Exchange Online license), or the From address is not a valid send-as address for it.';
        }

        return 'Microsoft 365 error HTTP ' . $status;
    }

    /**
     * The tenant is an admin-set setting spliced into the OAuth URL path; rawurlencode blocks
     * `/ ? #` injection while leaving valid tenants (GUID, `*.onmicrosoft.com`, `common`) untouched.
     */
    private function tenantBaseUrl(Connection $connection): string
    {
        return 'https://login.microsoftonline.com/' . rawurlencode((string) $connection->setting('tenant', 'common'));
    }
}
