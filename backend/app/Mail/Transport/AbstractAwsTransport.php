<?php

namespace BitApps\SMTP\Mail\Transport;

use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\SendResult;
use Throwable;

/**
 * Base for AWS SigV4-signed API transports. Overrides send() (rather than relying on
 * authHeaders()) because AWS signing must cover the exact body bytes that get posted: the body
 * is built first, then signed, then that same string is posted — never rebuilt in between.
 */
abstract class AbstractAwsTransport extends AbstractApiTransport
{
    private SigV4Signer $signer;

    public function __construct(ApiClient $client, SigV4Signer $signer)
    {
        parent::__construct($client);
        $this->signer = $signer;
    }

    public function send(MailMessage $message, Connection $connection): SendResult
    {
        try {
            $endpoint = $this->endpoint($connection);
            $body     = $this->buildBody($message, $connection);

            $baseHeaders = [
                'Host'                 => (string) parse_url($endpoint, \PHP_URL_HOST),
                'Content-Type'         => 'application/json',
                'X-Amz-Content-Sha256' => hash('sha256', $body),
            ];

            $signedHeaders = $this->signer->sign(
                'POST',
                $endpoint,
                $this->region($connection),
                $this->service(),
                $this->accessKey($connection),
                $this->secretKey($connection),
                $baseHeaders,
                $body
            );

            $response = $this->client->setHeaders($signedHeaders)->post($endpoint, $body);
        } catch (Throwable $e) {
            return SendResult::failure($e->getMessage(), null, ['exception' => \get_class($e)]);
        }

        return $this->toSendResult($response);
    }

    abstract protected function service(): string;

    abstract protected function region(Connection $connection): string;

    abstract protected function accessKey(Connection $connection): string;

    abstract protected function secretKey(Connection $connection): string;

    /**
     * Signing happens in send() itself; the parent contract still requires this method.
     */
    protected function authHeaders(Connection $connection): array
    {
        return [];
    }
}
