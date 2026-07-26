<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Webhook\Adapters;

use BitApps\SMTP\Mail\Status\DeliveryStatus;
use BitApps\SMTP\Mail\Webhook\Adapters\SendGridWebhookAdapter;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 *
 * @coversNothing
 */
class SendGridWebhookAdapterTest extends BaseUnitTestCase
{
    private SendGridWebhookAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new SendGridWebhookAdapter();
    }

    public function testBatchedProcessedAndBlockedBounceEvents(): void
    {
        $events = $this->parse((string) json_encode([
            [
                'event'           => 'processed',
                'email'           => 'a@b.com',
                'sg_message_id'   => 'sg-message-1',
                'bit_tracking_id' => 'track-1',
                'timestamp'       => 1704103200,
            ],
            [
                'event'           => 'bounce',
                'type'            => 'blocked',
                'email'           => 'a@b.com',
                'sg_message_id'   => 'sg-message-1',
                'bit_tracking_id' => 'track-1',
                'timestamp'       => 1704103201,
                'reason'          => '554 5.7.7 Email policy violation detected',
            ],
        ]));

        $this->assertCount(2, $events);
        $this->assertSame(DeliveryStatus::ACCEPTED, $events[0]->status());
        $this->assertFalse($events[0]->isTerminal());
        $this->assertSame(DeliveryStatus::BLOCKED, $events[1]->status());
        $this->assertTrue($events[1]->isTerminal());
        $this->assertSame('sg-message-1', $events[1]->messageId());
        $this->assertSame('track-1', $events[1]->trackingId());
        $this->assertSame('a@b.com', $events[1]->recipient());
        $this->assertSame('554 5.7.7 Email policy violation detected', $events[1]->detail());
        $this->assertSame(gmdate('Y-m-d H:i:s', 1704103201), $events[1]->occurredAt());
    }

    #[DataProvider('statusMappingProvider')]
    public function testStatusAndTerminalMapping(array $payload, string $expectedStatus, bool $expectedTerminal): void
    {
        $events = $this->parse((string) json_encode([$payload + ['email' => 'a@b.com']]));

        $this->assertCount(1, $events);
        $this->assertSame($expectedStatus, $events[0]->status());
        $this->assertSame($expectedTerminal, $events[0]->isTerminal());
    }

    public static function statusMappingProvider(): array
    {
        return [
            'processed'      => [['event' => 'processed'], DeliveryStatus::ACCEPTED, false],
            'delivered'      => [['event' => 'delivered'], DeliveryStatus::DELIVERED, true],
            'deferred'       => [['event' => 'deferred'], DeliveryStatus::DEFERRED, false],
            'dropped'        => [['event' => 'dropped'], DeliveryStatus::BLOCKED, true],
            'bounce'         => [['event' => 'bounce', 'type' => 'bounce'], DeliveryStatus::BOUNCED, true],
            'blocked bounce' => [['event' => 'bounce', 'type' => 'blocked'], DeliveryStatus::BLOCKED, true],
            'spam report'    => [['event' => 'spamreport'], DeliveryStatus::SPAM, true],
        ];
    }

    public function testSingleEventObjectIsAcceptedForManualWebhookTests(): void
    {
        $events = $this->parse('{"event":"delivered","email":"a@b.com","timestamp":"2026-07-23T06:49:43Z"}');

        $this->assertCount(1, $events);
        $this->assertSame(DeliveryStatus::DELIVERED, $events[0]->status());
        $this->assertSame('2026-07-23 06:49:43', $events[0]->occurredAt());
    }

    public function testTrackingIdFallsBackToNestedCustomArgs(): void
    {
        $events = $this->parse('[{"event":"delivered","custom_args":{"bit_tracking_id":"track-2"}}]');

        $this->assertSame('track-2', $events[0]->trackingId());
    }

    public function testUnknownAndEngagementEventsAreIgnored(): void
    {
        $this->assertSame([], $this->parse('[{"event":"open"},{"event":"click"}]'));
    }

    public function testMalformedJsonYieldsNoEvents(): void
    {
        $this->assertSame([], $this->parse('not-json{'));
    }

    private function parse(string $body): array
    {
        return $this->adapter->parseEvents(WebhookRequest::fromRaw($body));
    }
}
