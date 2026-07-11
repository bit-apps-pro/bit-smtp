<?php

namespace BitApps\SMTP\Mail\Transport;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\SendResult;
use Throwable;

/**
 * Base for HTTP API transports: posts a provider-built body to a provider endpoint and maps
 * the response into a SendResult via provider-specific success/error rules.
 */
abstract class AbstractApiTransport implements TransportInterface
{
    protected ApiClient $client;

    public function __construct(ApiClient $client)
    {
        $this->client = $client;
    }

    public function send(MailMessage $message, Connection $connection): SendResult
    {
        try {
            $response = $this->client
                ->setHeaders($this->authHeaders($connection) + ['Content-Type' => 'application/json'])
                ->post($this->endpoint($connection), $this->buildBody($message, $connection));
        } catch (Throwable $e) {
            return SendResult::failure($e->getMessage(), null, ['exception' => \get_class($e)]);
        }

        return $this->toSendResult($response);
    }

    abstract protected function endpoint(Connection $connection): string;

    /**
     * @return array|string
     */
    abstract protected function buildBody(MailMessage $message, Connection $connection);

    abstract protected function authHeaders(Connection $connection): array;

    /**
     * @param array|string $body
     */
    abstract protected function successFrom(int $status, $body): bool;

    /**
     * @param array|string $body
     */
    abstract protected function errorFrom(int $status, $body): string;

    protected function toSendResult(ApiResponse $response): SendResult
    {
        $status = $response->getStatus();
        $body   = $response->getBody();
        $debug  = ['status' => $status, 'body' => $body];

        if ($this->successFrom($status, $body)) {
            return SendResult::success($debug);
        }

        return SendResult::failure($this->errorFrom($status, $body), (string) $status, $debug);
    }
}
