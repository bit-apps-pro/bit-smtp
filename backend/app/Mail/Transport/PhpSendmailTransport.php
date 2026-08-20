<?php

namespace BitApps\SMTP\Mail\Transport;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\SendResult;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Sends through PHP's mail() function, which delegates to the host's configured sendmail command.
 */
class PhpSendmailTransport implements TransportInterface
{
    use AppliesPhpMailerMessage;

    /**
     * @var null|callable():PHPMailer
     */
    private $mailerFactory;

    public function __construct(?callable $mailerFactory = null)
    {
        $this->mailerFactory = $mailerFactory;
    }

    public function configure(PHPMailer $mailer, Connection $connection): void
    {
        $mailer->isMail();

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

        try {
            $mailer = $this->mailerFactory !== null
                ? ($this->mailerFactory)()
                : new PHPMailer(true);

            $this->configure($mailer, $connection);
            $this->applyMessage($mailer, $message);

            do_action_ref_array('phpmailer_init', [&$mailer]);

            if (!$mailer->send()) {
                return SendResult::failure(
                    $mailer->ErrorInfo !== '' ? $mailer->ErrorInfo : 'PHP mail() failed.'
                );
            }

            return SendResult::success();
        } catch (PHPMailerException $e) {
            return SendResult::failure($e->getMessage(), (string) $e->getCode());
        }
    }
}
