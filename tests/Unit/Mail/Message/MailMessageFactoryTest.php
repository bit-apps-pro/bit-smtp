<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Message;

use BitApps\SMTP\Mail\Message\MailMessageFactory;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use InvalidArgumentException;

/**
 * Covers header-string parsing of wp_mail fork; filters stubbed to test core parsing, not filter effects.
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
        Functions\when('get_bloginfo')->justReturn('UTF-8');
        Functions\when('is_email')->alias(static fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : false);
    }

    public function testDropsInvalidAddressesAndKeepsValidOnesWithTheirNames(): void
    {
        $message = $this->factory->fromWpMailAtts([
            'to'      => 'Good <good@example.org>, not-an-email',
            'headers' => ['From: from@example.org', 'Cc: bad, cc@example.org', 'Bcc: <nope>, Bcc Name <bcc@example.org>', 'Reply-To: bad, reply@example.org'],
        ]);

        $this->assertSame(['Good <good@example.org>'], $message->getTo());
        $this->assertSame(['cc@example.org'], $message->getCc());
        $this->assertSame(['Bcc Name <bcc@example.org>'], $message->getBcc());
        $this->assertSame('reply@example.org', $message->getReplyTo());
    }

    public function testAllInvalidRecipientsFailLikeAnEmptyList(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->factory->fromWpMailAtts(['to' => 'not-an-email', 'headers' => 'From: from@example.org']);
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

    public function testCharsetDefaultsToTheSiteCharset(): void
    {
        Functions\when('get_bloginfo')->alias(static fn (string $show): string => $show === 'charset' ? 'ISO-8859-2' : '');

        $this->assertSame('ISO-8859-2', $this->fromHeaders('Content-Type: text/plain')->getCharset());
    }

    public function testHeaderCharsetBeatsTheSiteCharset(): void
    {
        Functions\when('get_bloginfo')->justReturn('ISO-8859-2');

        $this->assertSame('windows-1250', $this->fromHeaders('Content-Type: text/html; charset="windows-1250"')->getCharset());
    }

    public function testFilteredContentTypeCharsetBeatsTheHeaderCharset(): void
    {
        $this->stubFilters(['wp_mail_content_type' => 'text/html; charset=ISO-8859-2']);

        $this->assertSame('ISO-8859-2', $this->fromHeaders('Content-Type: text/html; charset=windows-1250')->getCharset());
    }

    public function testCharsetFilterIsAppliedLastAndReceivesTheResolvedCharset(): void
    {
        $seen = [];
        Functions\when('apply_filters')->alias(static function (string $hook, $value) use (&$seen) {
            $seen[$hook] = $value;

            return $hook === 'wp_mail_charset' ? 'KOI8-R' : ($hook === 'wp_mail_content_type' ? 'text/html; charset=ISO-8859-2' : $value);
        });

        $message = $this->fromHeaders('Content-Type: text/html; charset=windows-1250');

        $this->assertSame('ISO-8859-2', $seen['wp_mail_charset']);
        $this->assertSame('KOI8-R', $message->getCharset());
    }

    public function testInvalidCharsetFilterResultFallsBackToTheSiteCharset(): void
    {
        Functions\when('get_bloginfo')->justReturn('ISO-8859-2');
        $this->stubFilters(['wp_mail_charset' => "UTF-8\r\nBcc: evil@evil.test"]);

        $this->assertSame('ISO-8859-2', $this->fromHeaders('Content-Type: text/plain')->getCharset());
    }

    public function testInvalidCharsetAndInvalidSiteCharsetFallBackToUtf8(): void
    {
        Functions\when('get_bloginfo')->justReturn('');
        $this->stubFilters(['wp_mail_charset' => '']);

        $this->assertSame('UTF-8', $this->fromHeaders('Content-Type: text/plain')->getCharset());
    }

    public function testNonStringFilterResultsFallBackSafely(): void
    {
        $this->stubFilters(['wp_mail_content_type' => null, 'wp_mail_charset' => ['UTF-8']]);

        $message = $this->fromHeaders('Content-Type: text/html');

        $this->assertSame('text/plain', $message->getContentType());
        $this->assertSame('UTF-8', $message->getCharset());
    }

    public function testBitFormFilteredContentTypeIsHtml(): void
    {
        $this->stubFilters(['wp_mail_content_type' => 'text/html; charset=UTF-8']);

        $message = $this->fromHeaders('');

        $this->assertSame('text/html', $message->getContentType());
        $this->assertTrue($message->isHtml());
        $this->assertSame('UTF-8', $message->getCharset());
    }

    public function testUppercaseContentTypeHeaderIsHtml(): void
    {
        $message = $this->fromHeaders('Content-Type: TEXT/HTML; CHARSET=UTF-8');

        $this->assertSame('text/html', $message->getContentType());
        $this->assertTrue($message->isHtml());
    }

    /**
     * @return array<string,array{0:array<int,string>|string}>
     */
    public static function multipartHeaderShapes(): array
    {
        return [
            'single line'        => ['Content-Type: multipart/alternative; boundary="b-1"'],
            'array entry'        => [['Content-Type: multipart/alternative; boundary="b-1"']],
            'charset first'      => ['Content-Type: multipart/alternative; charset=UTF-8; boundary="b-1"'],
            'boundary first'     => ['Content-Type: multipart/alternative; boundary="b-1"; charset=UTF-8'],
            'continuation line'  => ["Content-Type: multipart/alternative;\n boundary=\"b-1\""],
            'continuation crlf'  => ["Content-Type: multipart/alternative;\r\n\tboundary=b-1"],
            'continuation array' => [['Content-Type: multipart/alternative;', ' boundary="b-1"']],
            'two continuations'  => ["Content-Type: multipart/alternative;\n charset=UTF-8;\n boundary=\"b-1\""],
        ];
    }

    /**
     * @param array<int,string>|string $headers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('multipartHeaderShapes')]
    public function testMultipartBoundaryIsKeptInEveryHeaderShape($headers): void
    {
        $message = $this->fromHeaders($headers);

        $this->assertSame('multipart/alternative; boundary="b-1"', $message->getContentType());
        $this->assertTrue($message->isMultipart());
        $this->assertSame('UTF-8', $message->getCharset());
    }

    public function testContinuationBoundaryMayContainAColon(): void
    {
        $message = $this->fromHeaders("Content-Type: multipart/mixed;\n boundary=\"a:b\"");

        $this->assertSame('multipart/mixed; boundary="a:b"', $message->getContentType());
        $this->assertSame([], $message->getHeaders());
    }

    public function testWooCommerceBareMultipartWithHtmlBodyIsHtml(): void
    {
        $this->stubFilters(['wp_mail_content_type' => 'multipart/alternative']);

        $message = $this->factory->fromWpMailAtts([
            'to'      => 'to@example.com',
            'subject' => 'S',
            'message' => '<p>Order</p>',
            'headers' => "From: s@example.com\r\nContent-Type: multipart/alternative\r\n",
        ]);

        $this->assertSame('text/html', $message->getContentType());
    }

    public function testBareMultipartHeaderWithoutAFilterReachesTheFilterAndIsHtml(): void
    {
        $seen = null;
        Functions\when('apply_filters')->alias(static function (string $hook, $value) use (&$seen) {
            if ($hook === 'wp_mail_content_type') {
                $seen = $value;
            }

            return $value;
        });

        $message = $this->factory->fromWpMailAtts(['to' => 'to@example.com', 'subject' => 'S', 'message' => '<p>x</p>', 'headers' => ['From: s@example.com', 'Content-Type: multipart/alternative']]);

        $this->assertSame('multipart/alternative', $seen);
        $this->assertTrue($message->isHtml());
    }

    public function testBareMultipartWithPlainBodyStaysPlain(): void
    {
        $this->assertSame('text/plain', $this->fromHeaders('Content-Type: multipart/alternative')->getContentType());
    }

    public function testFilterReturningTheSameMultipartTypeWithoutBoundaryKeepsTheHeadersValue(): void
    {
        $this->stubFilters(['wp_mail_content_type' => 'Multipart/Mixed']);

        $this->assertSame('multipart/mixed; boundary="y"', $this->fromHeaders('Content-Type: multipart/mixed; boundary=y')->getContentType());
    }

    public function testFilterReturningADifferentMultipartTypeDoesNotInheritTheBoundary(): void
    {
        $this->stubFilters(['wp_mail_content_type' => 'multipart/alternative']);

        $this->assertSame('text/plain', $this->fromHeaders('Content-Type: multipart/mixed; boundary=y')->getContentType());
    }

    public function testDeclaresContentTypeInEveryHeaderForm(): void
    {
        $this->assertTrue($this->factory->declaresContentType('Content-Type: text/plain'));
        $this->assertTrue($this->factory->declaresContentType(['x', 'content-type: text/html']));
        $this->assertFalse($this->factory->declaresContentType('X-Foo: bar'));
        $this->assertFalse($this->factory->declaresContentType(''));
        $this->assertFalse($this->factory->declaresContentType([null]));
    }

    public function testNonStringHeaderElementsAreSkipped(): void
    {
        $message = $this->factory->fromWpMailAtts([
            'to'      => 'to@example.com',
            'subject' => 'S',
            'message' => 'B',
            'headers' => ['From: x@example.com', null, 42, ['nested'], 'Cc: cc@example.com'],
        ]);

        $this->assertSame('x@example.com', $message->getFrom());
        $this->assertSame(['cc@example.com'], $message->getCc());
    }

    public function testFoldedLineOfAnotherHeaderDoesNotMergeIntoContentType(): void
    {
        $message = $this->fromHeaders("Content-Type: text/calendar\nX-Long: a\n method=PUBLISH");

        $this->assertSame('text/calendar', $message->getContentType());
        $this->assertSame(['X-Long' => 'a'], $message->getHeaders());
    }

    public function testContinuationBeforeTheContentTypeLineIsIgnored(): void
    {
        $this->assertSame('text/plain', $this->fromHeaders([' boundary="b-1"', 'Content-Type: multipart/alternative'])->getContentType());
    }

    public function testContinuationOnlyAttachesToTheLatestContentType(): void
    {
        $message = $this->fromHeaders("Content-Type: text/calendar;\n method=PUBLISH\nContent-Type: text/html");

        $this->assertSame('text/html', $message->getContentType());
    }

    public function testContinuationLineWithoutContentTypeIsIgnored(): void
    {
        $this->assertSame('text/plain', $this->fromHeaders(' boundary="b-1"')->getContentType());
    }

    public function testMultipartValueReachesTheContentTypeFilter(): void
    {
        $seen = null;
        Functions\when('apply_filters')->alias(static function (string $hook, $value) use (&$seen) {
            if ($hook === 'wp_mail_content_type') {
                $seen = $value;
            }

            return $value;
        });

        $this->fromHeaders("Content-Type: multipart/mixed;\n boundary=\"b-1\"");

        $this->assertSame('multipart/mixed; boundary="b-1"', $seen);
    }

    public function testCalendarContentTypeSurvivesWithItsMethod(): void
    {
        $message = $this->fromHeaders('Content-Type: text/calendar; method=REQUEST; charset=UTF-8');

        $this->assertSame('text/calendar; method=REQUEST', $message->getContentType());
        $this->assertFalse($message->isHtml());
        $this->assertFalse($message->isMultipart());
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function injectedFilteredContentTypes(): array
    {
        return [
            'media type' => ["text/html\r\nBcc: evil@evil.test"],
            'boundary'   => ["multipart/mixed; boundary=\"x\r\nBcc: evil@evil.test\""],
            'param'      => ["text/calendar; method=\"R\r\nBcc: evil@evil.test\""],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('injectedFilteredContentTypes')]
    public function testHeaderInjectionThroughTheFilterIsNeutralized(string $filtered): void
    {
        $this->stubFilters(['wp_mail_content_type' => $filtered]);

        $contentType = $this->fromHeaders('')->getContentType();

        $this->assertSame(0, preg_match('/[\r\n]/', $contentType));
        $this->assertStringNotContainsString('Bcc', $contentType);
    }

    public function testHeaderInjectionThroughTheCharsetParamFallsBackToTheSiteCharset(): void
    {
        $message = $this->fromHeaders("Content-Type: text/html; charset=\"UTF-8\r\nBcc: evil@evil.test\"");

        $this->assertSame('UTF-8', $message->getCharset());
    }

    /**
     * @param array<int,string>|string $headers
     */
    private function fromHeaders($headers): \BitApps\SMTP\Mail\Message\MailMessage
    {
        return $this->factory->fromWpMailAtts([
            'to'      => 'to@example.com',
            'subject' => 'S',
            'message' => 'B',
            'headers' => \is_array($headers) ? array_merge($headers, ['From: sender@example.com']) : ltrim($headers . "\nFrom: sender@example.com"),
        ]);
    }

    /**
     * Override selected filters, passing every other hook through unchanged.
     *
     * @param array<string,mixed> $overrides
     */
    private function stubFilters(array $overrides): void
    {
        Functions\when('apply_filters')->alias(
            static fn (string $hook, $value) => \array_key_exists($hook, $overrides) ? $overrides[$hook] : $value
        );
    }
}
