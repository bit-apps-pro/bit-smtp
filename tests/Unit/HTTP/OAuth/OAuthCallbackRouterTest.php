<?php

namespace BitApps\SMTP\Tests\Unit\HTTP\OAuth;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Router\StaticRouter;
use BitApps\SMTP\HTTP\OAuth\OAuthCallbackRouter;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Closure;

/**
 * @internal
 *
 * @coversNothing
 */
final class OAuthCallbackRouterTest extends BaseUnitTestCase
{
    public function testPathOnlyUriRemovesTheQueryString(): void
    {
        $this->assertAdapterExists();

        $this->assertSame(
            '/bit-smtp/oauth/callback',
            OAuthCallbackRouter::pathOnlyUri('/bit-smtp/oauth/callback?code=abc&state=xyz')
        );
    }

    public function testPathOnlyUriPreservesASubdirectoryPath(): void
    {
        $this->assertAdapterExists();

        $this->assertSame(
            '/wordpress/bit-smtp/oauth/callback',
            OAuthCallbackRouter::pathOnlyUri('/wordpress/bit-smtp/oauth/callback?error=access_denied')
        );
    }

    public function testPathOnlyUriReturnsEmptyStringForMissingRequestUri(): void
    {
        $this->assertAdapterExists();

        $this->assertSame('', OAuthCallbackRouter::pathOnlyUri(null));
    }

    public function testDispatchTemporarilyExposesOnlyThePathToTheStaticRouter(): void
    {
        $this->assertAdapterExists();
        Functions\when('home_url')->justReturn('https://example.test/');

        $originalServer  = $_SERVER;
        $originalGet     = $_GET;
        $originalRequest = $_REQUEST;

        $_SERVER['REQUEST_URI'] = '/bit-smtp/oauth/callback?code=abc&state=xyz';
        $_GET                   = ['code' => 'abc', 'state' => 'xyz'];
        $_REQUEST               = ['code' => 'abc', 'state' => 'xyz'];

        $staticRouter = new class(function (): void {
            self::assertSame('/bit-smtp/oauth/callback', $_SERVER['REQUEST_URI']);
            self::assertSame('abc', $_GET['code']);
            self::assertSame('xyz', $_GET['state']);
            self::assertSame('abc', $_REQUEST['code']);
            self::assertSame('xyz', $_REQUEST['state']);
        }) extends StaticRouter {
            private Closure $assertRequest;

            public function __construct(Closure $assertRequest)
            {
                $this->assertRequest = $assertRequest;
            }

            public function handleRequest()
            {
                ($this->assertRequest)();
            }
        };

        try {
            (new OAuthCallbackRouter($staticRouter))->dispatch();

            $this->assertSame(
                '/bit-smtp/oauth/callback?code=abc&state=xyz',
                $_SERVER['REQUEST_URI']
            );
        } finally {
            $_SERVER  = $originalServer;
            $_GET     = $originalGet;
            $_REQUEST = $originalRequest;
        }
    }

    public function testDispatchNormalizesASubdirectoryInstallPathForTheStaticRouter(): void
    {
        $this->assertAdapterExists();
        Functions\when('home_url')->justReturn('https://example.test/wordpress/');

        $originalServer  = $_SERVER;
        $originalGet     = $_GET;
        $originalRequest = $_REQUEST;

        $_SERVER['REQUEST_URI'] = '/wordpress/bit-smtp/oauth/callback?code=abc&state=xyz';
        $_GET                   = ['code' => 'abc', 'state' => 'xyz'];
        $_REQUEST               = ['code' => 'abc', 'state' => 'xyz'];

        $staticRouter = new class(function (): void {
            self::assertSame('/bit-smtp/oauth/callback', $_SERVER['REQUEST_URI']);
            self::assertSame('abc', $_GET['code']);
            self::assertSame('xyz', $_GET['state']);
            self::assertSame('abc', $_REQUEST['code']);
            self::assertSame('xyz', $_REQUEST['state']);
        }) extends StaticRouter {
            private Closure $assertRequest;

            public function __construct(Closure $assertRequest)
            {
                $this->assertRequest = $assertRequest;
            }

            public function handleRequest()
            {
                ($this->assertRequest)();
            }
        };

        try {
            (new OAuthCallbackRouter($staticRouter))->dispatch();

            $this->assertSame(
                '/wordpress/bit-smtp/oauth/callback?code=abc&state=xyz',
                $_SERVER['REQUEST_URI']
            );
        } finally {
            $_SERVER  = $originalServer;
            $_GET     = $originalGet;
            $_REQUEST = $originalRequest;
        }
    }

    private function assertAdapterExists(): void
    {
        $this->assertTrue(
            class_exists(OAuthCallbackRouter::class),
            'OAuthCallbackRouter must be implemented.'
        );
    }
}
