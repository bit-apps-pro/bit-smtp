<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\MimeBuilder;

/**
 * Drives MimeBuilder against the real WP-bundled PHPMailer (unavailable in the unit tier, where it
 * is skipped) to cover the API-path MIME output: the built message carries subject/from/to, and a
 * CR/LF injected into a header value is stripped so it cannot forge a new header line.
 *
 * @internal
 *
 * @coversNothing
 */
final class MimeBuilderTest extends IntegrationTestCase
{
    public function testBuildsMimeWithSubjectFromToAndStripsHeaderInjection(): void
    {
        $this->useRealPhpMailer();

        $message = MailMessage::fromArray([
            'to'       => ['recipient@example.com'],
            'subject'  => 'Hello there',
            'body'     => 'Plain body',
            'from'     => 'sender@example.com',
            'fromName' => 'Sender Name',
            'headers'  => ['X-Custom' => "safe-value\r\nBcc: attacker@evil.test"],
        ]);

        $connection = Connection::fromArray(['id' => 'conn_1', 'provider' => 'amazon_ses', 'kind' => 'api']);

        $mime = (new MimeBuilder())->fromMailMessage($message, $connection);

        $this->assertStringContainsString('Subject: Hello there', $mime);
        $this->assertStringContainsString('sender@example.com', $mime);
        $this->assertStringContainsString('recipient@example.com', $mime);
        // The CRLF is stripped, so the injected "Bcc:" stays inline on the X-Custom value and
        // never begins its own header line.
        $this->assertStringNotContainsString("\nBcc: attacker@evil.test", $mime);
        $this->assertStringContainsString('X-Custom: safe-valueBcc: attacker@evil.test', $mime);
    }
}
