<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Credentials\DatabaseCredentialResolver;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Transport\SmtpTransport;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Exercises the explicit SmtpTransport send seam against mailpit (real SMTP, no wp_mail()).
 *
 * @internal
 *
 * @coversNothing
 */
final class SmtpTransportTest extends IntegrationTestCase
{
    private SmtpTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
        require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
        require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

        $this->transport = new SmtpTransport(new DatabaseCredentialResolver());
    }

    public function testSendDeliversMessageToMailpit(): void
    {
        $connection = $this->mailpitConnection();
        $message    = MailMessage::fromArray([
            'to'          => ['recipient@example.org'],
            'subject'     => 'Transport Subject',
            'body'        => '<p>Hello</p>',
            'contentType' => 'text/html',
        ]);

        $result = $this->transport->send($message, $connection);

        $this->assertTrue($result->isOk(), 'send should succeed: ' . (string) $result->getError());

        $delivered = $this->latestMailpitMessage();
        $this->assertNotNull($delivered);
        $this->assertSame('Transport Subject', $delivered['Subject']);
        $this->assertSame('sender@example.org', $delivered['From']['Address']);
        $this->assertSame('recipient@example.org', $delivered['To'][0]['Address']);
        $this->assertStringContainsStringIgnoringCase('text/html', $this->latestMailpitContentType());
    }

    public function testSendSplitsNamedCcAndKeepsCustomHeader(): void
    {
        $connection = $this->mailpitConnection();
        $message    = MailMessage::fromArray([
            'to'      => ['recipient@example.org'],
            'cc'      => ['Cee Cee <cc@example.org>'],
            'subject' => 'Named Cc',
            'body'    => 'Body',
            'headers' => ['X-Custom' => 'kept'],
        ]);

        $result = $this->transport->send($message, $connection);
        $this->assertTrue($result->isOk(), 'send should succeed: ' . (string) $result->getError());

        $delivered = $this->latestMailpitMessage();
        $this->assertNotNull($delivered);
        $this->assertSame('cc@example.org', $delivered['Cc'][0]['Address']);
        $this->assertSame('Cee Cee', $delivered['Cc'][0]['Name']);

        $headers = $this->mailpitHeaders($delivered['ID']);
        $this->assertSame('kept', $headers['X-Custom'][0] ?? null);
        // PHPMailer adds MIME-Version once; a passed-through duplicate must not appear.
        $this->assertLessThanOrEqual(1, \count($headers['Mime-Version'] ?? $headers['MIME-Version'] ?? []));
    }

    public function testSendToUnreachableHostReturnsFailure(): void
    {
        $connection = Connection::fromArray([
            'id'        => 'unreachable',
            'provider'  => 'other_smtp',
            'kind'      => 'smtp',
            'enabled'   => true,
            'fromEmail' => 'sender@example.org',
            'settings'  => ['host' => '127.0.0.1', 'port' => 2, 'encryption' => 'none', 'auth' => false],
        ]);

        $message = MailMessage::fromArray([
            'to'      => ['recipient@example.org'],
            'subject' => 'Nope',
            'body'    => 'Body',
        ]);

        $result = $this->transport->send($message, $connection);

        $this->assertFalse($result->isOk());
        $this->assertNotEmpty($result->getError());
    }

    public function testConfigureMapsConnectionOntoPhpmailer(): void
    {
        $connection = Connection::fromArray([
            'id'           => 'cfg',
            'provider'     => 'other_smtp',
            'kind'         => 'smtp',
            'enabled'      => true,
            'fromEmail'    => 'sender@example.org',
            'fromName'     => 'Sender Name',
            'replyToEmail' => 'reply@example.org',
            'settings'     => [
                'host'       => 'smtp.example.com',
                'port'       => 465,
                'encryption' => 'ssl',
                'auth'       => true,
                'username'   => 'smtp-user',
            ],
            'credentials'  => ['password' => ['source' => 'database', 'value' => 'smtp-pass']],
        ]);

        $mailer = new PHPMailer(true);
        $this->transport->configure($mailer, $connection);

        $this->assertSame('smtp', $mailer->Mailer);
        $this->assertSame('smtp.example.com', $mailer->Host);
        $this->assertSame(465, $mailer->Port);
        $this->assertTrue($mailer->SMTPAuth);
        $this->assertSame('smtp-user', $mailer->Username);
        $this->assertSame('smtp-pass', $mailer->Password);
        $this->assertSame('ssl', $mailer->SMTPSecure);
        $this->assertSame('sender@example.org', $mailer->From);
        $this->assertSame('Sender Name', $mailer->FromName);
        $this->assertSame('sender@example.org', $mailer->Sender);
        $replyToAddresses = array_column($mailer->getReplyToAddresses(), 0);
        $this->assertContains('reply@example.org', $replyToAddresses);
    }

    public function testSendStripsHeaderNameCrlf(): void
    {
        $connection = $this->mailpitConnection();
        $message    = MailMessage::fromArray([
            'to'      => ['recipient@example.org'],
            'subject' => 'CRLF Injection Test',
            'body'    => 'Body',
            'headers' => ["X-Safe\r\nX-Injected" => 'should-not-appear'],
        ]);

        $result = $this->transport->send($message, $connection);
        $this->assertTrue($result->isOk(), 'send should succeed: ' . (string) $result->getError());

        $delivered = $this->latestMailpitMessage();
        $this->assertNotNull($delivered);

        $headers = $this->mailpitHeaders($delivered['ID']);

        // Assert that the injected header name does not appear (case-insensitive check).
        $headerKeyLower = strtolower('X-Injected');
        $injectedExists  = false;
        foreach (array_keys($headers) as $key) {
            if (strtolower($key) === $headerKeyLower) {
                $injectedExists = true;
                break;
            }
        }
        $this->assertFalse($injectedExists, 'Header name CRLF injection should be stripped; X-Injected must not appear');

        // The CR+LF is stripped without a separator, so "X-Safe\r\nX-Injected" collapses to
        // "X-SafeX-Injected" as a single harmless custom header — assert it is present.
        $collapsedExists = false;
        foreach (array_keys($headers) as $key) {
            if (strtolower($key) === strtolower('X-SafeX-Injected')) {
                $collapsedExists = true;
                break;
            }
        }
        $this->assertTrue($collapsedExists, 'Collapsed header X-SafeX-Injected should be present as a single header');
    }

    private function mailpitConnection(): Connection
    {
        return Connection::fromArray([
            'id'        => 'mailpit',
            'provider'  => 'other_smtp',
            'kind'      => 'smtp',
            'enabled'   => true,
            'fromEmail' => 'sender@example.org',
            'fromName'  => 'Sender',
            'settings'  => [
                'host'       => self::SMTP_HOST,
                'port'       => self::SMTP_PORT,
                'encryption' => 'none',
                'auth'       => false,
            ],
        ]);
    }

    private function latestMailpitContentType(): string
    {
        $latest = $this->latestMailpitMessage();
        if ($latest === null || !isset($latest['ID'])) {
            return '';
        }

        $headers = $this->mailpitHeaders($latest['ID']);

        return isset($headers['Content-Type'][0]) ? (string) $headers['Content-Type'][0] : '';
    }

    /**
     * @return array<string,array<int,string>>
     */
    private function mailpitHeaders(string $id): array
    {
        $response = wp_remote_get(self::MAILPIT_API . '/message/' . $id . '/headers');
        $headers  = json_decode(wp_remote_retrieve_body($response), true);

        return \is_array($headers) ? $headers : [];
    }
}
