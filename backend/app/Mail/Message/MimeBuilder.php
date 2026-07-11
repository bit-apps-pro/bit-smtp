<?php

namespace BitApps\SMTP\Mail\Message;

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
    public function fromMailMessage(MailMessage $message): string
    {
        $this->requirePhpMailer();

        $mailer = new PHPMailer(true);
        $mailer->isMail();

        try {
            $this->applyMessage($mailer, $message);
            $mailer->preSend();
        } catch (PHPMailerException $e) {
            throw new RuntimeException('Unable to build MIME message: ' . $e->getMessage(), 0, $e);
        }

        return $mailer->getSentMIMEMessage();
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
