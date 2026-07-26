<?php

namespace BitApps\SMTP\Mail\Webhook\Adapters;

use BitApps\SMTP\Mail\Dispatch\TrackingIdStamper;
use BitApps\SMTP\Mail\Status\DeliveryStatus;
use BitApps\SMTP\Mail\Webhook\Contracts\WebhookAdapterInterface;
use BitApps\SMTP\Mail\Webhook\DeliveryEvent;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;

final class ResendWebhookAdapter implements WebhookAdapterInterface
{
    public function parseEvents(WebhookRequest $request): array
    {
        $payload = $request->decoded();
        $data    = \is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $type    = strtolower((string) ($payload['type'] ?? ''));
        $map     = [
            'email.sent'             => [DeliveryStatus::ACCEPTED, false],
            'email.delivered'        => [DeliveryStatus::DELIVERED, true],
            'email.delivery_delayed' => [DeliveryStatus::DEFERRED, false],
            'email.bounced'          => [DeliveryStatus::BOUNCED, true],
            'email.failed'           => [DeliveryStatus::BLOCKED, true],
            'email.complained'       => [DeliveryStatus::SPAM, true],
        ];
        if (!isset($map[$type])) {
            return [];
        }

        $to = $data['to'] ?? '';

        return [DeliveryEvent::fromArray([
            'message_id'  => $data['email_id']                              ?? null,
            'tracking_id' => $data['tags'][TrackingIdStamper::METADATA_KEY] ?? null,
            'recipient'   => \is_array($to) ? implode(',', $to) : $to,
            'status'      => $map[$type][0],
            'terminal'    => $map[$type][1],
            'detail'      => $data['status']        ?? $type,
            'occurred_at' => $payload['created_at'] ?? null,
        ])];
    }
}
