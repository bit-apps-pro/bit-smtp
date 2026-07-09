<?php

namespace BitApps\SMTP\Mail\Transport;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\CredentialResolverInterface;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Credentials\Credential;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\SendResult;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * Explicit SMTP send seam: builds and drives its own PHPMailer, bypassing wp_mail().
 * The wp_mail SMTP path (with third-party filters) stays via WpMailBridge's phpmailer_init.
 */
class SmtpTransport implements TransportInterface
{
    private CredentialResolverInterface $credentialResolver;

    public function __construct(CredentialResolverInterface $credentialResolver)
    {
        $this->credentialResolver = $credentialResolver;
    }

    /**
     * Map a connection's SMTP settings onto a PHPMailer instance.
     */
    public function configure(PHPMailer $mailer, Connection $connection): void
    {
        $mailer->Mailer = 'smtp';
        $mailer->Host   = (string) $connection->setting('host', '');
        $mailer->Port   = (int) $connection->setting('port', 0);

        if ($connection->setting('auth')) {
            $mailer->SMTPAuth = true;
            $mailer->Username = (string) $connection->setting('username', '');
            $mailer->Password = (string) $this->resolvePassword($connection);
        }

        $encryption = (string) $connection->setting('encryption', 'none');
        if ($encryption !== '' && $encryption !== 'none') {
            $mailer->SMTPSecure = $encryption;
        }

        $fromEmail = $connection->getFromEmail();
        if ($fromEmail !== '') {
            $mailer->setFrom($fromEmail, $connection->getFromName());
            $mailer->Sender = $fromEmail;
        }

        $replyTo = $connection->getReplyToEmail();
        if ($replyTo !== '') {
            $mailer->addReplyTo($replyTo);
        }
    }

    public function send(MailMessage $message, Connection $connection): SendResult
    {
        $this->requirePhpMailer();

        $debugLines = [];
        $mailer     = new PHPMailer(true);

        // Keep at DEBUG_CONNECTION (level 3): level 4 (DEBUG_LOWLEVEL) includes the base64 AUTH payload, which is returned to the client by the connection-test endpoint.
        $mailer->SMTPDebug   = SMTP::DEBUG_CONNECTION;
        $mailer->Debugoutput = static function ($str, $level) use (&$debugLines) {
            $debugLines[] = $str;
        };

        try {
            $this->configure($mailer, $connection);
            $this->applyMessage($mailer, $message);
            $mailer->send();

            return SendResult::success($debugLines);
        } catch (PHPMailerException $e) {
            return SendResult::failure($e->getMessage(), (string) $e->getCode(), $debugLines);
        }
    }

    /**
     * @throws PHPMailerException
     */
    private function applyMessage(PHPMailer $mailer, MailMessage $message): void
    {
        foreach ($message->getTo() as $address) {
            $this->addRecipient($mailer, 'addAddress', $address);
        }
        foreach ($message->getCc() as $address) {
            $this->addRecipient($mailer, 'addCC', $address);
        }
        foreach ($message->getBcc() as $address) {
            $this->addRecipient($mailer, 'addBCC', $address);
        }

        $mailer->Subject = $message->getSubject();
        $mailer->Body    = $message->getBody();
        $mailer->isHTML($message->getContentType() === 'text/html');

        // A message-level From overrides the connection From header, but not the envelope
        // Sender: that stays the authenticated connection identity so relays don't reject on SPF.
        $from = $message->getFrom();
        if ($from !== null && $from !== '') {
            $mailer->setFrom($from, (string) $message->getFromName(), false);
        }

        $replyTo = $message->getReplyTo();
        if ($replyTo !== null && $replyTo !== '') {
            $this->addRecipient($mailer, 'addReplyTo', $replyTo);
        }

        foreach ($message->getHeaders() as $name => $content) {
            // PHPMailer emits MIME-Version and X-Mailer itself; skip to avoid duplicates (mirrors core).
            if (\in_array($name, ['MIME-Version', 'X-Mailer'], true)) {
                continue;
            }
            // Drop CRLF from both name and value to prevent header injection.
            $name    = str_replace(["\r", "\n"], '', $name);
            $content = str_replace(["\r", "\n"], '', $content);
            $mailer->addCustomHeader(\sprintf('%s: %s', $name, $content));
        }

        foreach ($message->getAttachments() as $filename => $attachment) {
            $mailer->addAttachment($attachment, \is_string($filename) ? $filename : '');
        }
    }

    /**
     * Split an RFC822 "Name <addr>" address the way core does before handing it to PHPMailer,
     * so display names on to/cc/bcc/reply-to are preserved instead of failing validation.
     */
    private function addRecipient(PHPMailer $mailer, string $method, string $address): void
    {
        $name = '';
        if (preg_match('/(.*)<(.+)>/', $address, $matches) && \count($matches) === 3) {
            $name    = trim($matches[1]);
            $address = trim($matches[2]);
        }

        $mailer->{$method}($address, $name);
    }

    private function resolvePassword(Connection $connection): ?string
    {
        $credentials = $connection->getCredentials();
        if (!isset($credentials['password']) || !\is_array($credentials['password'])) {
            return null;
        }

        return $this->credentialResolver->resolve(Credential::fromArray($credentials['password']));
    }

    /**
     * Load the WP-bundled PHPMailer classes if the host application has not already.
     */
    private function requirePhpMailer(): void
    {
        if (class_exists(PHPMailer::class)) {
            return;
        }

        require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
        require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
        require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
    }
}
