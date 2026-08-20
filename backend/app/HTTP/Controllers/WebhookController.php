<?php

declare(strict_types=1);

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Webhook\DeliveryEventRecorder;
use BitApps\SMTP\Mail\Webhook\Signatures\WebhookSignatureVerifierFactory;
use BitApps\SMTP\Mail\Webhook\WebhookAdapterFactory;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;
use Throwable;

/**
 * Authenticates an inbound provider webhook by connection secret, records the correlated delivery
 * events, and marks the connection verified. Returns an HTTP status — never echoes, never exits — so
 * it is testable in isolation from the transport.
 */
final class WebhookController
{
    private MailConfigService $config;

    private WebhookAdapterFactory $factory;

    private WebhookSignatureVerifierFactory $verifierFactory;

    private DeliveryEventRecorder $recorder;

    public function __construct(
        ?MailConfigService $config = null,
        ?WebhookAdapterFactory $factory = null,
        ?DeliveryEventRecorder $recorder = null,
        ?WebhookSignatureVerifierFactory $verifierFactory = null
    ) {
        $this->config          = $config          ?? new MailConfigService();
        $this->factory         = $factory         ?? new WebhookAdapterFactory();
        $this->recorder        = $recorder        ?? new DeliveryEventRecorder(new LogService());
        $this->verifierFactory = $verifierFactory ?? new WebhookSignatureVerifierFactory();
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

        // Providers with no signed webhook resolve to null and skip the check; a signed provider
        // whose signature fails to validate is rejected as unauthenticated.
        $verifier = $this->verifierFactory->forProvider($connection->getProvider());
        if ($verifier !== null && !$verifier->verify($request, $connection)) {
            return 401;
        }

        $adapter = $this->factory->forProvider($connection->getProvider());
        if ($adapter === null) {
            return 404;
        }

        // The adapter contract says parseEvents never throws, but a malformed authenticated payload
        // can still raise a TypeError (an Error, not an Exception). Swallow it so we record nothing
        // and still return 200 rather than surfacing a 500 to the provider.
        try {
            $events = $adapter->parseEvents($request);
        } catch (Throwable $e) {
            $events = [];
        }

        $correlated = 0;
        $latest     = null;
        foreach ($events as $event) {
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
