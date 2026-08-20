<?php

namespace BitApps\SMTP\Mail\Status;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\MessageStatusCheckerInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use Throwable;

/**
 * Resolves a sent message's real outcome from Brevo's transactional event feed.
 */
final class BrevoStatusChecker implements MessageStatusCheckerInterface
{
    private const EVENTS_URL = 'https://api.brevo.com/v3/smtp/statistics/events';

    private const EVENT_STATES = [
        'blocked'    => DeliveryStatus::BLOCKED,
        'hardBounce' => DeliveryStatus::BOUNCED,
        'softBounce' => DeliveryStatus::BOUNCED,
        'spam'       => DeliveryStatus::SPAM,
        'complaint'  => DeliveryStatus::SPAM,
        'deferred'   => DeliveryStatus::DEFERRED,
        'delivered'  => DeliveryStatus::DELIVERED,
        'sent'       => DeliveryStatus::ACCEPTED,
        'request'    => DeliveryStatus::ACCEPTED,
    ];

    private ApiClient $client;

    public function __construct(ApiClient $client)
    {
        $this->client = $client;
    }

    public function check(string $messageId, Connection $connection): ?DeliveryStatus
    {
        try {
            $key = $connection->getCredentials()['api_key']['value'] ?? '';
            if ($key === '') {
                return null;
            }

            $response = $this->client
                ->setHeaders(['api-key' => $key])
                ->get(self::EVENTS_URL, ['messageId' => $messageId, 'limit' => 20]);

            if (!$response->isOk()) {
                return null;
            }

            return $this->reduceEvents($this->extractEvents($response->getBody()));
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @param array|string $body
     *
     * @return array<int,mixed>
     */
    private function extractEvents($body): array
    {
        if (!\is_array($body) || !isset($body['events']) || !\is_array($body['events'])) {
            return [];
        }

        return $body['events'];
    }

    /**
     * Collapses the event feed to the single worst outcome; empty means the send was accepted
     * but no downstream event has landed yet.
     *
     * @param array<int,mixed> $events
     */
    private function reduceEvents(array $events): DeliveryStatus
    {
        if ($events === []) {
            return new DeliveryStatus(DeliveryStatus::ACCEPTED, 'delivery status pending');
        }

        $winner = null;
        $detail = '';
        foreach ($events as $event) {
            $state = $this->stateFor($event);
            if ($state === null) {
                continue;
            }

            if ($winner === null || DeliveryStatus::severity($state) > DeliveryStatus::severity($winner)) {
                $winner = $state;
                $detail = $this->reasonFor($event);
            }
        }

        return $winner === null ? new DeliveryStatus(DeliveryStatus::UNKNOWN) : new DeliveryStatus($winner, $detail);
    }

    /**
     * @param mixed $event
     */
    private function stateFor($event): ?string
    {
        if (!\is_array($event) || !\is_string($event['event'] ?? null)) {
            return null;
        }

        return self::EVENT_STATES[$event['event']] ?? null;
    }

    /**
     * @param mixed $event
     */
    private function reasonFor($event): string
    {
        return \is_array($event) && \is_string($event['reason'] ?? null) ? $event['reason'] : '';
    }
}
