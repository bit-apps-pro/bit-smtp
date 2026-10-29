<?php

namespace BitApps\SMTP\Tests\Unit\HTTP;

use BitApps\SMTP\HTTP\ServerRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class ServerRequestTest extends BaseUnitTestCase
{
    private array $originalServer;

    /**
     * Snapshot $_SERVER so each test can mutate it freely.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer = $_SERVER;
    }

    /**
     * Restore the $_SERVER snapshot.
     */
    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        parent::tearDown();
    }

    /**
     * The path omits the query string and keeps encoded octets intact.
     */
    public function testPathDropsTheQueryAndKeepsPercentEncoding(): void
    {
        $_SERVER['REQUEST_URI'] = '/bit-smtp/track/click/a%2Fb.sig?x=1';

        $this->assertSame('/bit-smtp/track/click/a%2Fb.sig', ServerRequest::path());
    }

    /**
     * A missing REQUEST_URI yields an empty path.
     */
    public function testPathIsEmptyWhenTheRequestUriIsAbsent(): void
    {
        unset($_SERVER['REQUEST_URI']);

        $this->assertSame('', ServerRequest::path());
    }

    /**
     * The method is normalised to upper case.
     */
    public function testMethodIsUpperCased(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'post';

        $this->assertSame('POST', ServerRequest::method());
    }

    /**
     * Content-Length is read as a non-negative integer.
     */
    public function testContentLengthIsANonNegativeInteger(): void
    {
        $_SERVER['CONTENT_LENGTH'] = '2048';
        $this->assertSame(2048, ServerRequest::contentLength());

        $_SERVER['CONTENT_LENGTH'] = 'junk';
        $this->assertSame(0, ServerRequest::contentLength());
    }

    /**
     * Only a valid REMOTE_ADDR is returned as the client IP.
     */
    public function testClientIpRejectsAnInvalidRemoteAddress(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $this->assertSame('203.0.113.7', ServerRequest::clientIp());

        $_SERVER['REMOTE_ADDR'] = 'not-an-ip';
        $this->assertNull(ServerRequest::clientIp());
    }

    /**
     * Header values are unslashed plain text, or empty when absent.
     */
    public function testValueIsUnslashedTextOrEmpty(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = "Mozilla\\'s <b>Agent</b>";
        $this->assertSame("Mozilla's Agent", ServerRequest::userAgent());

        unset($_SERVER['HTTP_X_MISSING']);
        $this->assertSame('', ServerRequest::value('HTTP_X_MISSING'));
    }
}
