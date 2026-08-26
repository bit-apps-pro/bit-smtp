<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Webhook\Adapters;

use BitApps\SMTP\Mail\Status\DeliveryStatus;
use BitApps\SMTP\Mail\Webhook\Adapters\SesSnsWebhookAdapter;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class SesSnsWebhookAdapterTest extends BaseUnitTestCase
{
    private SesSnsWebhookAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new SesSnsWebhookAdapter();
    }

    public function testDeliveryNotificationYieldsOneTerminalDeliveredEventPerRecipient(): void
    {
        $events = $this->parse([
            'notificationType' => 'Delivery',
            'mail'             => ['messageId' => 'ses-msg-1'],
            'delivery'         => [
                'timestamp'  => '2026-08-26T00:00:00Z',
                'recipients' => ['a@x.test', 'b@x.test'],
            ],
        ]);

        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertSame('ses-msg-1', $event->messageId());
            $this->assertSame(DeliveryStatus::DELIVERED, $event->status());
            $this->assertTrue($event->isTerminal());
        }
        $this->assertSame('a@x.test', $events[0]->recipient());
        $this->assertSame('b@x.test', $events[1]->recipient());
    }

    public function testPermanentBounceIsTerminalWithBounceTypeInDetail(): void
    {
        $events = $this->parse([
            'notificationType' => 'Bounce',
            'mail'             => ['messageId' => 'ses-msg-2'],
            'bounce'           => [
                'bounceType'        => 'Permanent',
                'bouncedRecipients' => [['emailAddress' => 'c@x.test']],
            ],
        ]);

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame('ses-msg-2', $event->messageId());
        $this->assertSame(DeliveryStatus::BOUNCED, $event->status());
        $this->assertTrue($event->isTerminal());
        $this->assertSame('c@x.test', $event->recipient());
        $this->assertStringContainsString('Permanent', (string) $event->detail());
    }

    public function testTransientBounceIsNotTerminalButStillBounced(): void
    {
        $events = $this->parse([
            'notificationType' => 'Bounce',
            'mail'             => ['messageId' => 'ses-msg-2'],
            'bounce'           => [
                'bounceType'        => 'Transient',
                'bouncedRecipients' => [['emailAddress' => 'c@x.test']],
            ],
        ]);

        $this->assertCount(1, $events);
        $this->assertSame(DeliveryStatus::BOUNCED, $events[0]->status());
        $this->assertFalse($events[0]->isTerminal());
    }

    public function testComplaintIsTerminalSpam(): void
    {
        $events = $this->parse([
            'notificationType' => 'Complaint',
            'mail'             => ['messageId' => 'ses-msg-3'],
            'complaint'        => [
                'complainedRecipients' => [['emailAddress' => 'd@x.test']],
            ],
        ]);

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame('ses-msg-3', $event->messageId());
        $this->assertSame(DeliveryStatus::SPAM, $event->status());
        $this->assertTrue($event->isTerminal());
        $this->assertSame('d@x.test', $event->recipient());
    }

    public function testSubscriptionConfirmationEnvelopeYieldsNoEvents(): void
    {
        $inner    = json_encode(['notificationType' => 'Delivery', 'mail' => ['messageId' => 'x'], 'delivery' => ['recipients' => ['a@x.test']]]);
        $envelope = json_encode(['Type' => 'SubscriptionConfirmation', 'Message' => $inner]);

        $this->assertSame([], $this->adapter->parseEvents(WebhookRequest::fromRaw($envelope, [])));
    }

    public function testNotificationWithNonJsonInnerMessageYieldsNoEvents(): void
    {
        $envelope = json_encode(['Type' => 'Notification', 'Message' => 'not-json{']);

        $this->assertSame([], $this->adapter->parseEvents(WebhookRequest::fromRaw($envelope, [])));
    }

    public function testUnknownNotificationTypeYieldsNoEvents(): void
    {
        $this->assertSame([], $this->parse([
            'notificationType' => 'Open',
            'mail'             => ['messageId' => 'ses-msg-9'],
        ]));
    }

    /**
     * Wrap the given inner SES notification as the JSON string an SNS Notification envelope carries,
     * then run it through the adapter — mirroring how SES publishes feedback over SNS.
     *
     * @param array<string,mixed> $inner
     *
     * @return \BitApps\SMTP\Mail\Webhook\DeliveryEvent[]
     */
    private function parse(array $inner): array
    {
        $envelope = json_encode([
            'Type'    => 'Notification',
            'Message' => json_encode($inner),
        ]);

        return $this->adapter->parseEvents(WebhookRequest::fromRaw($envelope, []));
    }
}
