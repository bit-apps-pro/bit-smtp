<?php

namespace BitApps\SMTP\Mail\Webhook\Contracts;

use BitApps\SMTP\Mail\Webhook\WebhookRequest;

interface WebhookAdapterInterface
{
    /**
     * Translate provider webhook to normalized delivery events; yield [] on unknown types or malformed input.
     *
     * @return \BitApps\SMTP\Mail\Webhook\DeliveryEvent[]
     */
    public function parseEvents(WebhookRequest $request): array;
}
