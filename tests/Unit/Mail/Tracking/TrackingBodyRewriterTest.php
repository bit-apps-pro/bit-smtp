<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Tracking;

use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Tracking\TokenSigner;
use BitApps\SMTP\Mail\Tracking\TrackingBodyRewriter;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use RuntimeException;

/**
 * @internal
 *
 * @coversNothing
 */
final class TrackingBodyRewriterTest extends BaseUnitTestCase
{
    private const TOKEN = 'msg-token-uuid';

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_salt')->justReturn('unit-test-tracking-salt');
        Functions\when('home_url')->alias(static fn ($path = '') => 'https://site.test/' . ltrim((string) $path, '/'));
    }

    public function testReturnsACopyWithLinksRewrittenAndPixelInjected(): void
    {
        $message = $this->htmlMessage('<html><body><p><a href="https://example.com/order/42">here</a></p></body></html>');

        $rewritten = (new TrackingBodyRewriter())->rewrite($message, self::TOKEN);

        $body = $rewritten->getBody();
        $this->assertStringContainsString('https://site.test/bit-smtp/track/open/', $body, 'the open pixel must be injected');
        $this->assertStringContainsString('https://site.test/bit-smtp/track/click/', $body, 'the link must be rewritten');
        $this->assertStringNotContainsString('href="https://example.com/order/42"', $body);

        // Non-body fields must be carried through untouched on the copy.
        $this->assertSame($message->getTo(), $rewritten->getTo());
        $this->assertSame($message->getSubject(), $rewritten->getSubject());
        $this->assertSame('text/html', $rewritten->getContentType());
    }

    public function testTheEmbeddedTokensVerifyToTheMessageToken(): void
    {
        $message = $this->htmlMessage('<body><a href="https://example.com/x?y=1">x</a></body>');

        $body = (new TrackingBodyRewriter())->rewrite($message, self::TOKEN)->getBody();

        preg_match('#track/open/([A-Za-z0-9\-_]+\.[a-f0-9]{64})#', $body, $open);
        preg_match('#track/click/([A-Za-z0-9\-_]+\.[a-f0-9]{64})#', $body, $click);

        $this->assertSame(['t' => self::TOKEN], TokenSigner::verify($open[1]));
        $this->assertSame(['t' => self::TOKEN, 'u' => 'https://example.com/x?y=1'], TokenSigner::verify($click[1]));
    }

    public function testReturnsTheOriginalMessageWhenRewritingThrows(): void
    {
        // Force a failure inside rewrite() (home_url) to prove a broken rewrite never mutates the send.
        Functions\when('home_url')->alias(static function (): string {
            throw new RuntimeException('boom');
        });
        $message = $this->htmlMessage('<body><a href="https://example.com/x">x</a></body>');

        $result = (new TrackingBodyRewriter())->rewrite($message, self::TOKEN);

        $this->assertSame($message, $result, 'a rewrite failure must return the original message object');
    }

    private function htmlMessage(string $body): MailMessage
    {
        return MailMessage::fromArray([
            'to'          => ['to@example.com'],
            'subject'     => 'Subject',
            'body'        => $body,
            'contentType' => 'text/html',
        ]);
    }
}
