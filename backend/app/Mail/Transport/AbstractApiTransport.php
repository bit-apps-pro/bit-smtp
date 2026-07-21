<?php

namespace BitApps\SMTP\Mail\Transport;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\AuthStrategyInterface;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\SendResult;
use BitApps\SMTP\Mail\Support\ApiRequest;
use BitApps\SMTP\Mail\Support\EncoderInterface;
use Throwable;

/**
 * Base for HTTP API transports: posts a provider-built body to a provider endpoint and maps
 * the response into a SendResult via provider-specific success/error rules. A subclass may opt
 * into the ApiBase path (useStrategy) to route via a pluggable encoder + auth strategy instead.
 */
abstract class AbstractApiTransport implements TransportInterface
{
    protected ApiClient $client;

    private ?AuthStrategyInterface $authStrategy = null;

    private ?EncoderInterface $encoder = null;

    public function __construct(ApiClient $client)
    {
        $this->client = $client;
    }

    public function send(MailMessage $message, Connection $connection): SendResult
    {
        if ($this->authStrategy !== null && $this->encoder !== null) {
            return $this->sendViaStrategy($message, $connection);
        }

        try {
            $response = $this->client
                ->setHeaders($this->authHeaders($connection) + ['Content-Type' => 'application/json'])
                ->post($this->endpoint($connection), $this->buildBody($message, $connection));
        } catch (Throwable $e) {
            return SendResult::failure($e->getMessage(), null, ['exception' => \get_class($e)]);
        }

        return $this->toSendResult($response);
    }

    /**
     * Opt a subclass into the ApiBase path: buildBody() feeds the encoder, the strategy signs last.
     */
    protected function useStrategy(AuthStrategyInterface $strategy, EncoderInterface $encoder): void
    {
        $this->authStrategy = $strategy;
        $this->encoder      = $encoder;
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
     * Whether the provider handed off / accepted the message (status-only, e.g. HTTP 2xx) even if
     * successFrom() went on to find a per-message error in the body. Drives dispatch fallback:
     * only a genuinely non-accepted response (this false) is eligible to fall back.
     *
     * @param array|string $body
     */
    abstract protected function acceptedFrom(int $status, $body): bool;

    /**
     * @param array|string $body
     */
    abstract protected function errorFrom(int $status, $body): string;

    protected function toSendResult(ApiResponse $response): SendResult
    {
        $status    = $response->getStatus();
        $body      = $response->getBody();
        $debug     = ['status' => $status, 'body' => $body];
        $messageId = $this->messageIdFrom($status, $body);

        if ($this->successFrom($status, $body)) {
            return SendResult::success($debug)->withMessageId($messageId);
        }

        if ($this->acceptedFrom($status, $body)) {
            return SendResult::acceptedWithError($this->errorFrom($status, $body), (string) $status, $debug)->withMessageId($messageId);
        }

        return SendResult::failure($this->errorFrom($status, $body), (string) $status, $debug);
    }

    /**
     * The provider's message-id from a hand-off response, for delivery-webhook correlation.
     * Default: none (only descriptor-backed transports that declare a messageIdPath return one).
     *
     * @param array|string $body
     */
    protected function messageIdFrom(int $status, $body): ?string
    {
        return null;
    }

    private function sendViaStrategy(MailMessage $message, Connection $connection): SendResult
    {
        try {
            $encoded = $this->encoder->encode($this->buildBody($message, $connection));
            $request = new ApiRequest('POST', $this->endpoint($connection), $encoded['body'], $encoded['contentType']);
            $this->authStrategy->apply($request, $connection);

            $response = $this->client->setHeaders($request->headers)->post($request->url, $request->body);
        } catch (Throwable $e) {
            return SendResult::failure($e->getMessage(), null, ['exception' => \get_class($e)]);
        }

        return $this->toSendResult($response);
    }
}
