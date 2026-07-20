<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Support;

use BitApps\SMTP\Mail\Support\ApiRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class ApiRequestTest extends BaseUnitTestCase
{
    public function testConstructSeedsPropsAndContentTypeHeader(): void
    {
        $request = new ApiRequest('POST', 'https://api.example.com/send', '{"a":1}', 'application/json');

        $this->assertSame('POST', $request->method);
        $this->assertSame('https://api.example.com/send', $request->url);
        $this->assertSame('{"a":1}', $request->body);
        $this->assertSame('application/json', $request->contentType);
        $this->assertSame(['Content-Type' => 'application/json'], $request->headers);
    }

    public function testSetHeaderAddsHeaderWithoutDisturbingExisting(): void
    {
        $request = new ApiRequest('POST', 'https://api.example.com/send', '{"a":1}', 'application/json');

        $request->setHeader('Authorization', 'Bearer x');

        $this->assertSame(
            ['Content-Type' => 'application/json', 'Authorization' => 'Bearer x'],
            $request->headers
        );
    }

    public function testSetHeaderOverwritesExistingHeader(): void
    {
        $request = new ApiRequest('POST', 'https://api.example.com/send', '{"a":1}', 'application/json');

        $request->setHeader('Content-Type', 'text/plain');

        $this->assertSame(['Content-Type' => 'text/plain'], $request->headers);
    }
}
