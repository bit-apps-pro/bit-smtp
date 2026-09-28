<?php

namespace BitApps\SMTP\Mail\Dispatch;

use BitApps\SMTP\Deps\BitApps\WPKit\Hooks\Hooks;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Connections\ConnectionResolver;
use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Exceptions\ProviderNotFoundException;
use BitApps\SMTP\Mail\Health\HealthRecorder;
use BitApps\SMTP\Mail\Health\HealthTransition;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\MailMessageFactory;
use BitApps\SMTP\Mail\Message\SendResult;
use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotifierInterface;
use BitApps\SMTP\Mail\Notifications\NotificationDispatchGuard;
use BitApps\SMTP\Mail\Providers\ProviderRegistry;
use BitApps\SMTP\Mail\Routing\MailSourceDetector;
use BitApps\SMTP\Mail\Routing\RoutingDecision;
use BitApps\SMTP\Mail\Routing\RoutingResolver;
use BitApps\SMTP\Mail\Status\DeliveryStatus;
use BitApps\SMTP\Mail\Tracking\TrackingBodyRewriter;
use BitApps\SMTP\Plugin;
use BitApps\SMTP\Settings\PluginSettings;
use InvalidArgumentException;
use WP_Error;

/**
 * Route wp_mail through connections in priority order via provider transports; log outcome and fire standard actions.
 */
class WpMailBridge
{
    private SendContext $context;

    private MailEventLogger $eventLogger;

    private RetryQueue $retryQueue;

    private MailDataBuilder $mailData;

    private FailureClassifier $classifier;

    private TrackingIdStamper $stamper;

    private TrackingBodyRewriter $trackingBodyRewriter;

    private ProviderRegistry $registry;

    private MailMessageFactory $messageFactory;

    private MailSourceDetector $sourceDetector;

    private ConnectionSendability $sendability;

    private RoutingPlanner $routingPlanner;

    private RoutingSkipTracer $routingSkipTracer;

    private bool $loggingEnabled = false;

    private bool $healthEnabled = false;

    private bool $trackingEnabled = false;

    private ?FailureNotifierInterface $failureNotifier = null;

    private ?HealthRecorder $healthRecorder = null;

    /**
     * True while our dispatch loop is running, so the native-path log listeners skip our own sends.
     */
    private bool $dispatching = false;

    public function __construct(
        ProviderRegistry $registry,
        ConnectionResolver $connectionResolver,
        MailMessageFactory $messageFactory,
        RoutingResolver $routingResolver,
        MailSourceDetector $sourceDetector,
        ?FailureNotifierInterface $failureNotifier = null,
        ?HealthRecorder $healthRecorder = null
    ) {
        $this->registry             = $registry;
        $this->messageFactory       = $messageFactory;
        $this->sourceDetector       = $sourceDetector;
        $this->failureNotifier      = $failureNotifier;
        $this->healthRecorder       = $healthRecorder;
        $this->context              = new SendContext();
        $this->eventLogger          = new MailEventLogger(Plugin::instance()->logger());
        $this->retryQueue           = new RetryQueue();
        $this->mailData             = new MailDataBuilder();
        $this->classifier           = new FailureClassifier();
        $this->sendability          = new ConnectionSendability($registry);
        $this->routingPlanner       = new RoutingPlanner($connectionResolver, $routingResolver, $sourceDetector, $this->sendability);
        $this->routingSkipTracer    = new RoutingSkipTracer($this->sendability);
        $preferences                = PluginSettings::make();
        $this->loggingEnabled       = Plugin::instance()->logger()->isEnabled();
        $this->healthEnabled        = (bool) $preferences->get('health_check_enabled', false);
        $this->trackingEnabled      = (bool) $preferences->get('tracking_enabled', false);
        $this->stamper              = new TrackingIdStamper();
        $this->trackingBodyRewriter = new TrackingBodyRewriter();

        Hooks::addFilter('pre_wp_mail', [$this, 'onPreWpMail'], 10, 2);

        Hooks::addAction('wp_mail_succeeded', [$this, 'onNativeMailSucceeded']);
        Hooks::addAction('wp_mail_failed', [$this, 'onNativeMailFailed']);
    }

    /**
     * Ensure any logs still buffered are persisted.
     */
    public function __destruct()
    {
        $this->eventLogger->flushPendingLogs();
    }

    public function setDebug(bool $debug): self
    {
        $this->context->setDebug($debug);

        return $this;
    }

    public function isFailed(): bool
    {
        return $this->context->isFailed();
    }

