<?php

declare(strict_types=1);

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Aws\Sns\SnsMessage;
use BitApps\SMTP\Mail\Aws\Sns\SnsSubscriptionConfirmer;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Webhook\DeliveryEventRecorder;
use BitApps\SMTP\Mail\Webhook\Signatures\WebhookSignatureVerifierFactory;
use BitApps\SMTP\Mail\Webhook\WebhookAdapterFactory;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;
use Throwable;

/**
 * Verify webhook, record delivery events, mark connection verified; return HTTP status (testable).
 */
final class WebhookController
{
    /**
     * The only provider whose delivery events arrive over Amazon SNS rather than a provider HTTP webhook.
     */
    private const SNS_PROVIDER = 'amazon_ses';

    private MailConfigService $config;

    private WebhookAdapterFactory $factory;

    private WebhookSignatureVerifierFactory $verifierFactory;

    private DeliveryEventRecorder $recorder;

    private SnsSubscriptionConfirmer $snsConfirmer;

    public function __construct(
        ?MailConfigService $config = null,
        ?WebhookAdapterFactory $factory = null,
        ?DeliveryEventRecorder $recorder = null,
        ?WebhookSignatureVerifierFactory $verifierFactory = null,
        ?SnsSubscriptionConfirmer $snsConfirmer = null
    ) {
        $this->config          = $config          ?? new MailConfigService();
        $this->factory         = $factory         ?? new WebhookAdapterFactory();
        $this->recorder        = $recorder        ?? new DeliveryEventRecorder(new LogService());
        $this->verifierFactory = $verifierFactory ?? new WebhookSignatureVerifierFactory();
        $this->snsConfirmer    = $snsConfirmer    ?? new SnsSubscriptionConfirmer();
    }

    public function handle(string $connId, string $secret, WebhookRequest $request): int
    {
        $connection = $this->config->connectionById($connId);
        if ($connection === null || !$connection->isWebhookEnabled()) {
            return 404;
        }

        $stored = $connection->getWebhookSecret();
        if ($stored === '' || !hash_equals($stored, $secret)) {
            return 404;
        }

        $verifier = $this->verifierFactory->forProvider($connection->getProvider());
        if ($verifier !== null && !$verifier->verify($request, $connection)) {
            return 401;
        }

        if ($connection->getProvider() === self::SNS_PROVIDER) {
            $snsResult = $this->handleSnsControl(SnsMessage::fromArray($request->decoded()), $connection, $connId);
            if ($snsResult !== null) {
                return $snsResult;
            }
        }

        $adapter = $this->factory->forProvider($connection->getProvider());
        if ($adapter === null) {
            return 404;
        }

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

        return 200;
    }

    /**
     * Handle SNS control for SES (confirm subscription, validate account); null to continue parsing.
     */
    private function handleSnsControl(SnsMessage $sns, Connection $connection, string $connId): ?int
    {
        if ($sns->isSubscriptionConfirmation()) {
            if (!$this->snsConfirmer->confirm($sns)) {
                return 500;
            }

            $this->config->updateConnectionSettings($connId, ['webhook_sns_account_id' => $sns->topicAccountId()]);

            return 200;
        }

        $pinnedAccount = (string) $connection->setting('webhook_sns_account_id', '');
        if ($pinnedAccount !== '' && $sns->topicAccountId() !== $pinnedAccount) {
            return 403;
        }

        return null;
    }
}
