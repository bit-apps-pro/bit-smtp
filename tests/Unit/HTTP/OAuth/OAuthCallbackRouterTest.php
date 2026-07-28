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
        Functions\when('status_header')->justReturn();

        $originalServer  = $_SERVER;
        $originalGet     = $_GET;
        $originalRequest = $_REQUEST;

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/bit-smtp/oauth/callback?code=abc&state=xyz';
        $_GET                      = ['code' => 'abc', 'state' => 'xyz'];
        $_REQUEST                  = ['code' => 'abc', 'state' => 'xyz'];

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
        Functions\when('status_header')->justReturn();

        $originalServer  = $_SERVER;
        $originalGet     = $_GET;
        $originalRequest = $_REQUEST;

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/wordpress/bit-smtp/oauth/callback?error=access_denied&state=xyz';
        $_GET                      = ['error' => 'access_denied', 'state' => 'xyz'];
        $_REQUEST                  = ['error' => 'access_denied', 'state' => 'xyz'];

        $staticRouter = new class(function (): void {
            self::assertSame('/bit-smtp/oauth/callback', $_SERVER['REQUEST_URI']);
            self::assertSame('access_denied', $_GET['error']);
            self::assertSame('xyz', $_GET['state']);
            self::assertSame('access_denied', $_REQUEST['error']);
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
                '/wordpress/bit-smtp/oauth/callback?error=access_denied&state=xyz',
                $_SERVER['REQUEST_URI']
            );
        } finally {
            $_SERVER  = $originalServer;
            $_GET     = $originalGet;
            $_REQUEST = $originalRequest;
        }
    }

    public function testDispatchRejectsPostOnExactCallbackWithoutCallingStaticRouter(): void
    {
        Functions\when('home_url')->justReturn('https://example.test/');
        Functions\expect('status_header')->once()->with(405);

        $originalServer            = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/bit-smtp/oauth/callback?code=abc&state=xyz';
        $staticRouter              = new OAuthCallbackStaticRouterSpy();

        try {
            (new OAuthCallbackRouter($staticRouter))->dispatch();

            $this->assertSame(0, $staticRouter->handleRequestCalls);
            $this->assertSame(
                '/bit-smtp/oauth/callback?code=abc&state=xyz',
                $_SERVER['REQUEST_URI']
            );
        } finally {
            $_SERVER = $originalServer;
        }
    }

    public function testDispatchSetsStatus200BeforeHandlingMatchedGet(): void
    {
        Functions\when('home_url')->justReturn('https://example.test/');

        $status = null;
        Functions\when('status_header')->alias(static function (int $code) use (&$status): void {
            $status = $code;
        });

        $originalServer            = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/bit-smtp/oauth/callback/?code=abc&state=xyz';
        $staticRouter              = new OAuthCallbackStaticRouterSpy(static function () use (&$status): void {
            self::assertSame(200, $status);
        });

        try {
            (new OAuthCallbackRouter($staticRouter))->dispatch();

            $this->assertSame(1, $staticRouter->handleRequestCalls);
            $this->assertSame(
                '/bit-smtp/oauth/callback/?code=abc&state=xyz',
                $_SERVER['REQUEST_URI']
            );
        } finally {
            $_SERVER = $originalServer;
        }
    }

    public function testDispatchIgnoresUnrelatedPathsWithoutChangingStatusOrCallingStaticRouter(): void
    {
        Functions\when('home_url')->justReturn('https://example.test/');
        Functions\expect('status_header')->never();

        $originalServer            = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/bit-smtp/oauth/not-callback';
        $staticRouter              = new OAuthCallbackStaticRouterSpy();

        try {
            (new OAuthCallbackRouter($staticRouter))->dispatch();

            $this->assertSame(0, $staticRouter->handleRequestCalls);
            $this->assertSame('/bit-smtp/oauth/not-callback', $_SERVER['REQUEST_URI']);
        } finally {
            $_SERVER = $originalServer;
        }
    }

    public function testRegisterRepairsLifecycleHooksAndAttachesDispatch(): void
    {
        $staticRouter = new OAuthCallbackStaticRouterSpy();
        $adapter      = new OAuthCallbackRouter($staticRouter);

        Functions\expect('remove_action')
            ->once()
            ->with('bit_smtp_activate', [$staticRouter, 'flushOnDeactivate'], 10);
        Functions\expect('remove_action')
            ->once()
            ->with('bit_smtp_deactivate', [$staticRouter, 'flushOnActivate'], 10);
        Functions\expect('remove_action')
            ->once()
            ->with('template_redirect', [$staticRouter, 'handleRequest'], 10);
        Functions\expect('add_action')
            ->once()
            ->with('bit_smtp_activate', [$staticRouter, 'flushOnActivate'], 10);
        Functions\expect('add_action')
            ->once()
            ->with('bit_smtp_deactivate', [$staticRouter, 'flushOnDeactivate'], 10);
        Functions\expect('add_action')
            ->once()
            ->with('template_redirect', [$adapter, 'dispatch'], 10);

        $adapter->register();
    }

    private function assertAdapterExists(): void
    {
        $this->assertTrue(
            class_exists(OAuthCallbackRouter::class),
            'OAuthCallbackRouter must be implemented.'
        );
    }
}

final class OAuthCallbackStaticRouterSpy extends StaticRouter
{
    public int $handleRequestCalls = 0;

    private ?Closure $onHandleRequest;

    public function __construct(?Closure $onHandleRequest = null)
    {
        $this->onHandleRequest = $onHandleRequest;
    }

    public function handleRequest()
    {
        ++$this->handleRequestCalls;

        if ($this->onHandleRequest !== null) {
            ($this->onHandleRequest)();
        }
    }
}
