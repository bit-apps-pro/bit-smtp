<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Webhook\Adapters;

use BitApps\SMTP\Mail\Webhook\Adapters\MailgunWebhookAdapter;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class MailgunWebhookAdapterTest extends BaseUnitTestCase
{
    public function testMapsDocumentedDeliveryAndFailureEvents(): void
    {
        $adapter   = new MailgunWebhookAdapter();
        $delivered = $adapter->parseEvents($this->request('delivered'));
        $temporary = $adapter->parseEvents($this->request('temporary_fail'));
        $permanent = $adapter->parseEvents($this->request('permanent_fail'));

        $this->assertSame('delivered', $delivered[0]->status());
        $this->assertTrue($delivered[0]->isTerminal());
        $this->assertSame('deferred', $temporary[0]->status());
        $this->assertFalse($temporary[0]->isTerminal());
        $this->assertSame('bounced', $permanent[0]->status());
        $this->assertTrue($permanent[0]->isTerminal());
        $this->assertSame('message-1@example.test', $permanent[0]->messageId());
        $this->assertSame('tracking-1', $permanent[0]->trackingId());
    }

    private function request(string $event): WebhookRequest
    {
        return WebhookRequest::fromRaw(json_encode([
            'event'           => $event,
            'timestamp'       => 1720000000,
            'recipient'       => 'user@example.test',
            'message'         => ['headers' => ['message-id' => 'message-1@example.test']],
            'user-variables'  => ['bit_tracking_id' => 'tracking-1'],
            'delivery-status' => ['message' => 'provider detail'],
        ]));
    }
}
