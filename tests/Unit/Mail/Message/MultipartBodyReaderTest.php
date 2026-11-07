<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Message;

use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\MultipartBodyReader;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * Covers flattening a multipart message to one UTF-8 html/plain part for API providers.
 *
 * @internal
 *
 * @coversNothing
 */
class MultipartBodyReaderTest extends BaseUnitTestCase
{
    private const DIACRITICS = 'Příliš žluťoučký kůň';

    public function testNonMultipartMessageIsReturnedUntouched(): void
    {
        $message = $this->message('text/html', '<p>Hi</p>');

        $this->assertSame(['message' => $message, 'dropped' => []], (new MultipartBodyReader())->flatten($message));
    }

    public function testPrefersHtmlOverPlainText(): void
    {
        $body = implode("\r\n", [
            'preamble to ignore',
            '--b1',
            'Content-Type: text/plain; charset=UTF-8',
            '',
            'plain version',
            '--b1',
            'Content-Type: text/html; charset=UTF-8',
            '',
            '<p>html version</p>',
            '--b1--',
            'epilogue to ignore',
        ]);

        $result = $this->flatten($body);

        $this->assertSame('text/html', $result->getContentType());
        $this->assertTrue($result->isHtml());
        $this->assertFalse($result->isMultipart());
        $this->assertSame('<p>html version</p>', $result->getBody());
    }

    public function testFallsBackToPlainTextWhenNoHtmlPart(): void
    {
        $body = implode("\n", [
            '--b1',
            'Content-Type: application/pdf',
            '',
            'binary',
            '--b1',
            'Content-Type: text/plain',
            '',
            'line one',
            'line two',
            '--b1--',
        ]);

        $result = $this->flatten($body);

        $this->assertSame('text/plain', $result->getContentType());
        $this->assertSame("line one\nline two", $result->getBody());
    }

    public function testPartWithoutContentTypeIsTreatedAsPlainText(): void
    {
        $result = $this->flatten("--b1\r\n\r\nno headers here\r\n--b1--\r\n");

        $this->assertSame('text/plain', $result->getContentType());
        $this->assertSame('no headers here', $result->getBody());
    }

    public function testDecodesBase64Part(): void
    {
        $body = implode("\r\n", [
            '--b1',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: BASE64',
            '',
            chunk_split(base64_encode('<p>' . self::DIACRITICS . '</p>'), 20, "\r\n") . '--b1--',
        ]);

        $this->assertSame('<p>' . self::DIACRITICS . '</p>', $this->flatten($body)->getBody());
    }

    public function testDecodesQuotedPrintablePart(): void
    {
        $body = implode("\r\n", [
            '--b1',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            quoted_printable_encode(self::DIACRITICS),
            '--b1--',
        ]);

        $this->assertSame(self::DIACRITICS, $this->flatten($body)->getBody());
    }

    public function testConvertsLabelledNonUtf8PartToUtf8(): void
    {
        $this->assertSame(self::DIACRITICS, $this->flatten($this->latin2Part())->getBody());
    }

    public function testResultIsUtf8RegardlessOfTheMessageCharset(): void
    {
        $result = (new MultipartBodyReader())->flatten($this->message('multipart/alternative; boundary="b1"', $this->latin2Part(), 'ISO-8859-2'))['message'];

        $this->assertSame(self::DIACRITICS, $result->getBody());
        $this->assertSame('UTF-8', $result->getCharset());
        $this->assertNotFalse(json_encode($result->getBody()));
    }

    public function testUtf8BytesAreKeptDespiteAMisleadingLabel(): void
    {
        $body = "--b1\r\nContent-Type: text/plain; charset=us-ascii\r\n\r\nhé\r\n--b1--";

        $this->assertSame('hé', $this->flatten($body)->getBody());
    }

    public function testUnknownLabelWithAsciiBytesIsKept(): void
    {
        $body = "--b1\r\nContent-Type: text/plain; charset=x-not-a-real-charset\r\n\r\nkept\r\n--b1--";

        $this->assertSame('kept', $this->flatten($body)->getBody());
    }

    public function testUnknownLabelWithNonUtf8BytesIsUnreadable(): void
    {
        $this->assertUnflattenable("--b1\r\nContent-Type: text/plain; charset=x-not-a-real-charset\r\n\r\nh\xE9\r\n--b1--");
    }

    public function testNoLabelWithNonUtf8BytesIsUnreadable(): void
    {
        $this->assertUnflattenable("--b1\r\nContent-Type: text/plain\r\n\r\nh\xE9\r\n--b1--");
    }

    public function testUnreadableHtmlFallsThroughToReadablePlainText(): void
    {
        $body = "--b1\r\nContent-Type: text/html\r\n\r\n<p>h\xE9</p>\r\n--b1\r\nContent-Type: text/plain\r\n\r\nreadable\r\n--b1--";

        $flattened = $this->flattened($body);

        $this->assertSame('readable', $flattened['message']->getBody());
        $this->assertSame(['text/html'], $flattened['dropped']);
    }

