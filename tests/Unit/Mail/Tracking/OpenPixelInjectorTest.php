<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Tracking;

use BitApps\SMTP\Mail\Tracking\OpenPixelInjector;
use BitApps\SMTP\Mail\Tracking\TokenSigner;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;

/**
 * @internal
 *
 * @coversNothing
 */
final class OpenPixelInjectorTest extends BaseUnitTestCase
{
    private const OPEN_BASE = 'https://site.test/bit-smtp/track/open/';

    private const TOKEN = 'msg-token-uuid';

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_salt')->justReturn('unit-test-tracking-salt');
    }

    public function testInjectsPixelImmediatelyBeforeClosingBody(): void
    {
        $out = $this->injector()->inject('<html><body><p>Hi</p></body></html>', self::TOKEN);

        $this->assertStringContainsString('<img src="' . self::OPEN_BASE, $out);
        $this->assertStringContainsString('width="1" height="1" alt="" style="display:none" />', $out);
        // The pixel sits directly before </body> so it stays inside the rendered document.
        $this->assertStringContainsString('/></body></html>', $out);
    }

    public function testMatchesClosingBodyCaseInsensitively(): void
    {
        $out = $this->injector()->inject('<BODY><p>Hi</p></BODY>', self::TOKEN);

        $this->assertStringContainsString('/></BODY>', $out);
    }

    public function testAppendsPixelAtEndWhenNoClosingBody(): void
    {
        $out = $this->injector()->inject('<p>Hi</p>', self::TOKEN);

        $this->assertStringStartsWith('<p>Hi</p><img src="' . self::OPEN_BASE, $out);
        $this->assertStringEndsWith('/>', $out);
    }

    public function testPixelSrcCarriesASignedTokenThatVerifies(): void
    {
        $out = $this->injector()->inject('<body></body>', self::TOKEN);

        preg_match('#' . preg_quote(self::OPEN_BASE, '#') . '([A-Za-z0-9\-_]+\.[a-f0-9]{64})#', $out, $matches);
        $this->assertNotEmpty($matches, 'the pixel must carry a signed open token');
        $this->assertSame(['t' => self::TOKEN], TokenSigner::verify($matches[1]));
    }

    private function injector(): OpenPixelInjector
    {
        return new OpenPixelInjector(self::OPEN_BASE);
    }
}
