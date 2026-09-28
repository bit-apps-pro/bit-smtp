<?php

namespace BitApps\SMTP\Mail\Webhook\Adapters;

use BitApps\SMTP\Mail\Webhook\Contracts\WebhookAdapterInterface;
use BitApps\SMTP\Mail\Webhook\DeliveryEvent;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;

/**
 * Base for providers posting single event or batch array with top-level event field; subclass maps single item.
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
