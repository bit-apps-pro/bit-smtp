<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Webhook\Adapters;

use BitApps\SMTP\Mail\Status\DeliveryStatus;
use BitApps\SMTP\Mail\Webhook\Adapters\PostmarkWebhookAdapter;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class PostmarkWebhookAdapterTest extends BaseUnitTestCase
{
    private PostmarkWebhookAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new PostmarkWebhookAdapter();
    }

    public function testDeliveryEvent(): void
    {
        $events = $this->parse('{"RecordType":"Delivery","MessageID":"pm-123","Recipient":"a@b.com","DeliveredAt":"2024-01-01T10:00:00Z","Metadata":{"bit_tracking_id":"uuid-1"}}');

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame(DeliveryStatus::DELIVERED, $event->status());
        $this->assertTrue($event->isTerminal());
        $this->assertSame('a@b.com', $event->recipient());
        $this->assertSame('pm-123', $event->messageId());
        $this->assertSame('uuid-1', $event->trackingId());
        $this->assertSame('2024-01-01 10:00:00', $event->occurredAt());
    }

    public function testHardBounceIsTerminal(): void
    {
        $events = $this->parse('{"RecordType":"Bounce","MessageID":"pm-123","Email":"a@b.com","Type":"HardBounce","TypeCode":1,"BouncedAt":"2024-01-01T10:05:00Z","Metadata":{"bit_tracking_id":"uuid-1"}}');

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame(DeliveryStatus::BOUNCED, $event->status());
        $this->assertTrue($event->isTerminal());
        $this->assertSame('a@b.com', $event->recipient());
        $this->assertSame('HardBounce', $event->detail());
        $this->assertSame('pm-123', $event->messageId());
        $this->assertSame('uuid-1', $event->trackingId());
    }

    public function testSoftBounceIsNotTerminal(): void
    {
        $events = $this->parse('{"RecordType":"Bounce","MessageID":"pm-123","Email":"a@b.com","Type":"SoftBounce","TypeCode":4096,"BouncedAt":"2024-01-01T10:05:00Z","Metadata":{"bit_tracking_id":"uuid-1"}}');

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame(DeliveryStatus::BOUNCED, $event->status());
        $this->assertFalse($event->isTerminal());
        $this->assertSame('SoftBounce', $event->detail());
    }

    public function testSpamComplaintIsTerminal(): void
    {
        $events = $this->parse('{"RecordType":"SpamComplaint","MessageID":"pm-123","Email":"a@b.com"}');

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame(DeliveryStatus::SPAM, $event->status());
        $this->assertTrue($event->isTerminal());
        $this->assertSame('a@b.com', $event->recipient());
        $this->assertSame('pm-123', $event->messageId());
    }

    public function testUnknownRecordTypeYieldsNoEvents(): void
    {
        $this->assertSame([], $this->parse('{"RecordType":"Open","MessageID":"pm-1"}'));
    }

    public function testMalformedJsonYieldsNoEvents(): void
    {
        $this->assertSame([], $this->parse('not-json{'));
    }

    public function testMissingTrackingIdIsNull(): void
    {
        $events = $this->parse('{"RecordType":"Delivery","MessageID":"pm-123","Recipient":"a@b.com","DeliveredAt":"2024-01-01T10:00:00Z"}');

        $this->assertNull($events[0]->trackingId());
    }

    /**
     * @return \BitApps\SMTP\Mail\Webhook\DeliveryEvent[]
     */
    private function parse(string $body): array
    {
        return $this->adapter->parseEvents(WebhookRequest::fromRaw($body));
    }
}
