<?php

namespace BitApps\SMTP\Mail\Transport;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\CredentialResolverInterface;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Credentials\ConnectionSecretResolver;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\SendResult;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * SMTP send seam: build and drive PHPMailer directly, firing phpmailer_init for third-party tweaks.
 */
class SmtpTransport implements TransportInterface
{
    use AppliesPhpMailerMessage;

    private ConnectionSecretResolver $secretResolver;

    private int $timeoutSeconds;

    public function __construct(CredentialResolverInterface $credentialResolver, int $timeoutSeconds = 30)
    {
        $this->secretResolver = new ConnectionSecretResolver($credentialResolver);
        $this->timeoutSeconds = $timeoutSeconds;
    }

    /**
     * Map a connection's SMTP settings onto a PHPMailer instance.
     */
    public function configure(PHPMailer $mailer, Connection $connection): void
    {
        $mailer->Mailer  = 'smtp';
        $mailer->Host    = (string) $connection->setting('host', '');
        $mailer->Port    = (int) $connection->setting('port', 0);
        $mailer->Timeout = $this->timeoutSeconds;

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
        $this->requirePhpMailer(true);

        $debugLines = [];
        $mailer     = new PHPMailer(true);

        $mailer->SMTPDebug   = SMTP::DEBUG_CONNECTION;
        $mailer->Debugoutput = static function ($str, $level) use (&$debugLines) {
            $debugLines[] = $str;
        };

        try {
            $this->configure($mailer, $connection);
            $this->applyMessage($mailer, $message);

            do_action_ref_array('phpmailer_init', [&$mailer]);

            $mailer->send();

            $messageId = $mailer->getLastMessageID();

            return $messageId !== ''
                ? SendResult::success($debugLines)->withMessageId($messageId)
                : SendResult::success($debugLines);
        } catch (PHPMailerException $e) {
            return SendResult::failure($e->getMessage(), (string) $e->getCode(), $debugLines);
        }
    }

    /**
     * Resolve the SMTP password, letting a wp-config constant override the stored value at send time.
     */
    private function resolvePassword(Connection $connection): ?string
    {
        return $this->secretResolver->resolve($connection, 'password');
    }
}
