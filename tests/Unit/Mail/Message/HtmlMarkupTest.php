<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Message;

use BitApps\SMTP\Mail\Message\HtmlMarkup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers telling real HTML tags from text that only contains angle brackets.
 *
 * @internal
 *
 * @coversNothing
 */
class HtmlMarkupTest extends TestCase
{
    /**
     * @return array<string,array{0:string}>
     */
    public static function markup(): array
    {
        return [
            'paragraph'  => ['<p>Hi</p>'],
            'closing'    => ['text</div>'],
            'self close' => ['line<br/>next'],
            'upper case' => ['<TABLE>'],
            'link'       => ['<a href="http://example.org">x</a>'],
            'single'     => ["<img src='a.png' alt='b'>"],
            'unquoted'   => ['<td width=10>'],
        ];
    }

    #[DataProvider('markup')]
    public function testFindsRealTags(string $text): void
    {
        $this->assertTrue(HtmlMarkup::isPresentIn($text));
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function notMarkup(): array
    {
        return [
            'no brackets'  => ['plain words'],
            'comparison'   => ['if a<b and c>d'],
            'across lines' => ["a <b\nc=\"d\"> e"],
            'lone bracket' => ['a < b'],
            'email'        => ['John <john@example.org>'],
            'url'          => ['see <https://example.org/x>'],
        ];
    }

    #[DataProvider('notMarkup')]
    public function testIgnoresTextThatOnlyLooksLikeTags(string $text): void
    {
        $this->assertFalse(HtmlMarkup::isPresentIn($text));
    }
}
