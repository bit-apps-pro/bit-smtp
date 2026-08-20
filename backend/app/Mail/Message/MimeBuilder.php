<?php

namespace BitApps\SMTP\Mail\Message;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Support\SenderResolver;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

/**
 * Builds a raw RFC822 MIME string from a MailMessage via the WP-bundled PHPMailer, for
 * providers that submit a pre-built message (e.g. raw-MIME API sends) rather than JSON fields.
 */
class MimeBuilder
{
    /**
     * @throws RuntimeException when PHPMailer built the message but a field failed validation
     */
    public function fromMailMessage(MailMessage $message, Connection $connection): string
    {
        $this->requirePhpMailer();

        $mailer = new PHPMailer(true);
        $mailer->isMail();

        try {
            $this->applyMessage($mailer, $message, $connection);
            $mailer->preSend();
        } catch (PHPMailerException $e) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Message is escaped; $e is the chained previous exception, not output.
            throw new RuntimeException(esc_html('Unable to build MIME message: ' . $e->getMessage()), 0, $e);
        }

        return $mailer->getSentMIMEMessage();
    }

    /**
     * @throws PHPMailerException
     */
    private function applyMessage(PHPMailer $mailer, MailMessage $message, Connection $connection): void
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

        // From/Reply-To follow the shared message-wins, connection-fallback policy so raw-MIME
        // transports (SES/Gmail/M365) never emit an empty From — SES rejects that outright with
        // "There can be only one From address."
        $sender = new SenderResolver();

        foreach ($sender->from($message, $connection) as $from) {
            [$fromEmail, $fromName] = $this->splitAddress($from);
            $mailer->setFrom($fromEmail, $fromName, false);
        }

        foreach ($sender->replyTo($message, $connection) as $replyTo) {
            [$replyEmail, $replyName] = $this->splitAddress($replyTo);
            $mailer->addReplyTo($replyEmail, $replyName);
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
     * Add a recipient through the given PHPMailer method, preserving any "Name <addr>" display name.
     */
    private function addRecipient(PHPMailer $mailer, string $method, string $address): void
    {
        [$email, $name] = $this->splitAddress($address);
        $mailer->{$method}($email, $name);
    }

    /**
     * Split an RFC822 "Name <addr>" string into [address, display name], mirroring core, so a
     * display name survives instead of failing PHPMailer validation.
     *
     * @return array{0:string,1:string}
     */
    private function splitAddress(string $address): array
    {
        $name = '';
        if (preg_match('/(.*)<(.+)>/', $address, $matches) && \count($matches) === 3) {
            $name    = trim($matches[1]);
            $address = trim($matches[2]);
        }

        return [trim($address), $name];
    }

    /**
     * Load the WP-bundled PHPMailer classes if the host application has not already.
     *
     * @throws RuntimeException when no WordPress runtime is present to source PHPMailer from
     */
    private function requirePhpMailer(): void
    {
        if (class_exists(PHPMailer::class)) {
            return;
        }

        if (!\defined('ABSPATH') || !\defined('WPINC') || !is_file(\ABSPATH . \WPINC . '/PHPMailer/PHPMailer.php')) {
            throw new RuntimeException('WP-bundled PHPMailer is not available outside a WordPress runtime.');
        }

        require_once \ABSPATH . \WPINC . '/PHPMailer/PHPMailer.php';
        require_once \ABSPATH . \WPINC . '/PHPMailer/Exception.php';
    }
}
