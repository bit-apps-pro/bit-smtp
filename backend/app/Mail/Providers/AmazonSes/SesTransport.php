<?php

namespace BitApps\SMTP\Mail\Providers\AmazonSes;

use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\MimeBuilder;
use BitApps\SMTP\Mail\Transport\AbstractAwsTransport;

class SesTransport extends AbstractAwsTransport
{
    private MimeBuilder $mime;

    public function __construct(ApiClient $client, SigV4Signer $signer, MimeBuilder $mime)
    {
        parent::__construct($client, $signer);
        $this->mime = $mime;
    }

    protected function service(): string
    {
        return 'ses';
    }

    protected function region(Connection $connection): string
    {
        return (string) ($connection->getSettings()['region'] ?? '');
    }

    protected function accessKey(Connection $connection): string
    {
        return (string) ($connection->getSettings()['access_key'] ?? '');
    }

    protected function secretKey(Connection $connection): string
    {
        return (string) ($connection->getCredentials()['secret_key']['value'] ?? '');
    }

    protected function endpoint(Connection $connection): string
    {
        return \sprintf('https://email.%s.amazonaws.com/v2/email/outbound-emails', $this->region($connection));
    }

    /**
     * @return string
     */
    protected function buildBody(MailMessage $message, Connection $connection)
    {
        return json_encode([
            'Content' => [
                'Raw' => [
                    'Data' => base64_encode($this->mime->fromMailMessage($message)),
                ],
            ],
        ]);
    }

    /**
     * @param array|string $body
     */
    protected function successFrom(int $status, $body): bool
    {
        return $status === 200;
    }

    /**
     * @param array|string $body
     */
    protected function errorFrom(int $status, $body): string
    {
        if (\is_array($body)) {
            if (isset($body['message'])) {
                return (string) $body['message'];
            }

            if (isset($body['Message'])) {
                return (string) $body['Message'];
            }
        }

        return 'SES error HTTP ' . $status;
    }
}
