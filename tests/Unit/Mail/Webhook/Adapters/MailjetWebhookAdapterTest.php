<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Webhook\Adapters;

use BitApps\SMTP\Mail\Webhook\Adapters\MailjetWebhookAdapter;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class MailjetWebhookAdapterTest extends BaseUnitTestCase
{
    public function testMapsDeliveryAndBounceEventsFromDocumentedPayload(): void
    {
        $adapter   = new MailjetWebhookAdapter();
        $delivered = $adapter->parseEvents(WebhookRequest::fromRaw(json_encode([
            'event'          => 'sent', 'time' => 1720000000, 'email' => 'user@example.test',
            'MessageID'      => 19421777835146490, 'CustomID' => 'tracking-1',
            'customcampaign' => 'legacy-tracking',
        ])));
        $bounce = $adapter->parseEvents(WebhookRequest::fromRaw(json_encode([
            'event' => 'bounce', 'time' => 1720000001, 'email' => 'user@example.test', 'hard_bounce' => true, 'error' => 'user unknown',
        ])));

        $this->assertSame('delivered', $delivered[0]->status());
        $this->assertTrue($delivered[0]->isTerminal());
        $this->assertSame('19421777835146490', $delivered[0]->messageId());
        $this->assertSame('tracking-1', $delivered[0]->trackingId());
        $this->assertSame('bounced', $bounce[0]->status());
        $this->assertTrue($bounce[0]->isTerminal());
    }

    public function testFallsBackToLegacyCustomCampaignTracking(): void
    {
        $events = (new MailjetWebhookAdapter())->parseEvents(WebhookRequest::fromRaw(json_encode([
            'event'          => 'sent',
            'customcampaign' => 'legacy-tracking',
        ])));

        $this->assertSame('legacy-tracking', $events[0]->trackingId());
    }

    public function testMapsGroupedVersionTwoPayload(): void
    {
        $events = (new MailjetWebhookAdapter())->parseEvents(WebhookRequest::fromRaw(json_encode([
            ['event' => 'sent', 'time' => 1720000000, 'email' => 'a@example.test'],
            ['event' => 'blocked', 'time' => 1720000001, 'email' => 'b@example.test', 'error' => 'preblocked'],
        ])));

        $this->assertCount(2, $events);
        $this->assertSame('delivered', $events[0]->status());
        $this->assertSame('blocked', $events[1]->status());
    }
}
