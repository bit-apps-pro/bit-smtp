<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Webhook;

use BitApps\SMTP\Mail\Webhook\DeliveryEvent;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class DeliveryEventTest extends BaseUnitTestCase
{
    public function testFromArrayAndGettersRoundTrip(): void
    {
        $data = [
            'message_id'  => 'msg-123',
            'tracking_id' => 'track-456',
            'recipient'   => 'user@example.com',
            'status'      => 'delivered',
            'terminal'    => true,
            'detail'      => 'Delivered successfully',
            'occurred_at' => '2024-01-01T10:00:00.000-05:00',
        ];

        $event = DeliveryEvent::fromArray($data);

        $this->assertSame('msg-123', $event->messageId());
        $this->assertSame('track-456', $event->trackingId());
        $this->assertSame('user@example.com', $event->recipient());
        $this->assertSame('delivered', $event->status());
        $this->assertTrue($event->isTerminal());
        $this->assertSame('Delivered successfully', $event->detail());
        $this->assertSame('2024-01-01 15:00:00', $event->occurredAt());
    }

    public function testMissingOptionalsDefaultToNull(): void
    {
        $event = DeliveryEvent::fromArray([
            'recipient' => 'test@example.com',
            'status'    => 'bounced',
        ]);

        $this->assertNull($event->messageId());
        $this->assertNull($event->trackingId());
        $this->assertNull($event->detail());
        $this->assertNull($event->occurredAt());
        $this->assertSame('test@example.com', $event->recipient());
        $this->assertSame('bounced', $event->status());
    }

    public function testRecipientAndStatusDefaultToEmptyString(): void
    {
        $event = DeliveryEvent::fromArray([]);

        $this->assertSame('', $event->recipient());
        $this->assertSame('', $event->status());
    }

    public function testTerminalDefaultsToFalse(): void
    {
        $event = DeliveryEvent::fromArray([
            'recipient' => 'test@example.com',
            'status'    => 'deferred',
        ]);

        $this->assertFalse($event->isTerminal());
    }

    public function testCorrelationKeysNormalizesEmptyStringMessageIdToNull(): void
    {
        $event = DeliveryEvent::fromArray([
            'message_id'  => '',
            'tracking_id' => 'track-123',
        ]);

        $keys = $event->correlationKeys();
        $this->assertNull($keys['message_id']);
        $this->assertSame('track-123', $keys['tracking_id']);
    }

    public function testCorrelationKeysNormalizesEmptyStringTrackingIdToNull(): void
    {
        $event = DeliveryEvent::fromArray([
            'message_id'  => 'msg-123',
            'tracking_id' => '',
        ]);

        $keys = $event->correlationKeys();
        $this->assertSame('msg-123', $keys['message_id']);
        $this->assertNull($keys['tracking_id']);
    }

    public function testCorrelationKeysPreservesNonEmptyValues(): void
    {
        $event = DeliveryEvent::fromArray([
            'message_id'  => 'msg-123',
            'tracking_id' => 'track-456',
        ]);

        $keys = $event->correlationKeys();
        $this->assertSame('msg-123', $keys['message_id']);
        $this->assertSame('track-456', $keys['tracking_id']);
    }

    public function testHashIdenticalForSameRecipientStatusAndTime(): void
    {
        $event1 = DeliveryEvent::fromArray([
            'recipient'   => 'user@example.com',
            'status'      => 'delivered',
            'occurred_at' => '2024-01-01 10:00:00',
        ]);

        $event2 = DeliveryEvent::fromArray([
            'recipient'   => 'user@example.com',
            'status'      => 'delivered',
            'occurred_at' => '2024-01-01 10:00:00',
        ]);

        $this->assertSame($event1->hash(42), $event2->hash(42));
    }

    public function testHashDifferentForDifferentOccurredAt(): void
    {
        $event1 = DeliveryEvent::fromArray([
            'recipient'   => 'user@example.com',
            'status'      => 'delivered',
            'occurred_at' => '2024-01-01 10:00:00',
        ]);

        $event2 = DeliveryEvent::fromArray([
            'recipient'   => 'user@example.com',
            'status'      => 'delivered',
            'occurred_at' => '2024-01-01 11:00:00',
        ]);

        $this->assertNotSame($event1->hash(42), $event2->hash(42));
    }

    public function testHashIdenticalForBothNullOccurredAt(): void
    {
        $event1 = DeliveryEvent::fromArray([
            'recipient' => 'user@example.com',
            'status'    => 'delivered',
        ]);

        $event2 = DeliveryEvent::fromArray([
            'recipient' => 'user@example.com',
            'status'    => 'delivered',
        ]);

        $this->assertSame($event1->hash(42), $event2->hash(42));
    }

    public function testOccurredAtNormalizationFromTimestampWithOffset(): void
    {
        $event = DeliveryEvent::fromArray([
            'occurred_at' => '2024-01-01T10:00:00.000-05:00',
        ]);

        $this->assertSame('2024-01-01 15:00:00', $event->occurredAt());
    }

    public function testOccurredAtEmptyStringBecomesNull(): void
    {
        $event = DeliveryEvent::fromArray([
            'occurred_at' => '',
        ]);

        $this->assertNull($event->occurredAt());
    }

    public function testOccurredAtInvalidDateBecomesNull(): void
    {
        $event = DeliveryEvent::fromArray([
            'occurred_at' => 'not-a-date',
        ]);

        $this->assertNull($event->occurredAt());
    }

    public function testHashVariesByRecipient(): void
    {
        $event1 = DeliveryEvent::fromArray([
            'recipient' => 'user1@example.com',
            'status'    => 'delivered',
        ]);

        $event2 = DeliveryEvent::fromArray([
            'recipient' => 'user2@example.com',
            'status'    => 'delivered',
        ]);

        $this->assertNotSame($event1->hash(42), $event2->hash(42));
    }

    public function testHashVariesByStatus(): void
    {
        $event1 = DeliveryEvent::fromArray([
            'recipient' => 'user@example.com',
            'status'    => 'delivered',
        ]);

        $event2 = DeliveryEvent::fromArray([
            'recipient' => 'user@example.com',
            'status'    => 'bounced',
        ]);

        $this->assertNotSame($event1->hash(42), $event2->hash(42));
    }

    public function testHashVariesByLogId(): void
    {
        $event = DeliveryEvent::fromArray([
            'recipient' => 'user@example.com',
            'status'    => 'delivered',
        ]);

        $this->assertNotSame($event->hash(42), $event->hash(43));
    }

    public function testHashVariesByTerminal(): void
    {
        $transient = DeliveryEvent::fromArray([
            'recipient' => 'user@example.com',
            'status'    => 'deferred',
            'terminal'  => false,
        ]);

        $terminal = DeliveryEvent::fromArray([
            'recipient' => 'user@example.com',
            'status'    => 'deferred',
            'terminal'  => true,
        ]);

        $this->assertNotSame($transient->hash(42), $terminal->hash(42));
    }

    public function testHashVariesByDetail(): void
    {
        $event1 = DeliveryEvent::fromArray([
            'recipient' => 'user@example.com',
            'status'    => 'bounced',
            'detail'    => 'mailbox full',
        ]);

        $event2 = DeliveryEvent::fromArray([
            'recipient' => 'user@example.com',
            'status'    => 'bounced',
            'detail'    => 'user unknown',
        ]);

        $this->assertNotSame($event1->hash(42), $event2->hash(42));
    }
}
