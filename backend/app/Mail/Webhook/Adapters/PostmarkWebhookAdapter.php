<?php

namespace BitApps\SMTP\Mail\Webhook\Adapters;

use BitApps\SMTP\Mail\Status\DeliveryStatus;
use BitApps\SMTP\Mail\Webhook\Contracts\WebhookAdapterInterface;
use BitApps\SMTP\Mail\Webhook\DeliveryEvent;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;

/**
 * Maps a Postmark webhook (one event object per request, keyed by RecordType) to a DeliveryEvent.
 */
class PostmarkWebhookAdapter implements WebhookAdapterInterface
{
    private const HARD_BOUNCE_TYPE = 'HardBounce';

    private const HARD_BOUNCE_CODE = 1;

    public function parseEvents(WebhookRequest $request): array
    {
        $payload = $request->decoded();

        switch ($payload['RecordType'] ?? '') {
            case 'Delivery':
                return [$this->deliveryEvent($payload)];
            case 'Bounce':
                return [$this->bounceEvent($payload)];
            case 'SpamComplaint':
                return [$this->spamEvent($payload)];
            default:
                return [];
        }
    }

    private function deliveryEvent(array $payload): DeliveryEvent
    {
        return DeliveryEvent::fromArray([
            'message_id'  => $this->messageId($payload),
            'tracking_id' => $this->trackingId($payload),
            'recipient'   => $payload['Recipient'] ?? '',
            'status'      => DeliveryStatus::DELIVERED,
            'terminal'    => true,
            'detail'      => null,
            'occurred_at' => $payload['DeliveredAt'] ?? null,
        ]);
    }

    private function bounceEvent(array $payload): DeliveryEvent
    {
        $type   = $payload['Type'] ?? ($payload['Name'] ?? null);
        $isHard = ($payload['Type'] ?? null)     === self::HARD_BOUNCE_TYPE
            || (int) ($payload['TypeCode'] ?? 0) === self::HARD_BOUNCE_CODE;

        return DeliveryEvent::fromArray([
            'message_id'  => $this->messageId($payload),
            'tracking_id' => $this->trackingId($payload),
            'recipient'   => $payload['Email'] ?? '',
            'status'      => DeliveryStatus::BOUNCED,
            'terminal'    => $isHard,
            'detail'      => $type,
            'occurred_at' => $payload['BouncedAt'] ?? null,
        ]);
    }

    private function spamEvent(array $payload): DeliveryEvent
    {
        return DeliveryEvent::fromArray([
            'message_id'  => $this->messageId($payload),
            'tracking_id' => $this->trackingId($payload),
            'recipient'   => $payload['Email'] ?? '',
            'status'      => DeliveryStatus::SPAM,
            'terminal'    => true,
            'detail'      => null,
            'occurred_at' => $payload['BouncedAt'] ?? null,
        ]);
    }

    private function messageId(array $payload): ?string
    {
        return isset($payload['MessageID']) ? (string) $payload['MessageID'] : null;
    }

    private function trackingId(array $payload): ?string
    {
        $metadata = $payload['Metadata'] ?? null;
        if (!\is_array($metadata) || !isset($metadata['bit_tracking_id'])) {
            return null;
        }

        return (string) $metadata['bit_tracking_id'];
    }
}
