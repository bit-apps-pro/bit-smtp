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
    public function testDecodedReturnsAssociativeArray(): void
    {
        $request = WebhookRequest::fromRaw('{"event":"delivered","email":"a@b.com"}');

        $this->assertSame(['event' => 'delivered', 'email' => 'a@b.com'], $request->decoded());
    }

    public function testDecodedReturnsEmptyArrayOnInvalidJson(): void
    {
        $request = WebhookRequest::fromRaw('not-json{');

        $this->assertSame([], $request->decoded());
    }

    public function testDecodedReturnsEmptyArrayOnNonArrayJson(): void
    {
        $request = WebhookRequest::fromRaw('"scalar"');

        $this->assertSame([], $request->decoded());
    }
}
