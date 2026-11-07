<?php

namespace BitApps\SMTP\Mail\Transport;

use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\PhpMailerContent;
use BitApps\SMTP\Mail\Support\MailAddress;
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
        PhpMailerContent::applyRecipientsAndBody($mailer, $message);

        $from = $message->getFrom();
        if ($from !== null && $from !== '') {
            $mailer->setFrom($from, (string) $message->getFromName(), false);
        }

        $replyTo = $message->getReplyTo();
        if ($replyTo !== null && $replyTo !== '') {
            $mailer->addReplyTo(...MailAddress::split($replyTo));
        }

        PhpMailerContent::applyHeadersAndAttachments($mailer, $message);
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
}
