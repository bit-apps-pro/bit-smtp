<?php

namespace BitApps\SMTP\Mail\Transport;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\AuthStrategyInterface;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Exceptions\OAuthException;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\MultipartBodyReader;
use BitApps\SMTP\Mail\Message\SendResult;
use BitApps\SMTP\Mail\Support\ApiRequest;
use BitApps\SMTP\Mail\Support\EncoderInterface;
use Throwable;

/**
 * Base for HTTP API transports; posts body to endpoint and maps response via provider-specific rules.
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
        if ($this->sendsRawMime()) {
            return $this->post($message, $connection);
        }

        $flattened = (new MultipartBodyReader())->flatten($message);
        $debug     = array_map(static fn (string $part): string => 'Multipart part not sent through the API: ' . $part, $flattened['dropped']);

        if ($flattened['message'] === null) {
            return SendResult::failure(
                __('This email is multipart but has no readable text or HTML part, so this provider\'s API cannot send it.', 'bit-smtp'),
                SendResult::CODE_UNSENDABLE_BODY,
                $debug
            );
        }

        return $this->post($flattened['message'], $connection)->withDebugLines($debug);
    }

    /**
     * Whether this transport posts a full MIME message, which can carry a multipart body as is; others get it flattened to one part.
     */
    protected function sendsRawMime(): bool
    {
        return false;
    }

    /**
     * Opt into ApiBase path: encoder processes buildBody(), strategy signs last.
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
     * Check if provider accepted message (HTTP 2xx) for fallback eligibility, regardless of successFrom() result.
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
     * Extract provider's message ID from response for delivery-webhook correlation; default none.
     *
     * @param array|string $body
     */
    protected function messageIdFrom(int $status, $body): ?string
    {
        return null;
    }

    /**
     * Post through the strategy path when configured, otherwise with plain auth headers.
     */
    private function post(MailMessage $message, Connection $connection): SendResult
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

    private function sendViaStrategy(MailMessage $message, Connection $connection): SendResult
    {
        try {
            $encoded = $this->encoder->encode($this->buildBody($message, $connection));
            $request = new ApiRequest('POST', $this->endpoint($connection), $encoded['body'], $encoded['contentType']);
            $this->authStrategy->apply($request, $connection);

            $response = $this->client->setHeaders($request->headers)->post($request->url, $request->body);
        } catch (OAuthException $e) {
            return SendResult::failure($e->getMessage(), $e->sendResultCode(), ['exception' => OAuthException::class]);
        } catch (Throwable $e) {
            return SendResult::failure($e->getMessage(), null, ['exception' => \get_class($e)]);
        }

        return $this->toSendResult($response);
    }
}
