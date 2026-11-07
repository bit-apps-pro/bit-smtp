<?php

namespace BitApps\SMTP\Mail\Message;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Support\MailAddress;
use BitApps\SMTP\Mail\Support\SenderResolver;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

/**
 * Build RFC822 MIME string from MailMessage via WP-bundled PHPMailer.
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
     * Applies message fields to the PHPMailer instance; From/Reply-To follow the shared message-wins, connection-fallback policy so raw-MIME transports never emit an empty From.
     *
     * @throws PHPMailerException
     */
    private function applyMessage(PHPMailer $mailer, MailMessage $message, Connection $connection): void
    {
        PhpMailerContent::applyRecipientsAndBody($mailer, $message);

        $sender = new SenderResolver();

        foreach ($sender->from($message, $connection) as $from) {
            [$fromEmail, $fromName] = MailAddress::split($from);
            $mailer->setFrom($fromEmail, $fromName, false);
        }

        foreach ($sender->replyTo($message, $connection) as $replyTo) {
            $mailer->addReplyTo(...MailAddress::split($replyTo));
        }

        PhpMailerContent::applyHeadersAndAttachments($mailer, $message);
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