    /**
     * @return array<int,string>
     */
    public function getDebugOutput(): array
    {
        return $this->context->getDebugOutput();
    }

    public function retry(): self
    {
        $this->context->setRetrying(true);

        return $this;
    }

    public function setRetryLogId(int $logId): self
    {
        $this->context->setRetryLogId($logId);

        return $this;
    }

    /**
     * Mark send as manual resend of $logId, logged as child row linked to original.
     */
    public function setResendParentId(?int $logId): self
    {
        $this->context->setResendParentId($logId);

        return $this;
    }

    /**
     * Send edited manual resend over chosen connection, bypassing routing/failover; log as child of $resendParentId.
     *
     * @param array<string,mixed> $atts
     */
    public function dispatchResend(array $atts, Connection $connection, int $resendParentId): bool
    {
        $this->context->resetForSend();
        $this->context->setResendParentId($resendParentId);
        $this->context->setRoutingDecision(new RoutingDecision($this->sourceDetector->detect(), $connection->getId(), 'manual', null));

        $message = $this->messageFactory->fromWpMailAtts($atts);

        return $this->dispatch([$connection], $message, $this->mailData->buildFromAtts($atts, $message))['succeeded'];
    }

    /**
     * Whether a connection can attempt a send now, so callers can reject an unusable one before dispatch.
     */
    public function canSend(Connection $connection): bool
    {
        return $this->sendability->isSendable($connection);
    }

    public function setBatch(bool $status): self
    {
        $this->context->setBatch($status);

        return $this;
    }

    /**
     * pre_wp_mail handler: short-circuit with fallover dispatch; null defers to native wp_mail if disabled/no connection.
     *
     * @param null|bool           $return short-circuit value from an earlier pre_wp_mail listener
     * @param array<string,mixed> $atts   wp_mail() arguments: to, subject, message, headers, attachments
     *
     * @return null|bool null lets wp_mail() run natively; a bool short-circuits it
     */
    public function onPreWpMail($return, array $atts)
    {
        $resendParentId = null;
        if (!$this->dispatching && !NotificationDispatchGuard::isActive()) {
            $resendParentId = $this->context->getResendParentId();
            $this->context->setResendParentId(null);
        }

        if ($return !== null) {
            return $return;
        }

        if ($this->dispatching || NotificationDispatchGuard::isActive()) {
            return;
        }

        $this->context->resetForSend();

        $this->context->setNativeMailHeaders($atts['headers'] ?? '');

        $settings = Plugin::instance()->mailConfigService()->load();
        $this->routingPlanner->captureSourceForSend($settings, $this->context, $this->loggingEnabled);
        if (!$settings->isEnabled()) {
            return;
        }

        if ($this->routingPlanner->sendableConnections($settings, null) === []) {
            return;
        }

        try {
            $message = $this->messageFactory->fromWpMailAtts($atts);
        } catch (InvalidArgumentException $e) {
            return;
        }

        $routedConnectionId = $this->routingPlanner->routedConnectionId($message, $settings, $this->context);
        $connections        = $this->routingPlanner->sendableConnections($settings, $routedConnectionId);
        if ($connections === []) {
            return;
        }

        $this->context->setResendParentId($resendParentId);

        $routingChain = [];
        $routingSkips = [];
        if ($this->loggingEnabled) {
            $routingChain = $this->routingPlanner->priorityChainIds($settings, $routedConnectionId);
            $routingSkips = $this->routingSkipTracer->routingSkips($settings, $routingChain, $connections);
        }

        return $this->dispatch($connections, $message, $this->mailData->buildFromAtts($atts, $message), false, $routingSkips, $routingChain)['succeeded'];
    }

    /**
     * Re-dispatch previously-queued retry through failover loop over same connections.
     *
     * @param array<string,mixed> $mailData
     * @param string[]            $connectionIds
     *
     * @return array{succeeded: bool, failure_class: ?string}
     */
    public function dispatchRetry(MailMessage $message, array $mailData, array $connectionIds): array
    {
        $this->context->setRetrying(true);

        $connections = [];
        foreach ($connectionIds as $id) {
            $connection = Plugin::instance()->mailConfigService()->connectionById($id);
            if ($connection !== null) {
                $connections[] = $connection;
            }
        }

        return $this->dispatch($connections, $message, $mailData, true);
    }

