<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Routing;

use BitApps\SMTP\Mail\Routing\RoutingContext;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class RoutingContextTest extends BaseUnitTestCase
{
    public function testFromArrayAndGetters(): void
    {
        $context = RoutingContext::fromArray([
            'recipients'   => ['a@example.com', 'b@example.com'],
            'from'         => 'sender@example.com',
            'subject'      => 'Hello world',
            'sourcePlugin' => 'woocommerce',
        ]);

        $this->assertSame(['a@example.com', 'b@example.com'], $context->getRecipients());
        $this->assertSame('sender@example.com', $context->getFrom());
        $this->assertSame('Hello world', $context->getSubject());
        $this->assertSame('woocommerce', $context->getSourcePlugin());
    }

    public function testFromArrayUsesDefaultsForMissingKeys(): void
    {
        $context = RoutingContext::fromArray([]);

        $this->assertSame([], $context->getRecipients());
        $this->assertSame('', $context->getFrom());
        $this->assertSame('', $context->getSubject());
        $this->assertSame('', $context->getSourcePlugin());
    }
}
