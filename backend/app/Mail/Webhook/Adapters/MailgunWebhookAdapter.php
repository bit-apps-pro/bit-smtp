<?php

namespace BitApps\SMTP\Mail\Webhook\Adapters;

use BitApps\SMTP\Mail\Dispatch\TrackingIdStamper;
use BitApps\SMTP\Mail\Status\DeliveryStatus;
use BitApps\SMTP\Mail\Webhook\Contracts\WebhookAdapterInterface;
use BitApps\SMTP\Mail\Webhook\DeliveryEvent;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;

final class MailgunWebhookAdapter implements WebhookAdapterInterface
{
    public function parseEvents(WebhookRequest $request): array
    {
        $payload = $request->decoded()['event-data'] ?? $request->decoded();
        if (!\is_array($payload)) {
            return [];
        }

        $event = strtolower((string) ($payload['event'] ?? ''));
        $map   = [
            'accepted'       => [DeliveryStatus::ACCEPTED, false],
            'delivered'      => [DeliveryStatus::DELIVERED, true],
            'temporary_fail' => [DeliveryStatus::DEFERRED, false],
            'permanent_fail' => [DeliveryStatus::BOUNCED, true],
            'complained'     => [DeliveryStatus::SPAM, true],
        ];
        if (!isset($map[$event])) {
            return [];
        }

        $message   = $payload['message'] ?? [];
        $headers   = \is_array($message) && \is_array($message['headers'] ?? null) ? $message['headers'] : [];
        $variables = \is_array($payload['user-variables'] ?? null) ? $payload['user-variables'] : [];
        $recipient = $payload['recipient'] ?? ($payload['envelope']['targets'] ?? '');

        return [DeliveryEvent::fromArray([
            'message_id'  => $headers['message-id']                      ?? ($payload['id'] ?? null),
            'tracking_id' => $variables[TrackingIdStamper::METADATA_KEY] ?? null,
            'recipient'   => \is_array($recipient) ? implode(',', $recipient) : $recipient,
            'status'      => $map[$event][0],
            'terminal'    => $map[$event][1],
            'detail'      => $payload['delivery-status']['message'] ?? $event,
            'occurred_at' => $payload['timestamp']                  ?? null,
        ])];
    }
}
