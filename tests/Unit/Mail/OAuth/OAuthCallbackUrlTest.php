<?php

namespace BitApps\SMTP\Tests\Unit\Mail\OAuth;

use BitApps\SMTP\Mail\OAuth\OAuthCallbackUrl;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;

/**
 * @internal
 *
 * @covers \BitApps\SMTP\Mail\OAuth\OAuthCallbackUrl
 */
class OAuthCallbackUrlTest extends BaseUnitTestCase
{
    public function testBuildsRootInstallCallbackUrl(): void
    {
        Functions\when('home_url')->alias(
            static fn (string $path): string => 'https://site.test' . $path
        );

        $this->assertSame(
            'https://site.test/bit-smtp/oauth/callback',
            OAuthCallbackUrl::get()
        );
    }

    public function testPreservesSubdirectoryInstall(): void
    {
        Functions\when('home_url')->alias(
            static fn (string $path): string => 'https://site.test/wordpress' . $path
        );

        $this->assertSame(
            'https://site.test/wordpress/bit-smtp/oauth/callback',
            OAuthCallbackUrl::get()
        );
    }
}