    /**
     * wp_mail_succeeded listener for deferred native sends; our dispatch is skipped via $dispatching.
     *
     * @param array<string,mixed> $mailData
     */
    public function onNativeMailSucceeded($mailData): void
    {
        if ($this->dispatching || NotificationDispatchGuard::isActive()) {
            return;
        }

        if ($this->loggingEnabled) {
            $this->routingPlanner->captureNativeRoutingDecision($this->context);
            $this->eventLogger->logMailSuccess($this->enrichNativeMailData((array) $mailData), $this->context);
        }
        if ($this->failureNotifier !== null) {
            $this->failureNotifier->notifySuccess();
        }
    }

    /**
     * wp_mail_failed listener for sends we deferred to native wp_mail (see onNativeMailSucceeded).
     */
    public function onNativeMailFailed(WP_Error $error): void
    {
        if ($this->dispatching || NotificationDispatchGuard::isActive()) {
            return;
        }

        if ($this->loggingEnabled) {
            $this->routingPlanner->captureNativeRoutingDecision($this->context);
            $this->eventLogger->logMailFailed($this->enrichNativeError($error), $this->context);
        }
        if ($this->failureNotifier !== null) {
            $this->failureNotifier->notifyFailure($error);
        }
    }

    /**
     * Attempt each connection in order, stopping at the first success; injects tracking once before failover (no-op if tracking off), records health per-attempt without delaying failover, falls back only on non-accepted sends to prevent duplicates, and enqueues retryable failures (unless worker-driven re-dispatch).
     *
     * @param Connection[]        $connections
     * @param array<string,mixed> $mailData
     *
     * @return array{succeeded: bool, failure_class: ?string}
     */
    private function dispatch(array $connections, MailMessage $message, array $mailData, bool $isWorkerRedispatch = false, array $routingSkips = [], array $routingChain = []): array
    {
        $this->dispatching = true;

        try {
            $succeeded             = false;
            $lastResult            = null;
            $lastConnection        = null;
            $attempts              = [];
            $connectionIdsTried    = [];
            $healthTransitions     = [];
            $winningMessageId      = null;
            $winningTrackingId     = null;
            $winningDeliveryStatus = null;

            [$outgoing, $messageTrackingId] = $this->maybeInjectTracking($message);

            foreach ($connections as $connection) {
                $this->routingPlanner->advanceRoutingDecision($connection, $this->context);
                $provider             = $this->resolveProvider($connection);
                $tracking             = ($provider !== null && $connection->isWebhookEnabled()) ? $provider->tracking() : [];
                $trackingId           = $messageTrackingId ?? ($tracking !== [] ? $this->stamper->generate() : null);
                $lastResult           = $this->sendVia($provider, $connection, $outgoing, $tracking, $trackingId);
                $lastConnection       = $connection;
                $this->appendDebug($lastResult);
                $attempts[]           = $this->mailData->attemptEntry($connection, $lastResult);
                $connectionIdsTried[] = $connection->getId();

                $healthTransition = $this->recordHealth($connection, $lastResult);
                if ($healthTransition !== null) {
                    $healthTransitions[] = $healthTransition;
                }

                if ($lastResult->isAccepted()) {
                    $succeeded             = $lastResult->isOk();
                    $winningTrackingId     = $trackingId;
                    $winningMessageId      = $lastResult->getMessageId();
                    $winningDeliveryStatus = $this->resolveAcceptedDeliveryStatus($succeeded, $provider);

                    break;
                }

                if (FailureCategory::stopsFailover($this->classifier->classify($lastResult))) {
                    break;
                }
            }

            $this->context->setFailed(!$succeeded);

            $failureClass = $lastResult !== null && !$succeeded ? $this->classifier->classify($lastResult) : null;

            $winningMessage  = $lastConnection !== null ? $this->applyConnectionFrom($lastConnection, $outgoing) : $outgoing;
            $sender          = SenderFormatter::format($winningMessage->getFrom(), $winningMessage->getFromName());
            $aheadSkips      = $this->routingSkipTracer->skipsAheadOf($lastConnection, $routingSkips, $routingChain);
            $outcomeMailData = $this->mailData->withAttempts($mailData, $attempts);
            $outcomeMailData = $this->mailData->withSender($outcomeMailData, $sender);
            $outcomeMailData = $this->mailData->withRoutingSkips($outcomeMailData, $aheadSkips);
            $this->logOutcome($succeeded, $lastResult, $outcomeMailData, $lastConnection, $winningMessageId, $winningTrackingId, $winningDeliveryStatus, $failureClass);
            $this->notifyOutcome($succeeded, $lastResult, $outcomeMailData, $lastConnection);
            $this->flushHealth($healthTransitions);

            $this->fireWpMailAction($succeeded, $lastResult, $mailData);

            if (!$succeeded && !$isWorkerRedispatch && $failureClass !== null && FailureCategory::isRetryable($failureClass)) {
                $this->maybeEnqueueRetry($message, $mailData, $connectionIdsTried, $failureClass);
            }

            return ['succeeded' => $succeeded, 'failure_class' => $failureClass];
        } finally {
            $this->dispatching = false;
        }
    }

