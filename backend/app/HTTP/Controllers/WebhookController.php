<?php

declare(strict_types=1);

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Webhook\DeliveryEventRecorder;
use BitApps\SMTP\Mail\Webhook\WebhookAdapterFactory;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;

/**
 * Authenticates an inbound provider webhook by connection secret, records the correlated delivery
 * events, and marks the connection verified. Returns an HTTP status — never echoes, never exits — so
 * it is testable in isolation from the transport.
 */
final class WebhookController
{
    private MailConfigService $config;

    private WebhookAdapterFactory $factory;

    private DeliveryEventRecorder $recorder;

    public function __construct(
        ?MailConfigService $config = null,
        ?WebhookAdapterFactory $factory = null,
        ?DeliveryEventRecorder $recorder = null
    ) {
        $this->config   = $config   ?? new MailConfigService();
        $this->factory  = $factory  ?? new WebhookAdapterFactory();
        $this->recorder = $recorder ?? new DeliveryEventRecorder(new LogService());
    }

    public function handle(string $connId, string $secret, WebhookRequest $request): int
    {
        $connection = $this->config->connectionById($connId);
        if ($connection === null || !$connection->isWebhookEnabled()) {
            return 404;
        }

        // 404 (not 401) on a bad secret so a probe cannot distinguish a real connection from a fake
        // one; an empty stored secret must never authenticate, hash_equals keeps it constant-time.
        $stored = $connection->getWebhookSecret();
        if ($stored === '' || !hash_equals($stored, $secret)) {
            return 404;
        }

        $adapter = $this->factory->forProvider($connection->getProvider());
        if ($adapter === null) {
            return 404;
        }

        $correlated = 0;
        $latest     = null;
        foreach ($adapter->parseEvents($request) as $event) {
            if (!$this->recorder->record($event, $connection)) {
                continue;
            }

            $correlated++;
            $occurredAt = $event->occurredAt();
            if ($occurredAt !== null && ($latest === null || $occurredAt > $latest)) {
                $latest = $occurredAt;
            }
        }

        if ($correlated > 0) {
            $this->config->markWebhookVerified($connId, $latest);
        }

        // Always 200 on a well-formed, authenticated post — even with zero correlated events — so the
        // provider treats it as accepted and does not retry.
        return 200;
    }
}
