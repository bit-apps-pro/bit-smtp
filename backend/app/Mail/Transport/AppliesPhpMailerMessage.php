<?php

namespace BitApps\SMTP\Mail\Transport;

use BitApps\SMTP\Mail\Message\MailMessage;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

trait AppliesPhpMailerMessage
{
    /**
     * @throws PHPMailerException
     */
    protected function applyMessage(PHPMailer $mailer, MailMessage $message): void
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
            if (\in_array($name, ['MIME-Version', 'X-Mailer'], true)) {
                continue;
            }

            $name    = str_replace(["\r", "\n"], '', $name);
            $content = str_replace(["\r", "\n"], '', $content);
            $mailer->addCustomHeader(\sprintf('%s: %s', $name, $content));
        }

        foreach ($message->getAttachments() as $filename => $attachment) {
            $mailer->addAttachment($attachment, \is_string($filename) ? $filename : '');
        }
    }

    protected function requirePhpMailer(bool $withSmtp = false): void
    {
        if (!class_exists(PHPMailer::class)) {
            require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
        }

        if ($withSmtp && !class_exists(SMTP::class)) {
            require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
        }

        if (!class_exists(PHPMailerException::class)) {
            require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
        }
    }

    /**
     * @throws PHPMailerException
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
}
