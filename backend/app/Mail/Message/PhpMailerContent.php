<?php

namespace BitApps\SMTP\Mail\Message;

use BitApps\SMTP\Mail\Support\MailAddress;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Applies the message fields every PHPMailer-based transport shares; callers set From and Reply-To between the two steps.
 */
final class PhpMailerContent
{
    private const RESERVED_HEADERS = ['MIME-Version', 'X-Mailer'];

    /**
     * Set recipients, subject, body, charset and content type; call before phpmailer_init fires so third-party hooks can still override them.
     *
     * @throws PHPMailerException
     */
    public static function applyRecipientsAndBody(PHPMailer $mailer, MailMessage $message): void
    {
        foreach ($message->getTo() as $address) {
            $mailer->addAddress(...MailAddress::split($address));
        }
        foreach ($message->getCc() as $address) {
            $mailer->addCC(...MailAddress::split($address));
        }
        foreach ($message->getBcc() as $address) {
            $mailer->addBCC(...MailAddress::split($address));
        }

        $mailer->Subject     = $message->getSubject();
        $mailer->Body        = $message->getBody();
        $mailer->CharSet     = $message->getCharset();
        $mailer->ContentType = $message->getContentType();
    }

    /**
     * Add custom headers, stripped of CRLF to prevent injection, and attachments.
     *
     * @throws PHPMailerException
     */
    public static function applyHeadersAndAttachments(PHPMailer $mailer, MailMessage $message): void
    {
        foreach ($message->getHeaders() as $name => $content) {
            if (\in_array($name, self::RESERVED_HEADERS, true)) {
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
}
