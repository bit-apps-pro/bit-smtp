<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Webhook;

use BitApps\SMTP\Mail\Webhook\WebhookRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class WebhookRequestTest extends BaseUnitTestCase
{
    public function testRawBodyIsPreserved(): void
    {
        $request = WebhookRequest::fromRaw('{"a":1}', []);

        $this->assertSame('{"a":1}', $request->rawBody());
    }

    public function testDecodedReturnsAssociativeArray(): void
    {
        $request = WebhookRequest::fromRaw('{"event":"delivered","email":"a@b.com"}', []);

        $this->assertSame(['event' => 'delivered', 'email' => 'a@b.com'], $request->decoded());
    }

    public function testDecodedReturnsEmptyArrayOnInvalidJson(): void
    {
        $request = WebhookRequest::fromRaw('not-json{', []);

        $this->assertSame([], $request->decoded());
    }

    public function testDecodedReturnsEmptyArrayOnNonArrayJson(): void
    {
        $request = WebhookRequest::fromRaw('"scalar"', []);

        $this->assertSame([], $request->decoded());
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $request = WebhookRequest::fromRaw('', ['X-Custom-Header' => 'value']);

        $this->assertSame('value', $request->header('x-custom-header'));
        $this->assertSame('value', $request->header('X-CUSTOM-HEADER'));
    }

    public function testHeaderReturnsNullWhenAbsent(): void
    {
        $request = WebhookRequest::fromRaw('', []);

        $this->assertNull($request->header('x-missing'));
    }

    public function testBasicAuthParsesUserAndPass(): void
    {
        $request = WebhookRequest::fromRaw('', [
            'Authorization' => 'Basic ' . base64_encode('user:pass'),
        ]);

        $this->assertSame(['user' => 'user', 'pass' => 'pass'], $request->basicAuth());
    }

    public function testBasicAuthLookupIsCaseInsensitiveOnHeaderName(): void
    {
        $request = WebhookRequest::fromRaw('', [
            'AUTHORIZATION' => 'Basic ' . base64_encode('user:pass'),
        ]);

        $this->assertSame(['user' => 'user', 'pass' => 'pass'], $request->basicAuth());
    }

    public function testBasicAuthPreservesColonsInPassword(): void
    {
        $request = WebhookRequest::fromRaw('', [
            'Authorization' => 'Basic ' . base64_encode('user:pa:ss'),
        ]);

        $this->assertSame(['user' => 'user', 'pass' => 'pa:ss'], $request->basicAuth());
    }

    public function testBasicAuthReturnsNullWhenHeaderAbsent(): void
    {
        $request = WebhookRequest::fromRaw('', []);

        $this->assertNull($request->basicAuth());
    }

    public function testBasicAuthReturnsNullForNonBasicScheme(): void
    {
        $request = WebhookRequest::fromRaw('', ['Authorization' => 'Bearer token123']);

        $this->assertNull($request->basicAuth());
    }

    public function testBasicAuthReturnsNullWhenNoColonInCredentials(): void
    {
        $request = WebhookRequest::fromRaw('', [
            'Authorization' => 'Basic ' . base64_encode('nocolon'),
        ]);

        $this->assertNull($request->basicAuth());
    }
}
