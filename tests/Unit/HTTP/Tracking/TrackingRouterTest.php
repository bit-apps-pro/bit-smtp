<?php

namespace BitApps\SMTP\Tests\Unit\HTTP\Tracking;

use BitApps\SMTP\HTTP\Tracking\TrackingRouter;
use PHPUnit\Framework\TestCase;

/**
 * Exercises route parser: matches four-segment open/click shape, rejects webhook/OAuth/malformed paths.
 *
 * @internal
 *
 * @coversNothing
 */
final class TrackingRouterTest extends TestCase
{
    public function testMatchesOpenAtRootInstall(): void
    {
        $this->assertSame(
            ['type' => 'open', 'token' => 'abc123.def456'],
            TrackingRouter::parse('/bit-smtp/track/open/abc123.def456', '/')
        );
    }

    public function testMatchesClickAtRootInstall(): void
    {
        $this->assertSame(
            ['type' => 'click', 'token' => 'abc123.def456'],
            TrackingRouter::parse('/bit-smtp/track/click/abc123.def456', '/')
        );
    }

    public function testMatchesInsideASubdirectoryInstall(): void
    {
        $this->assertSame(
            ['type' => 'open', 'token' => 'tok'],
            TrackingRouter::parse('/subdir/bit-smtp/track/open/tok', '/subdir')
        );
    }

    public function testMatchesWithTrailingSlashesAroundSubdirHome(): void
    {
        $this->assertSame(
            ['type' => 'click', 'token' => 'tok'],
            TrackingRouter::parse('/subdir/bit-smtp/track/click/tok/', '/subdir/')
        );
    }

    public function testDoesNotMatchTheTwoSegmentWebhookShape(): void
    {
        $this->assertNull(TrackingRouter::parse('/bit-smtp/conn_x/secret', '/'));
    }

    public function testDoesNotMatchTheThreeSegmentOAuthShape(): void
    {
        $this->assertNull(TrackingRouter::parse('/bit-smtp/oauth/callback', '/'));
    }

    public function testRejectsAnUnknownVerbSegment(): void
    {
        $this->assertNull(TrackingRouter::parse('/bit-smtp/track/click-through/tok', '/'));
        $this->assertNull(TrackingRouter::parse('/bit-smtp/track/foo/tok', '/'));
    }

    public function testRejectsAMissingToken(): void
    {
        $this->assertNull(TrackingRouter::parse('/bit-smtp/track/open/', '/'));
        $this->assertNull(TrackingRouter::parse('/bit-smtp/track/open', '/'));
    }

    public function testRejectsExtraSegmentsAfterTheToken(): void
    {
        $this->assertNull(TrackingRouter::parse('/bit-smtp/track/open/tok/extra', '/'));
    }

    public function testRejectsAForeignPrefix(): void
    {
        $this->assertNull(TrackingRouter::parse('/other/track/open/tok', '/'));
    }

    public function testRejectsANullPath(): void
    {
        $this->assertNull(TrackingRouter::parse(null, '/'));
    }
}
