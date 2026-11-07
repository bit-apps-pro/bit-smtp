<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\MimeBuilder;

/**
 * Tests MimeBuilder MIME output with real PHPMailer; verifies header-injection defense.
 *
 * @internal
 *
 * @coversNothing
 */
final class MimeBuilderTest extends IntegrationTestCase
{
    /**
     * Build MIME with subject/from/to; header injection (CRLF) is stripped to prevent forged headers.
     */
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
        $this->assertStringNotContainsString("\nBcc: attacker@evil.test", $mime);
        $this->assertStringContainsString('X-Custom: safe-valueBcc: attacker@evil.test', $mime);
    }

    public function testUsesTheMessageCharsetForHeadersBodyAndEncodedWords(): void
    {
        $mime = $this->buildMime([
            'subject'     => 'Příliš žluťoučký kůň',
            'body'        => '<p>Příliš žluťoučký kůň</p>',
            'fromName'    => 'Kůň',
            'contentType' => 'text/html; charset=UTF-8',
            'charset'     => 'UTF-8',
        ]);

        $this->assertMatchesRegularExpression('/^Content-Type: text\/html; charset=UTF-8\r?$/m', $mime);
        $this->assertStringContainsString('Subject: =?UTF-8?', $mime);
        $this->assertStringContainsString('=?UTF-8?', substr($mime, (int) strpos($mime, 'From:'), 40));
        $this->assertStringContainsString('<p>Příliš žluťoučký kůň</p>', $mime);
        $this->assertStringNotContainsString('iso-8859-1', strtolower($mime));
    }

    public function testKeepsMultipartBodyAndBoundary(): void
    {
        $body = "--b1\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nplain\r\n--b1\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p>html</p>\r\n--b1--\r\n";
        $mime = $this->buildMime(['body' => $body, 'contentType' => 'multipart/alternative; boundary="b1"']);

        $this->assertMatchesRegularExpression('/^Content-Type: multipart\/alternative; boundary="b1"(; charset=UTF-8)?\r?$/m', $mime);
        $this->assertStringContainsString($body, str_replace("\n", "\r\n", str_replace("\r\n", "\n", $mime)));
    }

    public function testKeepsCalendarContentType(): void
    {
        $mime = $this->buildMime(['body' => 'BEGIN:VCALENDAR', 'contentType' => 'text/calendar; method=REQUEST']);

        $this->assertMatchesRegularExpression('/^Content-Type: text\/calendar; method=REQUEST; charset=UTF-8\r?$/m', $mime);
    }

    public function testRejectsContentTypeHeaderInjection(): void
    {
        $mime = $this->buildMime(['contentType' => "multipart/mixed; boundary=\"x\r\nBcc: attacker@evil.test\"", 'charset' => "UTF-8\r\nX-Evil: yes"]);

        $this->assertStringNotContainsString('attacker@evil.test', $mime);
        $this->assertStringNotContainsString('X-Evil', $mime);
        $this->assertMatchesRegularExpression('/^Content-Type: text\/plain; charset=UTF-8\r?$/m', $mime);
    }

    /**
     * Build a MIME message from the given fields over a minimal valid message.
     *
     * @param array<string,string> $overrides
     */
    private function buildMime(array $overrides): string
    {
        $this->useRealPhpMailer();

        $message = MailMessage::fromArray(array_merge([
            'to'      => ['recipient@example.com'],
            'subject' => 'Subject',
            'body'    => 'Body',
            'from'    => 'sender@example.com',
        ], $overrides));

        return (new MimeBuilder())->fromMailMessage($message, Connection::fromArray(['id' => 'conn_1', 'provider' => 'amazon_ses', 'kind' => 'api']));
    }
}
