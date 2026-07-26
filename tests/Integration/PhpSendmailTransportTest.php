<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Transport\PhpSendmailTransport;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * @internal
 *
 * @coversNothing
 */
final class PhpSendmailTransportTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
        require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
    }

    public function testConfigureSelectsPhpMailAndMapsConnectionIdentity(): void
    {
        $transport  = new PhpSendmailTransport();
        $connection = $this->connection();
        $mailer     = new PHPMailer(true);

        $transport->configure($mailer, $connection);

        $this->assertSame('mail', $mailer->Mailer);
        $this->assertSame('sender@example.org', $mailer->From);
        $this->assertSame('Sender Name', $mailer->FromName);
        $this->assertSame('sender@example.org', $mailer->Sender);
        $this->assertContains(
            'reply@example.org',
            array_column($mailer->getReplyToAddresses(), 0)
        );
    }

    public function testSendBuildsTheMessageWithoutCallingTheHostMailCommand(): void
    {
        $mailer    = new RecordingPhpMailer();
        $transport = new PhpSendmailTransport(static fn (): PHPMailer => $mailer);
        $message   = MailMessage::fromArray([
            'to'          => ['Recipient <recipient@example.org>'],
            'cc'          => ['cc@example.org'],
            'bcc'         => ['bcc@example.org'],
            'subject'     => 'Local transport subject',
            'body'        => '<p>Local transport body</p>',
            'contentType' => 'text/html',
            'headers'     => ['X-Bit-SMTP-Test' => 'php-sendmail'],
        ]);

        $result = $transport->send($message, $this->connection());

        $this->assertTrue($result->isOk());
        $this->assertTrue($mailer->sendCalled);
        $this->assertSame('mail', $mailer->Mailer);
        $this->assertSame('Local transport subject', $mailer->Subject);
        $this->assertSame('<p>Local transport body</p>', $mailer->Body);
        $this->assertSame('text/html', $mailer->ContentType);
        $this->assertSame(['recipient@example.org', 'Recipient'], $mailer->getToAddresses()[0]);
        $this->assertSame(['cc@example.org', ''], $mailer->getCcAddresses()[0]);
        $this->assertSame(['bcc@example.org', ''], $mailer->getBccAddresses()[0]);
        $this->assertSame(['X-Bit-SMTP-Test', 'php-sendmail'], $mailer->getCustomHeaders()[0]);
    }

    public function testSendReturnsFailureWhenPhpMailerThrows(): void
    {
        $mailer          = new RecordingPhpMailer();
        $mailer->failure = new PHPMailerException('PHP mail failed', 9);
        $transport       = new PhpSendmailTransport(static fn (): PHPMailer => $mailer);
        $message         = MailMessage::fromArray([
            'to'      => ['recipient@example.org'],
            'subject' => 'Failure',
            'body'    => 'Body',
        ]);

        $result = $transport->send($message, $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('PHP mail failed', $result->getError());
        $this->assertSame('9', $result->getCode());
    }

    public function testSendReturnsFailureWhenPhpMailerReturnsFalse(): void
    {
        $mailer             = new RecordingPhpMailer();
        $mailer->sendResult = false;
        $mailer->ErrorInfo  = 'mail() returned false';
        $transport          = new PhpSendmailTransport(static fn (): PHPMailer => $mailer);
        $message            = MailMessage::fromArray([
            'to'      => ['recipient@example.org'],
            'subject' => 'Failure',
            'body'    => 'Body',
        ]);

        $result = $transport->send($message, $this->connection());

        $this->assertFalse($result->isOk());
        $this->assertSame('mail() returned false', $result->getError());
    }

    private function connection(): Connection
    {
        return Connection::fromArray([
            'id'           => 'php-sendmail',
            'provider'     => 'php_sendmail',
            'kind'         => 'local',
            'enabled'      => true,
            'fromEmail'    => 'sender@example.org',
            'fromName'     => 'Sender Name',
            'replyToEmail' => 'reply@example.org',
        ]);
    }
}

final class RecordingPhpMailer extends PHPMailer
{
    public bool $sendCalled = false;

    public ?PHPMailerException $failure = null;

    public bool $sendResult = true;

    public function send()
    {
        $this->sendCalled = true;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->sendResult;
    }
}
