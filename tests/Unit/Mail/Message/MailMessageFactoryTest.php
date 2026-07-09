<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Message;

use BitApps\SMTP\Mail\Message\MailMessageFactory;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;

/**
 * Unit-covers the header-string parsing of the wp_mail fork. The three wp_mail filters are
 * stubbed to return their argument unchanged so we assert core's parsing, not filter effects.
 *
 * @internal
 *
 * @coversNothing
 */
class MailMessageFactoryTest extends BaseUnitTestCase
{
    private MailMessageFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new MailMessageFactory();

        Functions\when('apply_filters')->returnArg(2);
    }

    public function testExtractsFromContentTypeCcBccReplyToFromHeaderString(): void
    {
        $headers = implode("\r\n", [
            'From: Jane Doe <jane@example.com>',
            'Content-Type: text/html; charset=UTF-8',
            'Cc: cc-one@example.com, cc-two@example.com',
            'Bcc: bcc@example.com',
            'Reply-To: reply@example.com',
            'X-Custom: keep-me',
        ]);

        $message = $this->factory->fromWpMailAtts([
            'to'      => 'to@example.com',
            'subject' => 'Hi',
            'message' => 'Body',
            'headers' => $headers,
        ]);

        $this->assertSame('jane@example.com', $message->getFrom());
        $this->assertSame('Jane Doe', $message->getFromName());
        $this->assertSame('text/html', $message->getContentType());
        $this->assertSame(['cc-one@example.com', 'cc-two@example.com'], $message->getCc());
        $this->assertSame(['bcc@example.com'], $message->getBcc());
        $this->assertSame('reply@example.com', $message->getReplyTo());
        $this->assertSame(['X-Custom' => 'keep-me'], $message->getHeaders());
    }

    public function testAcceptsHeadersAsArrayAndSplitsCommaSeparatedTo(): void
    {
        Functions\when('network_home_url')->justReturn('https://www.example.com');
        Functions\when('wp_parse_url')->justReturn('www.example.com');

        $message = $this->factory->fromWpMailAtts([
            'to'      => 'a@example.com, b@example.com',
            'subject' => 'S',
            'message' => 'B',
            'headers' => ['Content-Type: text/plain'],
        ]);

        $this->assertSame(['a@example.com', 'b@example.com'], $message->getTo());
        $this->assertSame('text/plain', $message->getContentType());
    }

    public function testDefaultsFromAndContentTypeWhenHeadersAbsent(): void
    {
        Functions\when('network_home_url')->justReturn('https://www.example.com');
        Functions\when('wp_parse_url')->justReturn('www.example.com');

        $message = $this->factory->fromWpMailAtts([
            'to'      => 'to@example.com',
            'subject' => 'S',
            'message' => 'B',
        ]);

        $this->assertSame('wordpress@example.com', $message->getFrom());
        $this->assertSame('WordPress', $message->getFromName());
        $this->assertSame('text/plain', $message->getContentType());
        $this->assertNull($message->getReplyTo());
        $this->assertSame([], $message->getCc());
    }

    public function testNamedCcBccReplyToAddressesArePreservedVerbatim(): void
    {
        $headers = implode("\r\n", [
            'Cc: Cee Cee <cc@example.com>',
            'Bcc: Bee Cee <bcc@example.com>',
            'Reply-To: Reply Person <reply@example.com>',
        ]);

        Functions\when('network_home_url')->justReturn('https://example.com');
        Functions\when('wp_parse_url')->justReturn('example.com');

        $message = $this->factory->fromWpMailAtts([
            'to'      => 'to@example.com',
            'subject' => 'S',
            'message' => 'B',
            'headers' => $headers,
        ]);

        // The factory keeps the "Name <addr>" form; the transport splits it at send time.
        $this->assertSame(['Cee Cee <cc@example.com>'], $message->getCc());
        $this->assertSame(['Bee Cee <bcc@example.com>'], $message->getBcc());
        $this->assertSame('Reply Person <reply@example.com>', $message->getReplyTo());
    }

    public function testLastFromHeaderWins(): void
    {
        $headers = implode("\r\n", [
            'From: First <first@example.com>',
            'From: Second <second@example.com>',
        ]);

        $message = $this->factory->fromWpMailAtts([
            'to'      => 'to@example.com',
            'subject' => 'S',
            'message' => 'B',
            'headers' => $headers,
        ]);

        $this->assertSame('second@example.com', $message->getFrom());
        $this->assertSame('Second', $message->getFromName());
    }

    public function testBareFromHeaderWithoutBracketsBecomesEmailWithDefaultName(): void
    {
        $message = $this->factory->fromWpMailAtts([
            'to'      => 'to@example.com',
            'subject' => 'S',
            'message' => 'B',
            'headers' => 'From: sender@example.com',
        ]);

        $this->assertSame('sender@example.com', $message->getFrom());
        $this->assertSame('WordPress', $message->getFromName());
    }
}
