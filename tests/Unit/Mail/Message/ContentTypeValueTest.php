<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Message;

use BitApps\SMTP\Mail\Message\ContentTypeValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers Content-Type parsing and the header-safety validation of everything it re-emits.
 *
 * @internal
 *
 * @coversNothing
 */
class ContentTypeValueTest extends TestCase
{
    public function testParsesBareMediaType(): void
    {
        $value = ContentTypeValue::parse('text/html');

        $this->assertSame('text/html', $value->mediaType());
        $this->assertNull($value->charset());
        $this->assertNull($value->boundary());
        $this->assertTrue($value->isHtml());
        $this->assertFalse($value->isMultipart());
    }

    public function testLowerCasesMediaTypeAndKeepsCharsetAsWritten(): void
    {
        $value = ContentTypeValue::parse('TEXT/HTML; CHARSET="iso-8859-2"');

        $this->assertSame('text/html', $value->mediaType());
        $this->assertSame('iso-8859-2', $value->charset());
        $this->assertSame('text/html', $value->headerValue());
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function combinedParams(): array
    {
        return [
            'charset first'      => ['multipart/mixed; charset=UTF-8; boundary="b-1"'],
            'boundary first'     => ['multipart/mixed; boundary="b-1"; charset=UTF-8'],
            'unquoted upper'     => ['Multipart/Mixed; CHARSET=UTF-8; BOUNDARY=b-1'],
            'folded with CRLF'   => ["multipart/mixed;\r\n charset=UTF-8;\r\n boundary=\"b-1\""],
            'single quotes'      => ["multipart/mixed; charset='UTF-8'; boundary='b-1'"],
            'doubled semicolons' => ['multipart/mixed;; charset=UTF-8;; boundary=b-1;'],
        ];
    }

    #[DataProvider('combinedParams')]
    public function testReadsCharsetAndBoundaryInEitherOrder(string $header): void
    {
        $value = ContentTypeValue::parse($header);

        $this->assertSame('multipart/mixed', $value->mediaType());
        $this->assertSame('UTF-8', $value->charset());
        $this->assertSame('b-1', $value->boundary());
        $this->assertTrue($value->isMultipart());
        $this->assertSame('multipart/mixed; boundary="b-1"', $value->headerValue());
    }

    public function testHeaderValueKeepsOtherParamsForOtherTypes(): void
    {
        $this->assertSame(
            'text/calendar; method=REQUEST',
            ContentTypeValue::parse('text/calendar; charset=UTF-8; method=REQUEST')->headerValue()
        );
        $this->assertSame(
            'multipart/related; type="text/html"; boundary="b1"',
            ContentTypeValue::parse('multipart/related; type="text/html"; boundary=b1')->headerValue()
        );
    }

    public function testHeaderValueDropsParamsForHtmlAndPlain(): void
    {
        $this->assertSame('text/plain', ContentTypeValue::parse('text/plain; format=flowed')->headerValue());
        $this->assertSame('text/html', ContentTypeValue::parse('text/html; charset=UTF-8')->headerValue());
    }

    public function testKeepsBoundaryCaseAndRfc2046Punctuation(): void
    {
        $boundary = "Ab0'()+_,-./:=? z";

        $this->assertSame($boundary, ContentTypeValue::parse('multipart/mixed; boundary="' . $boundary . '"')->boundary());
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function malformedMediaTypes(): array
    {
        return [
            'empty'           => [''],
            'no slash'        => ['html'],
            'two slashes'     => ['text/html/extra'],
            'space inside'    => ['text /html'],
            'crlf injection'  => ["text/html\r\nBcc: evil@evil.test"],
            'lf injection'    => ["text/html\nBcc: evil@evil.test"],
            'trailing header' => ["text/html\r\n\r\n<script>"],
        ];
    }

    #[DataProvider('malformedMediaTypes')]
    public function testMalformedMediaTypeFallsBackToPlainText(string $header): void
    {
        $value = ContentTypeValue::parse($header);

        $this->assertSame('text/plain', $value->mediaType());
        $this->assertSame('text/plain', $value->headerValue());
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function unsafeCharsets(): array
    {
        return [
            'crlf'     => ["text/html; charset=\"UTF-8\r\nBcc: evil@evil.test\""],
            'spaces'   => ['text/html; charset="UTF 8"'],
            'injected' => ['text/html; charset="UTF-8>"'],
            'empty'    => ['text/html; charset=""'],
        ];
    }

    #[DataProvider('unsafeCharsets')]
    public function testUnsafeCharsetIsDropped(string $header): void
    {
        $this->assertNull(ContentTypeValue::parse($header)->charset());
    }

    public function testCharsetValidationMatchesTheTokenPattern(): void
    {
        $this->assertTrue(ContentTypeValue::isValidCharset('ISO-8859-2'));
        $this->assertTrue(ContentTypeValue::isValidCharset('x.foo:bar+baz_1'));
        $this->assertFalse(ContentTypeValue::isValidCharset(''));
        $this->assertFalse(ContentTypeValue::isValidCharset("UTF-8\n"));
        $this->assertFalse(ContentTypeValue::isValidCharset('UTF-8; x=y'));
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function unsafeBoundaries(): array
    {
        return [
            'crlf'          => ["multipart/mixed; boundary=\"x\r\nBcc: evil@evil.test\""],
            'lf'            => ["multipart/mixed; boundary=\"x\nBcc: evil@evil.test\""],
            'quote'         => ['multipart/mixed; boundary="a\"b"'],
            'backslash'     => ['multipart/mixed; boundary="a\\b"'],
            'empty'         => ['multipart/mixed; boundary=""'],
            'missing'       => ['multipart/mixed'],
        ];
    }

    #[DataProvider('unsafeBoundaries')]
    public function testMultipartWithoutSafeBoundaryFallsBackToPlainText(string $header): void
    {
        $value = ContentTypeValue::parse($header);

        $this->assertSame('text/plain', $value->mediaType());
        $this->assertNull($value->boundary());
        $this->assertSame('text/plain', $value->headerValue());
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function boundariesOutsideRfc2046(): array
    {
        return [
            'hash'     => ['abc#1'],
            'asterisk' => ['a*b'],
            'braces'   => ['{abc}'],
            'pipe'     => ['a|b'],
            'amp'      => ['a&b'],
            'angle'    => ['a<b>'],
            '71 chars' => [str_repeat('a', 71)],
        ];
    }

    #[DataProvider('boundariesOutsideRfc2046')]
    public function testBoundaryOutsideRfc2046ButSafeForTheHeaderIsKeptQuoted(string $boundary): void
    {
        $value = ContentTypeValue::parse('multipart/mixed; boundary=' . $boundary);

        $this->assertSame('multipart/mixed', $value->mediaType());
        $this->assertSame($boundary, $value->boundary());
        $this->assertSame('multipart/mixed; boundary="' . $boundary . '"', $value->headerValue());
    }

    public function testParamExposesSafeParametersOnly(): void
    {
        $value = ContentTypeValue::parse("attachment; filename=\"a.pdf\"; evil=\"x\r\ny\"");

        $this->assertSame('a.pdf', $value->param('filename'));
        $this->assertNull($value->param('evil'));
        $this->assertNull($value->param('missing'));
    }

    public function testWhitespaceOutsideQuotesIsTrimmedAndCrlfInsideIsUnsafe(): void
    {
        $this->assertSame('x', ContentTypeValue::parse("multipart/mixed; boundary= x \r\n")->boundary());
        $this->assertNull(ContentTypeValue::parse("multipart/mixed; boundary=\"x\r\n\"")->boundary());
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function quotedBoundaries(): array
    {
        return [
            'trailing apostrophe in double quotes' => ['"abc\'"', "abc'"],
            'leading apostrophe in double quotes'  => ['"\'abc"', "'abc"],
            'wrapped in both'                      => ['"\'abc\'"', "'abc'"],
            'trailing apostrophe unquoted'         => ["abc'", "abc'"],
            'matched single quotes'                => ["'abc'", 'abc'],
            'matched double quotes'                => ['"abc"', 'abc'],
        ];
    }

    #[DataProvider('quotedBoundaries')]
    public function testOnlyOneMatchedPairOfQuotesIsStripped(string $written, string $expected): void
    {
        $this->assertSame($expected, ContentTypeValue::parse('multipart/mixed; boundary=' . $written)->boundary());
    }

    public function testBareMultipartReadsAsPlainButReportsItsDeclaredType(): void
    {
        $value = ContentTypeValue::parse('Multipart/Alternative');

        $this->assertTrue($value->isBareMultipart());
        $this->assertSame('text/plain', $value->mediaType());
        $this->assertSame('text/plain', $value->headerValue());
        $this->assertFalse($value->isMultipart());
        $this->assertSame('multipart/alternative', $value->filterValue());
    }

    public function testFilterValueEqualsHeaderValueForEverythingElse(): void
    {
        foreach (['text/html; charset=UTF-8', 'multipart/mixed; boundary=x', 'text/calendar; method=REQUEST', "text/html\r\nBcc: x"] as $raw) {
            $value = ContentTypeValue::parse($raw);

            $this->assertFalse($value->isBareMultipart());
            $this->assertSame($value->headerValue(), $value->filterValue());
        }
    }

    public function testMultipartWithUnsafeBoundaryCountsAsBareAndStaysSafe(): void
    {
        $value = ContentTypeValue::parse("multipart/mixed; boundary=\"x\r\nBcc: evil@evil.test\"");

        $this->assertTrue($value->isBareMultipart());
        $this->assertSame('multipart/mixed', $value->filterValue());
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function unsafeParams(): array
    {
        return [
            'crlf in value'   => ["text/calendar; method=\"REQUEST\r\nBcc: evil@evil.test\""],
            'bare crlf value' => ["text/calendar; method=REQUEST\r\nBcc: evil@evil.test"],
            'crlf new header' => ["text/calendar;\r\nBcc: evil=evil@evil.test"],
            'quote in value'  => ['text/calendar; method="a\"b"'],
        ];
    }

    #[DataProvider('unsafeParams')]
    public function testUnsafeParamsNeverReachTheHeaderValue(string $header): void
    {
        $headerValue = ContentTypeValue::parse($header)->headerValue();

        $this->assertSame(0, preg_match('/[\r\n]/', $headerValue));
        $this->assertStringNotContainsString('Bcc:', $headerValue);
    }

    public function testParamValueNeedingQuotesIsQuotedOnOutput(): void
    {
        $this->assertSame(
            'application/x-thing; name="a b"',
            ContentTypeValue::parse('application/x-thing; name="a b"')->headerValue()
        );
    }
}
