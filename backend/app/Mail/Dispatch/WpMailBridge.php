<?php

namespace BitApps\SMTP\Mail\Dispatch;

use BitApps\SMTP\Deps\BitApps\WPKit\Hooks\Hooks;
use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Connections\ConnectionResolver;
use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Exceptions\ProviderNotFoundException;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\MailMessageFactory;
use BitApps\SMTP\Mail\Message\SendResult;
use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotifierInterface;
use BitApps\SMTP\Mail\Notifications\NotificationDispatchGuard;
use BitApps\SMTP\Mail\Providers\ProviderRegistry;
use BitApps\SMTP\Mail\Routing\MailSourceDetector;
use BitApps\SMTP\Mail\Routing\RoutingContext;
use BitApps\SMTP\Mail\Routing\RoutingDecision;
use BitApps\SMTP\Mail\Routing\RoutingResolver;
use BitApps\SMTP\Mail\Routing\RoutingRules;
use BitApps\SMTP\Mail\Status\DeliveryStatus;
use BitApps\SMTP\Mail\Webhook\WebhookAdapterFactory;
use BitApps\SMTP\Plugin;
use InvalidArgumentException;
use WP_Error;

/**
 * Drives wp_mail through our connection dispatch: on `pre_wp_mail` it sends the message over each
 * resolved connection in priority order — each through its OWN provider transport — falling back to
 * the next on failure, then logs the outcome and re-fires the standard wp_mail_succeeded/failed
 * actions for third-party listeners. Owns the single mutable SendContext the controller reads back
 * after wp_mail() for test-mail/resend.
 */
class WpMailBridge
{
    private SendContext $context;

    private MailEventLogger $eventLogger;

    private TrackingIdStamper $stamper;

    private ProviderRegistry $registry;

    private ConnectionResolver $connectionResolver;

    private MailMessageFactory $messageFactory;

    private RoutingResolver $routingResolver;

    private MailSourceDetector $sourceDetector;

    private bool $loggingEnabled = false;

