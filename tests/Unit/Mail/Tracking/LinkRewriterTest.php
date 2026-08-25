<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Tracking;

use BitApps\SMTP\Mail\Tracking\LinkRewriter;
use BitApps\SMTP\Mail\Tracking\TokenSigner;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 *
 * @coversNothing
 */
final class LinkRewriterTest extends BaseUnitTestCase
{
    private const CLICK_BASE = 'https://site.test/bit-smtp/track/click/';

    private const TOKEN = 'msg-token-uuid';

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_salt')->justReturn('unit-test-tracking-salt');
    }

    public function testRewritesAbsoluteHttpAndHttpsLinks(): void
    {
        $html = '<a href="http://plain.example/a">a</a> <a href="https://secure.example/b?x=1">b</a>';

        $out = $this->rewriter()->rewrite($html, self::TOKEN);

        $tokens = $this->extractClickTokens($out);
        $this->assertCount(2, $tokens, 'both http and https links must be rewritten');

        $this->assertSame(['t' => self::TOKEN, 'u' => 'http://plain.example/a'], TokenSigner::verify($tokens[0]));
        $this->assertSame(['t' => self::TOKEN, 'u' => 'https://secure.example/b?x=1'], TokenSigner::verify($tokens[1]));
    }

    public function testPreservesSurroundingMarkupAndAnchorText(): void
    {
        $html = '<p>Hi <a class="btn" href="https://secure.example/x">click</a> now</p>';

        $out = $this->rewriter()->rewrite($html, self::TOKEN);

        $this->assertStringContainsString('<p>Hi <a class="btn" href="' . self::CLICK_BASE, $out);
        $this->assertStringContainsString('">click</a> now</p>', $out);
    }

    #[DataProvider('nonRewritableHrefs')]
    public function testSkipsNonHttpAbsoluteLinks(string $href): void
    {
        $html = '<a href="' . $href . '">x</a>';

        $this->assertSame($html, $this->rewriter()->rewrite($html, self::TOKEN));
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function nonRewritableHrefs(): array
    {
        return [
            'mailto'            => ['mailto:person@example.com'],
            'tel'               => ['tel:+15551234567'],
            'fragment'          => ['#section'],
            'root-relative'     => ['/orders/42'],
            'relative'          => ['orders/42'],
            'protocol-relative' => ['//cdn.example.com/asset.png'],
            'javascript'        => ['javascript:alert(1)'],
        ];
    }

    public function testLeavesOverLengthUrlsUntouched(): void
    {
        $longUrl = 'https://example.com/?q=' . str_repeat('a', 2100);
        $html    = '<a href="' . $longUrl . '">x</a>';

        $this->assertSame($html, $this->rewriter()->rewrite($html, self::TOKEN));
    }

    public function testDoesNotRewriteLinksAlreadyPointingAtOurTrackPath(): void
    {
        $html = '<a href="' . self::CLICK_BASE . 'already.signed">x</a>';

        $this->assertSame($html, $this->rewriter()->rewrite($html, self::TOKEN));
    }

    public function testIsIdempotentAcrossASecondPass(): void
    {
        $rewriter = $this->rewriter();
        $once     = $rewriter->rewrite('<a href="https://secure.example/x">x</a>', self::TOKEN);

        $this->assertSame($once, $rewriter->rewrite($once, self::TOKEN), 'a second pass must not re-wrap an already-tracked link');
    }

    public function testRewritesTheRealHrefNotADataHrefAttribute(): void
    {
        $html      = '<a data-href="http://decoy.example/x" href="http://real.example/y">link</a>';
        $rewritten = $this->rewriter()->rewrite($html, self::TOKEN);

        // data-href is left intact; only the real href becomes a signed click URL.
        $this->assertStringContainsString('data-href="http://decoy.example/x"', $rewritten);
        $tokens = $this->extractClickTokens($rewritten);
        $this->assertCount(1, $tokens);
        $this->assertSame(['t' => self::TOKEN, 'u' => 'http://real.example/y'], TokenSigner::verify($tokens[0]));
    }

    private function rewriter(): LinkRewriter
    {
        return new LinkRewriter(self::CLICK_BASE);
    }

    /**
     * Pull every signed click token out of rewritten HTML, in document order.
     *
     * @return string[]
     */
    private function extractClickTokens(string $html): array
    {
        preg_match_all('#' . preg_quote(self::CLICK_BASE, '#') . '([A-Za-z0-9\-_]+\.[a-f0-9]{64})#', $html, $matches);

        return $matches[1];
    }
}
