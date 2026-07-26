<?php

namespace BitApps\SMTP\Mail\Webhook\Adapters;

use BitApps\SMTP\Mail\Dispatch\TrackingIdStamper;
use BitApps\SMTP\Mail\Status\DeliveryStatus;
use BitApps\SMTP\Mail\Webhook\Contracts\WebhookAdapterInterface;
use BitApps\SMTP\Mail\Webhook\DeliveryEvent;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;

final class SparkPostWebhookAdapter implements WebhookAdapterInterface
{
    /**
     * @var array<string, array{0: string, 1: bool}> event type => [status, terminal]
     */
    private const EVENT_MAP = [
        'injection'            => [DeliveryStatus::ACCEPTED, false],
        'delivery'             => [DeliveryStatus::DELIVERED, true],
        'delay'                => [DeliveryStatus::DEFERRED, false],
        'bounce'               => [DeliveryStatus::BOUNCED, true],
        'out_of_band'          => [DeliveryStatus::BOUNCED, true],
        'policy_rejection'     => [DeliveryStatus::BLOCKED, true],
        'spam_complaint'       => [DeliveryStatus::SPAM, true],
        'generation_failure'   => [DeliveryStatus::BLOCKED, true],
        'generation_rejection' => [DeliveryStatus::BLOCKED, true],
    ];

    public function parseEvents(WebhookRequest $request): array
    {
        $events = [];
        foreach ($request->decoded() as $batch) {
            $messageEvent = $batch['msys']['message_event'] ?? null;
            $genEvent     = $batch['msys']['gen_event']     ?? null;
            $event        = \is_array($messageEvent) ? $messageEvent : (\is_array($genEvent) ? $genEvent : null);
            if ($event === null) {
                continue;
            }

            $type = strtolower((string) ($event['type'] ?? ''));
            if (!isset(self::EVENT_MAP[$type])) {
                continue;
            }

            $metadata = \is_array($event['rcpt_meta'] ?? null) ? $event['rcpt_meta'] : [];
            $events[] = DeliveryEvent::fromArray([
                'message_id'  => $event['transmission_id']                  ?? $event['message_id'] ?? null,
                'tracking_id' => $metadata[TrackingIdStamper::METADATA_KEY] ?? null,
                'recipient'   => $event['rcpt_to']                          ?? '',
                'status'      => self::EVENT_MAP[$type][0],
                'terminal'    => self::EVENT_MAP[$type][1],
                'detail'      => $event['reason']    ?? $event['description'] ?? $type,
                'occurred_at' => $event['timestamp'] ?? $event['injection_time'] ?? null,
            ]);
        }

        return $events;
    }
}