    private ?FailureNotifierInterface $failureNotifier = null;

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
        ?FailureNotifierInterface $failureNotifier = null
    ) {
        $this->registry           = $registry;
        $this->connectionResolver = $connectionResolver;
        $this->messageFactory     = $messageFactory;
        $this->routingResolver    = $routingResolver;
        $this->sourceDetector     = $sourceDetector;
        $this->failureNotifier    = $failureNotifier;
        $this->context            = new SendContext();
        $this->eventLogger        = new MailEventLogger(Plugin::instance()->logger());
        $this->loggingEnabled     = Plugin::instance()->logger()->isEnabled();
        $this->stamper            = new TrackingIdStamper();

        Hooks::addFilter('pre_wp_mail', [$this, 'onPreWpMail'], 10, 2);

        // Sends deferred to native wp_mail still need outcome logging and failure notifications.
        // Our own dispatch and nested notification email are skipped via $dispatching.
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

    public function setBatch(bool $status): self
    {
        $this->context->setBatch($status);

        return $this;
    }

    /**
     * pre_wp_mail handler: short-circuits wp_mail() with a fallback dispatch over the ordered
     * connections. Returns null to defer to native wp_mail (a prior listener already handled the
     * send, the plugin is disabled, or there is no usable connection to send with).
     *
     * @param null|bool           $return short-circuit value from an earlier pre_wp_mail listener
     * @param array<string,mixed> $atts   wp_mail() arguments: to, subject, message, headers, attachments
     *
     * @return null|bool null lets wp_mail() run natively; a bool short-circuits it
     */
    public function onPreWpMail($return, array $atts)
    {
        if ($return !== null) {
            return $return;
        }

        if ($this->dispatching || NotificationDispatchGuard::isActive()) {
            return;
        }

        // Clear stale per-send output up front so a deferred (native) send's read-back is not
        // polluted by a prior dispatch; the native log listeners record that send's outcome.
        $this->context->resetForSend();

        $settings = Plugin::instance()->mailConfigService()->load();
        $this->captureSourceForSend($settings);
        if (!$settings->isEnabled()) {
            return;
        }

        // Check eligibility before building the message, so the wp_mail_* value filters aren't
        // applied here and then again by native wp_mail when we defer with no usable connection.
        // Routing only reorders the eligible set, so it cannot change emptiness.
        if ($this->sendableConnections($settings, null) === []) {
            return;
        }

        try {
            $message = $this->messageFactory->fromWpMailAtts($atts);
        } catch (InvalidArgumentException $e) {
            // Degenerate input (e.g. no valid recipients): defer to core rather than fatal the request.
            return;
        }

        $connections = $this->sendableConnections($settings, $this->routedConnectionId($message, $settings));
        if ($connections === []) {
            return;
        }

        return $this->dispatch($connections, $message, $this->buildMailData($atts));
    }

    /**
     * wp_mail_succeeded listener for sends we deferred to native wp_mail; our own dispatch is logged
     * directly and skipped here via $dispatching.
     *
     * @param array<string,mixed> $mailData
     */
    public function onNativeMailSucceeded($mailData): void
    {
        if ($this->dispatching || NotificationDispatchGuard::isActive()) {
            return;
        }

        if ($this->loggingEnabled) {
            $this->captureNativeRoutingDecision();
            $this->eventLogger->logMailSuccess((array) $mailData, $this->context);
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
            $this->captureNativeRoutingDecision();
            $this->eventLogger->logMailFailed($error, $this->context);
        }
        if ($this->failureNotifier !== null) {
            $this->failureNotifier->notifyFailure($error);
        }
    }

    /**
     * Attempt each connection in order, stopping at the first success.
     *
     * @param Connection[]        $connections
     * @param array<string,mixed> $mailData
     */
    private function dispatch(array $connections, MailMessage $message, array $mailData): bool
    {
        $this->dispatching = true;

        try {
            $succeeded             = false;
            $lastResult            = null;
            $lastConnection        = null;
            $attempts              = [];
            $winningMessageId      = null;
            $winningTrackingId     = null;
            $winningDeliveryStatus = null;

            foreach ($connections as $connection) {
                $this->advanceRoutingDecision($connection);
                $provider       = $this->resolveProvider($connection);
                $tracking       = ($provider !== null && $connection->isWebhookEnabled()) ? $provider->tracking() : [];
                $trackingId     = $tracking !== [] ? $this->stamper->generate() : null;
                $lastResult     = $this->sendVia($provider, $connection, $message, $tracking, $trackingId);
                $lastConnection = $connection;
                $this->appendDebug($lastResult);
                $attempts[]     = $this->attemptEntry($connection, $lastResult);

                // Fall back only when NOT accepted: an accepted-but-partial send (e.g. a 2xx with a
                // per-message error) was already handed off, so retrying via the next connection
                // would duplicate-deliver to the recipients the first provider already accepted.
                if ($lastResult->isAccepted()) {
                    $succeeded = $lastResult->isOk();
                    // Retain the provider's accepted-message id even when asynchronous delivery
                    // tracking is unavailable. Delivery visibility is gated independently by a
                    // verified webhook or an authoritative status stamped at hand-off.
                    $winningTrackingId = $trackingId;
                    $winningMessageId  = $lastResult->getMessageId();
                    // A fully-ok send stamps its delivery status straight from the hand-off (an
                    // accepted-but-partial send is a failure row and stays unstamped).
                    $winningDeliveryStatus = $this->resolveAcceptedDeliveryStatus($succeeded, $provider, $connection);

                    break;
                }
            }

            $this->context->setFailed(!$succeeded);

            // One log row per message: the final outcome on the winning (or last-tried) connection,
            // carrying the whole attempt trail so the fallback chain (failed -> failed -> sent) is
            // visible in the log detail rather than split across a row per attempt.
            $outcomeMailData = $this->withAttempts($mailData, $attempts);
            $this->logOutcome($succeeded, $lastResult, $outcomeMailData, $lastConnection, $winningMessageId, $winningTrackingId, $winningDeliveryStatus);
            $this->notifyOutcome($succeeded, $lastResult, $outcomeMailData, $lastConnection);

            $this->fireWpMailAction($succeeded, $lastResult, $mailData);

            return $succeeded;
        } finally {
            $this->dispatching = false;
        }
    }

    /**
     * Send over the connection's OWN provider transport, resolved from the registry by provider key.
     * A connection whose provider is not registered is treated as a failed attempt — logged, then the
     * fallback loop continues — rather than fataling the request (BC for unavailable providers).
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
     * Delivery status to stamp on a successful transport hand-off. A hand-off is not recipient
     * delivery: only an authenticated, correlated webhook may write terminal delivery values. Keep
     * webhook-backed connections unstamped pending their callback; represent non-webhook hand-offs as
     * accepted so analytics can keep them out of its verified-delivery denominator.
     */
    private function resolveAcceptedDeliveryStatus(bool $succeeded, ?ProviderInterface $provider, Connection $connection): ?string
    {
        if (!$succeeded || $provider === null) {
            return null;
        }

        // Providers may only contribute the non-terminal `accepted` state here. Normalize any legacy
        // terminal value defensively: a provider response alone is never proof of recipient delivery.
        if ($provider->deliveryStatusOnAccept() === DeliveryStatus::ACCEPTED) {
            return DeliveryStatus::ACCEPTED;
        }

        return $this->deliveryWebhookExpected($connection) ? null : DeliveryStatus::ACCEPTED;
    }

    /**
     * Whether a delivery webhook can still report an outcome for this connection — true only when the
     * provider has an inbound adapter AND the connection has the webhook enabled.
     */
    private function deliveryWebhookExpected(Connection $connection): bool
    {
        return WebhookAdapterFactory::supportsProvider($connection->getProvider())
            && $connection->isWebhookEnabled();
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
     * Force the connection's configured From onto the outgoing message so every transport (API,
     * MIME, SMTP) sends from the verified sender the connection is configured with, rather than
     * whatever From the message carried in (e.g. wp_mail()'s wordpress@<site> default) — matching
     * SmtpTransport's own from-forcing for consistency across all transports.
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

    private function routedConnectionId(MailMessage $message, MailSettings $settings): ?string
    {
        $rules    = $this->routingRules($settings);
        $decision = $this->context->getRoutingDecision();

        if ($rules === null) {
            if ($decision !== null) {
                $this->context->setRoutingDecision(new RoutingDecision(
                    $decision->sourcePlugin(),
                    $this->defaultConnectionId($settings),
                    'default',
                    null
                ));
            }

            return null;
        }

        if ($decision === null) {
            $decision = new RoutingDecision($this->sourceDetector->detect(), null, 'native', null);
        }

        $context = RoutingContext::fromArray([
            'recipients'   => $message->getTo(),
            'from'         => $message->getFrom() ?? '',
            'subject'      => $message->getSubject(),
            'sourcePlugin' => $decision->sourcePlugin(),
        ]);
        $decision     = $this->routingResolver->decide($context, $rules);
        $connectionId = $decision->connectionId() ?? $this->defaultConnectionId($settings);

        $this->context->setRoutingDecision(new RoutingDecision(
            $decision->sourcePlugin(),
            $connectionId,
            $decision->type(),
            $decision->ruleIndex()
        ));

        return $decision->connectionId();
    }

    private function captureSourceForSend(MailSettings $settings): void
    {
        if (!$this->loggingEnabled && (!$settings->isEnabled() || $this->routingRules($settings) === null)) {
            return;
        }

        $this->context->setRoutingDecision(new RoutingDecision(
            $this->sourceDetector->detect(),
            null,
            'native',
            null
        ));
    }

    private function captureNativeRoutingDecision(): void
    {
        $decision = $this->context->getRoutingDecision();
        if ($decision === null) {
            $decision = new RoutingDecision($this->sourceDetector->detect(), null, 'native', null);
        } else {
            $decision = $decision->withType('native');
        }

        $this->context->setRoutingDecision($decision);
    }

    private function advanceRoutingDecision(Connection $connection): void
    {
        $decision = $this->context->getRoutingDecision();
        if ($decision === null || $decision->type() === 'fallback' || $decision->type() === 'native') {
            return;
        }

        if ($decision->connectionId() !== null && $decision->connectionId() !== $connection->getId()) {
            $this->context->setRoutingDecision($decision->withType('fallback'));
        }
    }

    private function defaultConnectionId(MailSettings $settings): ?string
    {
        $connections = $this->sendableConnections($settings);

        return isset($connections[0]) ? $connections[0]->getId() : null;
    }

    private function routingRules(MailSettings $settings): ?RoutingRules
    {
        $rawRules = $settings->getFeatures()['routing'] ?? [];
        if (!\is_array($rawRules) || $rawRules === []) {
            return null;
        }

        return RoutingRules::fromArray($rawRules);
    }

    /**
     * Drop connections that cannot send yet so an incomplete setup falls through to native wp_mail
     * instead of forcing a broken send (mirrors the pre-refactor guard). Only SMTP connections gate
     * on a host; API connections carry no host and are always eligible to attempt.
     *
     * @return Connection[]
     */
    private function sendableConnections(MailSettings $settings, ?string $preferredId = null): array
    {
        return array_values(array_filter(
            $this->connectionResolver->resolveOrdered($settings, $preferredId),
            static function (Connection $connection): bool {
                if ($connection->getKind() !== 'smtp') {
                    return true;
                }

                return (string) $connection->setting('host', '') !== '';
            }
        ));
    }

    private function appendDebug(SendResult $result): void
    {
        foreach ($result->getDebug() as $line) {
            $this->context->appendDebug($this->stringifyDebug($line) . "\n");
        }
    }

    /**
     * Debug entries are transport-shaped: SMTP yields plain strings, API transports yield scalars
     * and nested arrays (status/body). Normalize any non-string to a string so accumulating debug
     * output never trips a PHP "array to string conversion" on the API send path.
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
     * One entry in a message's attempt trail: the connection tried and how it resolved. 'accepted'
     * marks a hand-off that carried a per-message error (accepted by the provider but not fully ok).
     *
     * @return array{connection: string, status: string, error: string|null}
     */
    private function attemptEntry(Connection $connection, SendResult $result): array
    {
        if ($result->isOk()) {
            $status = 'sent';
        } elseif ($result->isAccepted()) {
            $status = 'accepted';
        } else {
            $status = 'failed';
        }

        return [
            'connection' => $this->connectionLabel($connection),
            'status'     => $status,
            'error'      => $result->getError(),
        ];
    }

    /**
     * @param array<string,mixed>                                                      $mailData
     * @param array<int,array{connection: string, status: string, error: string|null}> $attempts
     *
     * @return array<string,mixed>
     */
    private function withAttempts(array $mailData, array $attempts): array
    {
        $mailData['attempts'] = $attempts;

        return $mailData;
    }

    /**
     * @param array<string,mixed> $mailData
     */
    private function logOutcome(bool $succeeded, ?SendResult $result, array $mailData, ?Connection $connection, ?string $messageId = null, ?string $trackingId = null, ?string $deliveryStatus = null): void
    {
        if (!$this->loggingEnabled) {
            return;
        }

        // Store the label for the Logs UI, but correlate delivery webhooks on the stable connection id
        // so a rename (or two unnamed same-provider connections) can't break/mis-attribute status.
        $connectionLabel = $connection !== null ? $this->connectionLabel($connection) : null;
        $connectionId    = $connection !== null ? $connection->getId() : null;

        if ($succeeded) {
            $this->eventLogger->logMailSuccess($mailData, $this->context, $connectionLabel, $messageId, $trackingId, $connectionId, $deliveryStatus);

            return;
        }

        $this->eventLogger->logMailFailed($this->toError($result, $mailData), $this->context, $connectionLabel, $messageId, $trackingId, $connectionId);
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
     * The label shown in the Logs UI to identify which connection handled (or attempted) a send.
     */
    private function connectionLabel(Connection $connection): string
    {
        return $connection->label();
    }

    /**
     * Fire the standard WP action once for the final outcome so third-party wp_mail_succeeded /
     * wp_mail_failed listeners still run on a short-circuited send.
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
     * Build the WP_Error core would hand to wp_mail_failed, carrying the mail data and the
     * PHPMailer exception code the logger inspects for connection-level failures.
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
     * Mirror core's wp_mail $mail_data so logging and the re-fired actions carry the same shape.
     *
     * @param array<string,mixed> $atts
     *
     * @return array<string,mixed>
     */
    private function buildMailData(array $atts): array
    {
        $to = $atts['to'] ?? [];
        if (!\is_array($to)) {
            $to = explode(',', $to);
        }

        return [
            'to'          => $to,
            'subject'     => $atts['subject']     ?? '',
            'message'     => $atts['message']     ?? '',
            'headers'     => $atts['headers']     ?? '',
            'attachments' => $atts['attachments'] ?? [],
        ];
    }
}
