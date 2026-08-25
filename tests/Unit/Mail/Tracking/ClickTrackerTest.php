<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Tracking;

use BitApps\SMTP\Mail\Tracking\ClickTracker;
use BitApps\SMTP\Mail\Tracking\TokenSigner;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;

/**
 * @internal
 *
 * @coversNothing
 */
final class ClickTrackerTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_salt')->justReturn('unit-test-tracking-salt');
    }

    public function testDestinationDecodesHtmlEntitiesInTheCapturedUrl(): void
    {
        // The href was captured from HTML, so `&amp;` must be decoded back to `&` before redirecting.
        $token = TokenSigner::sign(['t' => 'uuid', 'u' => 'https://shop.example/o?a=1&amp;b=2']);

        self::assertSame('https://shop.example/o?a=1&b=2', ClickTracker::destination($token));
    }

    public function testDestinationReturnsNullForATamperedToken(): void
    {
        $token = TokenSigner::sign(['t' => 'uuid', 'u' => 'https://shop.example/o']);

        self::assertNull(ClickTracker::destination($token . 'x'), 'a tampered token must never yield a redirect');
    }

    public function testDestinationRejectsANonHttpUrl(): void
    {
        $token = TokenSigner::sign(['t' => 'uuid', 'u' => 'javascript:alert(1)']);

        self::assertNull(ClickTracker::destination($token), 'only http(s) destinations may be redirected to');
    }
}
