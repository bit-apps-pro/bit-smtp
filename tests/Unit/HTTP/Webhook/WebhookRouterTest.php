<?php

namespace BitApps\SMTP\Tests\Unit\HTTP\Webhook;

use BitApps\SMTP\HTTP\Webhook\WebhookRouter;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the pure route parser: id/secret extraction, subdirectory home-path stripping, and the
 * segment-count / prefix rejections. No WordPress runtime required.
 *
 * @internal
 *
 * @coversNothing
 */
final class WebhookRouterTest extends TestCase
{
    public function testMatchesRootInstallPath(): void
    {
        $this->assertSame(
            ['id' => 'conn_x', 'secret' => 'sek'],
            WebhookRouter::parse('/bit-smtp/conn_x/sek', '/')
        );
    }

    public function testMatchesSubdirectoryInstallPath(): void
    {
        $this->assertSame(
            ['id' => 'conn_x', 'secret' => 'sek'],
            WebhookRouter::parse('/subdir/bit-smtp/conn_x/sek', '/subdir')
        );
    }

    public function testMatchesWithTrailingSlashesAroundSubdirHome(): void
    {
        $this->assertSame(
            ['id' => 'conn_x', 'secret' => 'sek'],
            WebhookRouter::parse('/subdir/bit-smtp/conn_x/sek/', '/subdir/')
        );
    }

    public function testReturnsNullWhenSegmentsAreMissing(): void
    {
        $this->assertNull(WebhookRouter::parse('/bit-smtp/', '/'));
    }

    public function testReturnsNullWhenOnlyOneSegmentIsPresent(): void
    {
        $this->assertNull(WebhookRouter::parse('/bit-smtp/only-one', '/'));
    }

    public function testReturnsNullWhenTooManySegments(): void
    {
        $this->assertNull(WebhookRouter::parse('/bit-smtp/a/b/c', '/'));
    }

    public function testReturnsNullForForeignPrefix(): void
    {
        $this->assertNull(WebhookRouter::parse('/other/a/b', '/'));
    }

    public function testReturnsNullForNullPath(): void
    {
        $this->assertNull(WebhookRouter::parse(null, '/'));
    }
}
