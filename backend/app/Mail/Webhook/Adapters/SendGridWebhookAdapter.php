<?php

namespace BitApps\SMTP\Mail\Webhook\Adapters;

use BitApps\SMTP\Mail\Dispatch\TrackingIdStamper;
use BitApps\SMTP\Mail\Status\DeliveryStatus;
use BitApps\SMTP\Mail\Webhook\DeliveryEvent;

/**
 * Maps SendGrid's batched Event Webhook payload to normalized delivery events.
 */
class SendGridWebhookAdapter extends AbstractBatchedEventWebhookAdapter
{
    /**
     * @var array<string, array{0: string, 1: bool}> event => [status, terminal]; 'bounce' is resolved separately
     */
    private const EVENT_MAP = [
        'processed'   => [DeliveryStatus::ACCEPTED, false],
        'delivered'   => [DeliveryStatus::DELIVERED, true],
        'deferred'    => [DeliveryStatus::DEFERRED, false],
        'dropped'     => [DeliveryStatus::BLOCKED, true],
        'spamreport'  => [DeliveryStatus::SPAM, true],
        'spam_report' => [DeliveryStatus::SPAM, true],
        'spam report' => [DeliveryStatus::SPAM, true],
    ];

    protected function eventFrom(array $payload): ?DeliveryEvent
    {
        $event = strtolower(trim((string) ($payload['event'] ?? '')));

        if ($event === 'bounce') {
            $status   = $this->isBlockedBounce($payload) ? DeliveryStatus::BLOCKED : DeliveryStatus::BOUNCED;
            $terminal = true;
        } elseif (isset(self::EVENT_MAP[$event])) {
            [$status, $terminal] = self::EVENT_MAP[$event];
        } else {
            return null;
        }

        return DeliveryEvent::fromArray([
            'message_id'  => $this->stringOrNull($payload['sg_message_id'] ?? null),
            'tracking_id' => $this->trackingId($payload),
            'recipient'   => $payload['email'] ?? '',
            'status'      => $status,
            'terminal'    => $terminal,
            'detail'      => $this->detail($payload, $event),
            'occurred_at' => $payload['timestamp'] ?? null,
        ]);
    }

    private function isBlockedBounce(array $payload): bool
    {
        $type = strtolower(trim((string) ($payload['type'] ?? '')));

        return $type === 'blocked' || $type === 'block';
    }

    private function trackingId(array $payload): ?string
    {
        if (isset($payload[TrackingIdStamper::METADATA_KEY])) {
            return (string) $payload[TrackingIdStamper::METADATA_KEY];
        }

        $customArgs = $payload['custom_args'] ?? null;
        if (!\is_array($customArgs) || !isset($customArgs[TrackingIdStamper::METADATA_KEY])) {
            return null;
        }

        return (string) $customArgs[TrackingIdStamper::METADATA_KEY];
    }

    private function detail(array $payload, string $fallback): string
    {
        foreach (['reason', 'response', 'type'] as $key) {
            if (isset($payload[$key]) && (string) $payload[$key] !== '') {
                return (string) $payload[$key];
            }
        }

        return $fallback;
    }
}
