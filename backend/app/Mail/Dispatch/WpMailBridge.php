<?php

namespace BitApps\SMTP\Mail\Dispatch;

use BitApps\SMTP\Deps\BitApps\WPKit\Hooks\Hooks;
use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Connections\ConnectionResolver;
use BitApps\SMTP\Mail\Exceptions\ProviderNotFoundException;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\MailMessageFactory;
use BitApps\SMTP\Mail\Message\SendResult;
use BitApps\SMTP\Mail\Providers\ProviderRegistry;
use BitApps\SMTP\Mail\Routing\MailSourceDetector;
use BitApps\SMTP\Mail\Routing\RoutingContext;
use BitApps\SMTP\Mail\Routing\RoutingResolver;
use BitApps\SMTP\Mail\Routing\RoutingRules;
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

    private ProviderRegistry $registry;

    private ConnectionResolver $connectionResolver;

    private MailMessageFactory $messageFactory;

    private RoutingResolver $routingResolver;

    private MailSourceDetector $sourceDetector;

    private bool $loggingEnabled;

    /**
     * True while our dispatch loop is running, so the native-path log listeners skip our own sends.
     */
    private bool $dispatching = false;

    public function __construct(
        ProviderRegistry $registry,
        ConnectionResolver $connectionResolver,
        MailMessageFactory $messageFactory,
        RoutingResolver $routingResolver,
        MailSourceDetector $sourceDetector
    ) {
        $this->registry           = $registry;
        $this->connectionResolver = $connectionResolver;
        $this->messageFactory     = $messageFactory;
        $this->routingResolver    = $routingResolver;
        $this->sourceDetector     = $sourceDetector;
        $this->context            = new SendContext();
        $this->eventLogger        = new MailEventLogger(Plugin::instance()->logger());
        $this->loggingEnabled     = Plugin::instance()->logger()->isEnabled();

        Hooks::addFilter('pre_wp_mail', [$this, 'onPreWpMail'], 10, 2);

        if ($this->loggingEnabled) {
            // Sends we defer to native wp_mail (routing disabled / mid-setup) still fire these core
            // actions; keep logging them as before. Our own dispatch is skipped via $dispatching.
            Hooks::addAction('wp_mail_succeeded', [$this, 'onNativeMailSucceeded']);
            Hooks::addAction('wp_mail_failed', [$this, 'onNativeMailFailed']);
        }
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

        // Clear stale per-send output up front so a deferred (native) send's read-back is not
        // polluted by a prior dispatch; the native log listeners record that send's outcome.
        $this->context->resetForSend();

        $settings = Plugin::instance()->mailConfigService()->load();
        if (!$settings->isEnabled()) {
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
        if ($this->dispatching) {
            return;
        }

        $this->eventLogger->logMailSuccess((array) $mailData, $this->context);
    }

    /**
     * wp_mail_failed listener for sends we deferred to native wp_mail (see onNativeMailSucceeded).
     */
    public function onNativeMailFailed(WP_Error $error): void
    {
        if ($this->dispatching) {
            return;
        }

        $this->eventLogger->logMailFailed($error, $this->context);
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
            $isRetry    = $this->context->isRetrying();
            $succeeded  = false;
            $lastResult = null;

            foreach ($connections as $connection) {
                $lastResult = $this->sendVia($connection, $message);
                $this->appendDebug($lastResult);

                // A resend updates its originating log once with the final outcome (below); a fresh
                // send records every attempt so the fallback history is visible.
                if (!$isRetry) {
                    $this->logAttempt($lastResult, $mailData);
                }

                if ($lastResult->isOk()) {
                    $succeeded = true;

                    break;
                }
            }

            $this->context->setFailed(!$succeeded);

            if ($isRetry) {
                $this->logOutcome($succeeded, $lastResult, $mailData);
            }

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
    private function sendVia(Connection $connection, MailMessage $message): SendResult
    {
        try {
            $transport = $this->registry->get($connection->getProvider())->transport();
        } catch (ProviderNotFoundException $e) {
            return SendResult::failure($e->getMessage());
        }

        return $transport->send($message, $connection);
    }

    /**
     * Resolve the connection a matching routing rule picks for this message, or null when routing is
     * unconfigured or no rule matches — in which case the dispatch order is unchanged (BC). Source
     * detection (a backtrace walk) is skipped entirely when no rules are configured, the common case.
     */
    private function routedConnectionId(MailMessage $message, MailSettings $settings): ?string
    {
        $features = $settings->getFeatures();
        $rawRules = $features['routing'] ?? [];
        if (!\is_array($rawRules) || $rawRules === []) {
            return null;
        }

        $context = RoutingContext::fromArray([
            'recipients'   => $message->getTo(),
            'from'         => $message->getFrom() ?? '',
            'subject'      => $message->getSubject(),
            'sourcePlugin' => $this->sourceDetector->detect(),
        ]);

        return $this->routingResolver->resolve($context, RoutingRules::fromArray($rawRules));
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
     * @param array<string,mixed> $mailData
     */
    private function logAttempt(SendResult $result, array $mailData): void
    {
        if (!$this->loggingEnabled) {
            return;
        }

        if ($result->isOk()) {
            $this->eventLogger->logMailSuccess($mailData, $this->context);

            return;
        }

        $this->eventLogger->logMailFailed($this->toError($result, $mailData), $this->context);
    }

    /**
     * @param array<string,mixed> $mailData
     */
    private function logOutcome(bool $succeeded, ?SendResult $result, array $mailData): void
    {
        if (!$this->loggingEnabled) {
            return;
        }

        if ($succeeded) {
            $this->eventLogger->logMailSuccess($mailData, $this->context);

            return;
        }

        $this->eventLogger->logMailFailed($this->toError($result, $mailData), $this->context);
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