    /**
     * Build outgoing message with open/click tracking injected if enabled & HTML body; token doubles as log tracking_id.
     *
     * @return array{0: MailMessage, 1: ?string}
     */
    private function maybeInjectTracking(MailMessage $message): array
    {
        if (!$this->trackingEnabled || !$this->loggingEnabled || $message->getContentType() !== 'text/html') {
            return [$message, null];
        }

        $token = wp_generate_uuid4();

        return [$this->trackingBodyRewriter->rewrite($message, $token), $token];
    }

    /**
     * Enqueue a retryable failure onto the retry queue when the reliability preferences allow it; honors the admin's retry-class filter (empty = every retryable class). The queued row carries no log id (a fresh log row is written per retry attempt instead).
     *
     * @param array<string,mixed> $mailData
     * @param string[]            $connectionIds
     */
    private function maybeEnqueueRetry(MailMessage $message, array $mailData, array $connectionIds, string $failureClass): void
    {
        $settings = PluginSettings::make();
        if (!$settings->get('retry_enabled', false)) {
            return;
        }

        if (!FailureCategory::isRetryableWithin($failureClass, (array) $settings->get('retry_on_classes', []))) {
            return;
        }

        $maxAttempts = (int) $settings->get('retry_max_attempts', 3);
        $backoffMode = (string) $settings->get('retry_backoff', 'exponential');
        $firstDelay  = RetryWorker::computeDelay(1, $backoffMode);

        $this->retryQueue->enqueue($message, $mailData, $connectionIds, $failureClass, null, $maxAttempts, $firstDelay);
    }

    /**
     * Send via connection's provider transport; unregistered provider = logged attempt (BC, no fatal).
     */
    private function resolveProvider(Connection $connection): ?ProviderInterface
    {
        try {
            return $this->registry->get($connection->getProvider());
        } catch (ProviderNotFoundException $e) {
            return null;
        }
    }

    /**
     * Delivery status floored at non-terminal 'accepted' on successful hand-off (webhook later upgrades).
     */
    private function resolveAcceptedDeliveryStatus(bool $succeeded, ?ProviderInterface $provider): ?string
    {
        if (!$succeeded || $provider === null) {
            return null;
        }

        return DeliveryStatus::ACCEPTED;
    }

    /**
     * @param array{channel?: string, key?: string} $tracking
     */
    private function sendVia(?ProviderInterface $provider, Connection $connection, MailMessage $message, array $tracking, ?string $trackingId): SendResult
    {
        if ($provider === null) {
            return SendResult::failure(\sprintf('Provider "%s" is not registered.', $connection->getProvider()));
        }

        $prepared = $this->applyConnectionFrom($connection, $message);
        if ($trackingId !== null) {
            $prepared = $this->stamper->stamp($prepared, $tracking, $trackingId);
        }

        return $provider->transport()->send($prepared, $connection);
    }

    /**
     * Force connection's configured From on message to ensure all transports send from verified sender.
     */
    private function applyConnectionFrom(Connection $connection, MailMessage $message): MailMessage
    {
        $fromEmail = $connection->getFromEmail();
        if ($fromEmail === '') {
            return $message;
        }

        return MailMessage::fromArray(array_merge($message->toArray(), [
            'from'     => $fromEmail,
            'fromName' => $connection->getFromName(),
        ]));
    }

    private function appendDebug(SendResult $result): void
    {
        foreach ($result->getDebug() as $line) {
            $this->context->appendDebug($this->stringifyDebug($line) . "\n");
        }
    }

    /**
     * Normalize transport-shaped debug entries (SMTP strings, API scalars/arrays) to prevent PHP warnings.
     *
     * @param mixed $line
     */
    private function stringifyDebug($line): string
    {
        if (\is_string($line)) {
            return $line;
        }

        if (\is_scalar($line)) {
            return (string) $line;
        }

        return (string) json_encode($line);
    }