    public function testBlankHtmlPartLosesToRealPlainText(): void
    {
        foreach (['', "  \r\n\t "] as $blank) {
            $flattened = $this->flattened("--b1\r\nContent-Type: text/plain\r\n\r\nreal text\r\n--b1\r\nContent-Type: text/html\r\n\r\n{$blank}\r\n--b1--");

            $this->assertSame('text/plain', $flattened['message']->getContentType());
            $this->assertSame('real text', $flattened['message']->getBody());
            $this->assertSame(['text/html'], $flattened['dropped']);
        }
    }

    public function testAllPartsBlankIsUnflattenable(): void
    {
        $this->assertUnflattenable("--b1\r\nContent-Type: text/plain\r\n\r\n  \r\n--b1\r\nContent-Type: text/html\r\n\r\n\r\n--b1--");
    }

    public function testBlankAfterDecodingIsUnreadable(): void
    {
        $this->assertUnflattenable("--b1\r\nContent-Type: text/html\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode("  \n") . "\r\n--b1--");
    }

    public function testReadsOneLevelOfNesting(): void
    {
        $body = implode("\r\n", [
            '--outer',
            'Content-Type: multipart/alternative;',
            ' boundary="inner"',
            '',
            '--inner',
            'Content-Type: text/plain',
            '',
            'nested plain',
            '--inner',
            'Content-Type: text/html',
            '',
            '<p>nested html</p>',
            '--inner--',
            '--outer',
            'Content-Type: application/pdf',
            'Content-Disposition: attachment; filename="a.pdf"',
            '',
            'binary',
            '--outer--',
        ]);

        $result = $this->flatten($body, 'multipart/mixed; boundary="outer"');

        $this->assertSame('text/html', $result->getContentType());
        $this->assertSame('<p>nested html</p>', $result->getBody());
    }

    public function testReadsMixedRelatedAlternative(): void
    {
        $body = $this->nested(['related', 'alternative'], "Content-Type: text/html\r\n\r\n<p>deep</p>");

        $this->assertSame('<p>deep</p>', $this->flatten($body, 'multipart/mixed; boundary="l0"')->getBody());
    }

    public function testReadsFiveLevelsOfNesting(): void
    {
        $body = $this->nested(['a', 'b', 'c', 'd', 'e'], "Content-Type: text/html\r\n\r\n<p>deep</p>");

        $this->assertSame('<p>deep</p>', $this->flatten($body, 'multipart/mixed; boundary="l0"')->getBody());
    }

    public function testDeeperThanFiveLevelsIsUnflattenableAndReportsTheCutOffPart(): void
    {
        $body = $this->nested(['a', 'b', 'c', 'd', 'e', 'f'], "Content-Type: text/html\r\n\r\n<p>too deep</p>");

        $flattened = (new MultipartBodyReader())->flatten($this->message('multipart/mixed; boundary="l0"', $body));

        $this->assertNull($flattened['message']);
        $this->assertSame(['multipart/f'], $flattened['dropped']);
    }

    public function testCalendarOnlyMultipartIsUnflattenable(): void
    {
        $this->assertUnflattenable("--b1\r\nContent-Type: text/calendar; method=REQUEST\r\n\r\nBEGIN:VCALENDAR\r\n--b1--");
    }

    public function testBodiesWithoutATextPartAreUnflattenable(): void
    {
        foreach (['no delimiters at all', "--other\r\nContent-Type: text/html\r\n\r\n<p>x</p>\r\n--other--", "--b1\r\nContent-Type: text/html", "--b1\r\nContent-Type: image/png\r\n\r\nbytes\r\n--b1--"] as $body) {
            $this->assertUnflattenable($body);
        }
    }

    public function testEveryPartLeftOutIsReportedWithTypeAndFilenameButNeverContents(): void
    {
        $body = implode("\r\n", [
            '--b1',
            'Content-Type: text/plain',
            '',
            'SECRET-PLAIN',
            '--b1',
            'Content-Type: text/html',
            '',
            '<p>chosen</p>',
            '--b1',
            'Content-Type: text/html',
            '',
            'SECRET-LATER',
            '--b1',
            'Content-Type: image/png; name="logo.png"',
            'Content-Disposition: inline',
            'Content-ID: <logo>',
            '',
            'SECRET-IMAGE',
            '--b1',
            'Content-Type: application/pdf',
            'Content-Disposition: attachment; filename="report.pdf"',
            '',
            'SECRET-PDF',
            '--b1',
            'Content-Type: text/calendar; method=REQUEST',
            '',
            'SECRET-CAL',
            '--b1--',
        ]);

        $flattened = $this->flattened($body);

        $this->assertSame('<p>chosen</p>', $flattened['message']->getBody());
        $this->assertSame(['text/plain', 'text/html', 'image/png (logo.png)', 'application/pdf (report.pdf)', 'text/calendar'], $flattened['dropped']);
        $this->assertStringNotContainsString('SECRET', implode(' ', $flattened['dropped']));
    }

    public function testUnsafeFilenameIsLeftOutOfTheReport(): void
    {
        $body = "--b1\r\nContent-Type: text/plain\r\n\r\nx\r\n--b1\r\nContent-Type: image/png\r\nContent-Disposition: attachment; filename=\"a\\\"b\"\r\n\r\ny\r\n--b1--";

        $this->assertSame(['image/png'], $this->flattened($body)['dropped']);
    }

    public function testNothingLeftOutReportsNothing(): void
    {
        $this->assertSame([], $this->flattened("--b1\r\nContent-Type: text/plain\r\n\r\nx\r\n--b1--")['dropped']);
    }

    public function testSkipsTextPartsMarkedAsAttachments(): void
    {
        $body = implode("\r\n", [
            '--b1',
            'Content-Type: text/html',
            'Content-Disposition: attachment; filename="report.html"',
            '',
            '<p>attached report</p>',
            '--b1',
            'Content-Type: text/plain',
            '',
            'the real body',
            '--b1--',
        ]);

        $flattened = $this->flattened($body);

        $this->assertSame('text/plain', $flattened['message']->getContentType());
        $this->assertSame('the real body', $flattened['message']->getBody());
        $this->assertSame(['text/html (report.html)'], $flattened['dropped']);
    }

    public function testBoundaryPrefixOfAnotherBoundaryDoesNotSplit(): void
    {
        $body = implode("\r\n", [
            '--abc',
            'Content-Type: text/plain',
            '',
            'first line',
            '--abcd is not a delimiter',
            '--abc--',
        ]);

        $this->assertSame("first line\r\n--abcd is not a delimiter", $this->flatten($body, 'multipart/mixed; boundary="abc"')->getBody());
    }

    public function testBoundaryWithRegexCharactersIsMatchedLiterally(): void
    {
        foreach (['a+b(c).d/e', 'abc#1', 'a*b', '{abc}', 'a|b', 'a&b'] as $boundary) {
            $body = "--{$boundary}\r\nContent-Type: text/plain\r\n\r\nliteral\r\n--{$boundary}--";

            $this->assertSame('literal', $this->flatten($body, 'multipart/mixed; boundary="' . $boundary . '"')->getBody());
        }
    }

    public function testPreservesEveryOtherFieldOfTheMessage(): void
    {
        $message = MailMessage::fromArray([
            'to'          => ['to@example.com'],
            'subject'     => 'Subject',
            'body'        => "--b1\r\nContent-Type: text/html\r\n\r\n<p>x</p>\r\n--b1--",
            'contentType' => 'multipart/alternative; boundary="b1"',
            'from'        => 'from@example.com',
            'headers'     => ['X-Custom' => 'kept'],
            'metadata'    => ['bit_tracking_id' => 'abc'],
        ]);

        $result = (new MultipartBodyReader())->flatten($message)['message'];

        $this->assertSame('from@example.com', $result->getFrom());
        $this->assertSame(['X-Custom' => 'kept'], $result->getHeaders());
        $this->assertSame(['bit_tracking_id' => 'abc'], $result->getMetadata());
    }

    /**
     * Flatten a multipart message built from the given body, returning the single-part copy.
     */
    private function flatten(string $body, string $contentType = 'multipart/alternative; boundary="b1"'): MailMessage
    {
        return (new MultipartBodyReader())->flatten($this->message($contentType, $body))['message'];
    }

    /**
     * Full flatten result for a body under the default boundary.
     *
     * @return array{message:?MailMessage,dropped:array<int,string>}
     */
    private function flattened(string $body): array
    {
        return (new MultipartBodyReader())->flatten($this->message('multipart/alternative; boundary="b1"', $body));
    }

    /**
     * Assert that a body under the default boundary yields no readable part.
     */
    private function assertUnflattenable(string $body): void
    {
        $this->assertNull($this->flattened($body)['message']);
    }

    /**
     * Single base64 text part holding the diacritics in ISO-8859-2.
     */
    private function latin2Part(): string
    {
        $encoded = base64_encode((string) mb_convert_encoding(self::DIACRITICS, 'ISO-8859-2', 'UTF-8'));

        return "--b1\r\nContent-Type: text/plain; charset=ISO-8859-2\r\nContent-Transfer-Encoding: base64\r\n\r\n{$encoded}\r\n--b1--";
    }

    /**
     * Wrap a leaf part in multipart levels named by subtype, the outermost using boundary "l0".
     *
     * @param array<int,string> $subtypes
     */
    private function nested(array $subtypes, string $leaf): string
    {
        $content = $leaf;
        foreach (array_reverse($subtypes, true) as $depth => $subtype) {
            $inner   = 'l' . ($depth + 1);
            $content = "Content-Type: multipart/{$subtype}; boundary=\"{$inner}\"\r\n\r\n--{$inner}\r\n{$content}\r\n--{$inner}--";
        }

        return "--l0\r\n{$content}\r\n--l0--";
    }

    /**
     * Minimal message with the given content type, body and optional charset.
     */
    private function message(string $contentType, string $body, string $charset = 'UTF-8'): MailMessage
    {
        return MailMessage::fromArray([
            'to'          => ['to@example.com'],
            'subject'     => 'Subject',
            'body'        => $body,
            'contentType' => $contentType,
            'charset'     => $charset,
        ]);
    }
}
