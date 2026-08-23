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
 * Fires `phpmailer_init` on the fully-built mailer (as core does) so third-party tweaks still apply.
 */
class SmtpTransport implements TransportInterface
{
    use AppliesPhpMailerMessage;

    private CredentialResolverInterface $credentialResolver;

    private int $timeoutSeconds;

    public function __construct(CredentialResolverInterface $credentialResolver, int $timeoutSeconds = 30)
    {
        $this->credentialResolver = $credentialResolver;
        $this->timeoutSeconds     = $timeoutSeconds;
    }

    /**
     * Map a connection's SMTP settings onto a PHPMailer instance.
     */
    public function configure(PHPMailer $mailer, Connection $connection): void
    {
        $mailer->Mailer = 'smtp';
        $mailer->Host   = (string) $connection->setting('host', '');
        $mailer->Port   = (int) $connection->setting('port', 0);
        // PHPMailer defaults to a 300s socket timeout; without this, a dead/unreachable host hangs
        // the whole request instead of failing within the configured send_timeout_seconds pref.
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

        // Keep at DEBUG_CONNECTION (level 3): level 4 (DEBUG_LOWLEVEL) includes the base64 AUTH payload, which is returned to the client by the connection-test endpoint.
        $mailer->SMTPDebug   = SMTP::DEBUG_CONNECTION;
        $mailer->Debugoutput = static function ($str, $level) use (&$debugLines) {
            $debugLines[] = $str;
        };

        try {
            $this->configure($mailer, $connection);
            $this->applyMessage($mailer, $message);

            // Our connection config is the baseline; fire phpmailer_init last (mirroring core's
            // do_action_ref_array by-reference call) so third-party listeners (DKIM, custom headers)
            // can tweak — or even reassign — the fully-built mailer.
            do_action_ref_array('phpmailer_init', [&$mailer]);

            $mailer->send();

            return SendResult::success($debugLines);
        } catch (PHPMailerException $e) {
            return SendResult::failure($e->getMessage(), (string) $e->getCode(), $debugLines);
        }
    }

    private function resolvePassword(Connection $connection): ?string
    {
        $credentials = $connection->getCredentials();
        if (!isset($credentials['password']) || !\is_array($credentials['password'])) {
            return null;
        }

        return $this->credentialResolver->resolve(Credential::fromArray($credentials['password']));
    }
}
