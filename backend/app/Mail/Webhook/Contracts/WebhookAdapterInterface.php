<?php

namespace BitApps\SMTP\Mail\Webhook\Contracts;

use BitApps\SMTP\Mail\Webhook\WebhookRequest;

interface WebhookAdapterInterface
{
    /**
     * Translate a provider's inbound webhook into normalized delivery events. Unknown or
     * irrelevant record types yield []. Must never throw on malformed input (bad JSON → []).
     *
     * @return \BitApps\SMTP\Mail\Webhook\DeliveryEvent[]
     */
    public function parseEvents(WebhookRequest $request): array;
}