    /**
     * Logs the send outcome; stores the connection label for Logs UI display but correlates delivery webhooks on the stable connection id to prevent mis-attribution from renames or unnamed same-provider connections.
     *
     * @param array<string,mixed> $mailData
     */
    private function logOutcome(bool $succeeded, ?SendResult $result, array $mailData, ?Connection $connection, ?string $messageId = null, ?string $trackingId = null, ?string $deliveryStatus = null, ?string $failureClass = null): void
    {
        if (!$this->loggingEnabled) {
            return;
        }

        $connectionLabel = $connection !== null ? $connection->label() : null;
        $connectionId    = $connection !== null ? $connection->getId() : null;

        if ($succeeded) {
            $this->eventLogger->logMailSuccess($mailData, $this->context, $connectionLabel, $messageId, $trackingId, $connectionId, $deliveryStatus);

            return;
        }

        $this->eventLogger->logMailFailed($this->toError($result, $mailData), $this->context, $connectionLabel, $messageId, $trackingId, $connectionId, $failureClass);
    }

    /**
     * @param array<string,mixed> $mailData
     */
    private function notifyOutcome(bool $succeeded, ?SendResult $result, array $mailData, ?Connection $connection): void
    {
        if ($this->failureNotifier === null) {
            return;
        }

        if ($succeeded) {
            $this->failureNotifier->notifySuccess();

            return;
        }

        $this->failureNotifier->notifyFailure($this->toError($result, $mailData), $connection);
    }

    /**
     * Feed attempt outcome to health engine; return transition or null (disabled feature = zero work).
     */
    private function recordHealth(Connection $connection, SendResult $result): ?HealthTransition
    {
        if (!$this->healthEnabled || $this->healthRecorder === null) {
            return null;
        }

        return $this->healthRecorder->record($connection, $this->classifier->classify($result));
    }

    /**
     * Flush collected health transitions after failover loop to prevent latency between send attempts.
     *
     * @param HealthTransition[] $transitions
     */
    private function flushHealth(array $transitions): void
    {
        if ($this->healthEnabled && $this->healthRecorder !== null) {
            $this->healthRecorder->notify($transitions);
        }
    }

    /**
     * Fire standard WP action for final outcome so third-party listeners run on short-circuited send.
     *
     * @param array<string,mixed> $mailData
     */
    private function fireWpMailAction(bool $succeeded, ?SendResult $result, array $mailData): void
    {
        if ($succeeded) {
            do_action('wp_mail_succeeded', $mailData);

            return;
        }

        do_action('wp_mail_failed', $this->toError($result, $mailData));
    }

    /**
     * Build WP_Error for wp_mail_failed with mail data and PHPMailer exception code for failure inspection.
     *
     * @param array<string,mixed> $mailData
     */
    private function toError(?SendResult $result, array $mailData): WP_Error
    {
        $mailData['phpmailer_exception_code'] = $result !== null ? (int) $result->getCode() : 0;
        $errorMessage                         = $result !== null && $result->getError() !== null ? $result->getError() : '';

        return new WP_Error('wp_mail_failed', $errorMessage, $mailData);
    }

    /**
     * Enrich native wp_mail payload with From/Cc/Bcc recovered from raw headers; core strips these.
     *
     * @param array<string,mixed> $mailData wp_mail's $mail_data: to, subject, message, headers, attachments
     *
     * @return array<string,mixed>
     */
    private function enrichNativeMailData(array $mailData): array
    {
        $atts            = $mailData;
        $atts['headers'] = $this->context->getNativeMailHeaders() ?: ($mailData['headers'] ?? '');

        try {
            $message = $this->messageFactory->fromWpMailAtts($atts);
        } catch (InvalidArgumentException $e) {
            return $mailData;
        }

        $mailData['from'] = SenderFormatter::format($message->getFrom(), $message->getFromName());
        $mailData['cc']   = $message->getCc();
        $mailData['bcc']  = $message->getBcc();

        return $mailData;
    }

    /**
     * Rebuild wp_mail_failed error with enriched mail data; preserve codes for SMTP-failure inspection.
     */
    private function enrichNativeError(WP_Error $error): WP_Error
    {
        $data = $error->get_error_data();
        if (!\is_array($data)) {
            return $error;
        }

        $enriched = new WP_Error();
        foreach ($error->get_error_codes() as $code) {
            foreach ($error->get_error_messages($code) as $message) {
                $enriched->add($code, $message);
            }
        }
        $enriched->add_data($this->enrichNativeMailData($data), $error->get_error_code());

        return $enriched;
    }
}
