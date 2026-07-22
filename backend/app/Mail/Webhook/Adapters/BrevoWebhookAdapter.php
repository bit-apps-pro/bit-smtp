<?php

namespace BitApps\SMTP\Mail\Webhook\Adapters;

use BitApps\SMTP\Mail\Status\DeliveryStatus;
use BitApps\SMTP\Mail\Webhook\Contracts\WebhookAdapterInterface;
use BitApps\SMTP\Mail\Webhook\DeliveryEvent;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;

/**
 * Maps a Brevo webhook (one event per request, keyed by the snake_case `event`) to a DeliveryEvent.
 */
class BrevoWebhookAdapter implements WebhookAdapterInterface
{
    /**
     * @var array<string, array{status: string, terminal: bool}>
     */
    private const EVENT_MAP = [
        'delivered'   => ['status' => DeliveryStatus::DELIVERED, 'terminal' => true],
        'hard_bounce' => ['status' => DeliveryStatus::BOUNCED, 'terminal' => true],
        'soft_bounce' => ['status' => DeliveryStatus::BOUNCED, 'terminal' => false],
        'blocked'     => ['status' => DeliveryStatus::BLOCKED, 'terminal' => true],
        'spam'        => ['status' => DeliveryStatus::SPAM, 'terminal' => true],
        'deferred'    => ['status' => DeliveryStatus::DEFERRED, 'terminal' => false],
    ];

    public function parseEvents(WebhookRequest $request): array
    {
        $payload = $request->decoded();
        $event   = (string) ($payload['event'] ?? '');

        if (!isset(self::EVENT_MAP[$event])) {
            return [];
        }

        $mapping = self::EVENT_MAP[$event];

        return [DeliveryEvent::fromArray([
            'message_id'  => $this->stringOrNull($payload['message-id'] ?? null),
            'tracking_id' => $this->stringOrNull($payload['X-Mailin-custom'] ?? null),
            'recipient'   => $payload['email'] ?? '',
            'status'      => $mapping['status'],
            'terminal'    => $mapping['terminal'],
            'detail'      => $event,
            'occurred_at' => $this->occurredAt($payload),
        ])];
    }

    private function occurredAt(array $payload): ?string
    {
        if (isset($payload['date'])) {
            return (string) $payload['date'];
        }

        $ts = $payload['ts_event'] ?? ($payload['ts'] ?? null);
        if ($ts === null) {
            return null;
        }

        // A bare unix timestamp is not a parseable date string; render it to UTC first.
        if (\is_int($ts) || ctype_digit((string) $ts)) {
            return gmdate('Y-m-d H:i:s', (int) $ts);
        }

        return (string) $ts;
    }

    private function stringOrNull($value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
