<?php

namespace BitApps\SMTP\Mail\Dispatch;

use BitApps\SMTP\Deps\BitApps\WPKit\Hooks\Hooks;
use BitApps\SMTP\Mail\Connections\ConnectionResolver;
use BitApps\SMTP\Mail\Transport\SmtpTransport;
use BitApps\SMTP\Plugin;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use WP_Error;

/**
 * Bridges WordPress' wp_mail pipeline onto our SMTP configuration and logging.
 *
 * Maps the stored SMTP config onto PHPMailer via `phpmailer_init`, and delegates the
 * `wp_mail_succeeded` / `wp_mail_failed` outcomes to a composed MailEventLogger. Owns the single
 * mutable SendContext that the controller mutates before wp_mail() and reads back afterwards.
 */
class WpMailBridge
{
    private SendContext $context;

    private MailEventLogger $eventLogger;

    private SmtpTransport $transport;

    private ConnectionResolver $connectionResolver;

    public function __construct(SmtpTransport $transport, ConnectionResolver $connectionResolver)
    {
        $this->transport          = $transport;
        $this->connectionResolver = $connectionResolver;
        $this->context            = new SendContext();
        $this->eventLogger        = new MailEventLogger(Plugin::instance()->logger());

        Hooks::addAction('phpmailer_init', [$this, 'configureMailer'], 1000);

        if (Plugin::instance()->logger()->isEnabled()) {
            // Only wire logging when enabled, to avoid unnecessary overhead on every send.
            Hooks::addAction('wp_mail_succeeded', [$this, 'logMailSuccess']);
            Hooks::addAction('wp_mail_failed', [$this, 'logMailFailed']);
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

    public function configureMailer(PHPMailer $mailer): void
    {
        // Start of a wp_mail send: clear stale output but keep caller-set inputs.
        $this->context->resetForSend();

        $settings = Plugin::instance()->mailConfigService()->load();
        if (!$settings->isEnabled()) {
            // Not enabled: leave WP's default mail path untouched.
            return;
        }

        $connection = $this->connectionResolver->resolve($settings);
        if ($connection === null || (string) $connection->setting('host', '') === '') {
            // No usable connection (or no host yet): leave WP's default mail path untouched.
            return;
        }

        $this->transport->configure($mailer, $connection);

        if ($this->context->isDebug() || (bool) $connection->setting('smtp_debug', false)) {
            // Capture connection-level debug so test-mail failures surface actionable insight.
            $mailer->SMTPDebug   = SMTP::DEBUG_CONNECTION;
            $mailer->Debugoutput = [$this, 'captureDebugOutput'];
        }
    }

    public function logMailSuccess($mailData): void
    {
        $this->eventLogger->logMailSuccess($mailData, $this->context);
    }

    public function logMailFailed(WP_Error $error): void
    {
        $this->eventLogger->logMailFailed($error, $this->context);
    }

    /**
     * PHPMailer Debugoutput callback: ($str, $level).
     *
     * @param mixed $debugOutput
     * @param mixed $debugLevel
     */
    public function captureDebugOutput($debugOutput, $debugLevel): void
    {
        $this->context->appendDebug($debugOutput . "\n");
    }
}
