<?php

namespace BitApps\SMTP\Mail\Webhook\Adapters;

use BitApps\SMTP\Mail\Webhook\Contracts\WebhookAdapterInterface;
use BitApps\SMTP\Mail\Webhook\DeliveryEvent;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;

/**
 * Base for providers that POST either a single event object or a batch array of them, each keyed by a
 * top-level `event` field (SendGrid, Mailjet). Subclasses only map one decoded item to a DeliveryEvent.
 */
abstract class AbstractBatchedEventWebhookAdapter implements WebhookAdapterInterface
{
    use CastsNullableString;

    public function parseEvents(WebhookRequest $request): array
    {
        $payload = $request->decoded();
        $items   = isset($payload['event']) ? [$payload] : $payload;
        $events  = [];

        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $event = $this->eventFrom($item);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    abstract protected function eventFrom(array $payload): ?DeliveryEvent;
}
