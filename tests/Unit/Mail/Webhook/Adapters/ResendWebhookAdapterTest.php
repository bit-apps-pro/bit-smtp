<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Webhook\Adapters;

use BitApps\SMTP\Mail\Webhook\Adapters\ResendWebhookAdapter;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class ResendWebhookAdapterTest extends BaseUnitTestCase
{
    public function testMapsDocumentedEmailLifecycleEvents(): void
    {
        $adapter   = new ResendWebhookAdapter();
        $delivered = $adapter->parseEvents($this->request('email.delivered'));
        $delayed   = $adapter->parseEvents($this->request('email.delivery_delayed'));
        $bounced   = $adapter->parseEvents($this->request('email.bounced'));

        $this->assertSame('delivered', $delivered[0]->status());
        $this->assertSame('email-1', $delivered[0]->messageId());
        $this->assertSame('tracking-1', $delivered[0]->trackingId());
        $this->assertSame('deferred', $delayed[0]->status());
        $this->assertFalse($delayed[0]->isTerminal());
        $this->assertSame('bounced', $bounced[0]->status());
        $this->assertTrue($bounced[0]->isTerminal());
    }

    private function request(string $type): WebhookRequest
    {
        return WebhookRequest::fromRaw(json_encode([
            'type'       => $type,
            'created_at' => '2026-07-23T10:00:00Z',
            'data'       => [
                'email_id' => 'email-1',
                'to'       => ['user@example.test'],
                'tags'     => ['bit_tracking_id' => 'tracking-1'],
            ],
        ]));
    }
}
