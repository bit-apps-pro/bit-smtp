<?php

namespace BitApps\SMTP\Mail\Providers\AmazonSes;

use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\MimeBuilder;
use BitApps\SMTP\Mail\Transport\AbstractAwsTransport;
use InvalidArgumentException;

class SesTransport extends AbstractAwsTransport
{
    /**
     * AWS region shape, allowing GovCloud/ISO's extra segment (e.g. us-east-1, us-gov-east-1);
     * the region is interpolated into the signed request host, so anything outside this charset
     * must never reach endpoint(). The `D` modifier makes `$` match only the true end of string,
     * rejecting a trailing newline.
     */
    private const REGION_PATTERN = '/^[a-z]{2}(-[a-z]+)+-\d+$/D';

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
        $region = (string) ($connection->getSettings()['region'] ?? '');

        if (!preg_match(self::REGION_PATTERN, $region)) {
            throw new InvalidArgumentException('Invalid AWS SES region.');
        }

        return $region;
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
