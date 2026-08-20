<?php

namespace BitApps\SMTP\Mail\Providers\AmazonSes;

use BitApps\SMTP\Mail\Auth\AwsSigV4Strategy;
use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\MimeBuilder;
use BitApps\SMTP\Mail\Support\JsonEncoder;
use BitApps\SMTP\Mail\Transport\AbstractApiTransport;

class SesTransport extends AbstractApiTransport
{
    private MimeBuilder $mime;

    public function __construct(ApiClient $client, SigV4Signer $signer, MimeBuilder $mime)
    {
        parent::__construct($client);
        $this->mime = $mime;
        $this->useStrategy(new AwsSigV4Strategy($signer, 'ses'), new JsonEncoder());
    }

    protected function endpoint(Connection $connection): string
    {
        return \sprintf(
            'https://email.%s.amazonaws.com/v2/email/outbound-emails',
            (string) $connection->setting('region', '')
        );
    }

    /**
     * @return array
     */
    protected function buildBody(MailMessage $message, Connection $connection)
    {
        return [
            'Content' => [
                'Raw' => [
                    'Data' => base64_encode($this->mime->fromMailMessage($message)),
                ],
            ],
        ];
    }

    /**
     * Unused: SigV4 signing is handled by the AwsSigV4Strategy applied in the constructor.
     */
    protected function authHeaders(Connection $connection): array
    {
        return [];
    }

    /**
     * @param array|string $body
     */
    protected function successFrom(int $status, $body): bool
    {
        return $status === 200;
    }

    /**
     * SES has no 2xx-with-error-body case: accepted and successful are the same status check.
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
