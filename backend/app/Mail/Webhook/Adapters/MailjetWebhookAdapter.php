<?php

namespace BitApps\SMTP\Mail\Webhook\Adapters;

use BitApps\SMTP\Mail\Status\DeliveryStatus;
use BitApps\SMTP\Mail\Webhook\DeliveryEvent;

final class MailjetWebhookAdapter extends AbstractBatchedEventWebhookAdapter
{
    protected function eventFrom(array $payload): ?DeliveryEvent
    {
        $event = strtolower((string) ($payload['event'] ?? ''));
        $map   = [
            'sent'    => [DeliveryStatus::DELIVERED, true],
            'bounce'  => [DeliveryStatus::BOUNCED, (bool) ($payload['hard_bounce'] ?? false)],
            'blocked' => [DeliveryStatus::BLOCKED, true],
            'spam'    => [DeliveryStatus::SPAM, true],
        ];
        if (!isset($map[$event])) {
            return null;
        }

        return DeliveryEvent::fromArray([
            'message_id'  => isset($payload['MessageID']) ? (string) $payload['MessageID'] : null,
            'tracking_id' => $payload['CustomID']       ?? $payload['customcampaign'] ?? null,
            'recipient'   => $payload['email']          ?? '',
            'status'      => $map[$event][0],
            'terminal'    => $map[$event][1],
            'detail'      => $payload['comment'] ?? $payload['error'] ?? $payload['smtp_reply'] ?? $event,
            'occurred_at' => $payload['time']    ?? null,
        ]);
    }
}
