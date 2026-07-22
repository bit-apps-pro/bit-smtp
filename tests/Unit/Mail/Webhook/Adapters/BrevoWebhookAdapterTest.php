<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Webhook\Adapters;

use BitApps\SMTP\Mail\Status\DeliveryStatus;
use BitApps\SMTP\Mail\Webhook\Adapters\BrevoWebhookAdapter;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 *
 * @coversNothing
 */
class BrevoWebhookAdapterTest extends BaseUnitTestCase
{
    private BrevoWebhookAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new BrevoWebhookAdapter();
    }

    public function testDeliveredEvent(): void
    {
        $events = $this->parse('{"event":"delivered","message-id":"<x@relay.mailin.fr>","email":"a@b.com","date":"2024-01-01 10:00:00","X-Mailin-custom":"uuid-1"}');

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame(DeliveryStatus::DELIVERED, $event->status());
        $this->assertTrue($event->isTerminal());
        $this->assertSame('a@b.com', $event->recipient());
        $this->assertSame('<x@relay.mailin.fr>', $event->messageId());
        $this->assertSame('uuid-1', $event->trackingId());
        $this->assertSame('delivered', $event->detail());
        $this->assertSame('2024-01-01 10:00:00', $event->occurredAt());
    }

    #[DataProvider('statusMappingProvider')]
    public function testEventStatusAndTerminalMapping(string $event, string $expectedStatus, bool $expectedTerminal): void
    {
        $events = $this->parse('{"event":"' . $event . '","email":"a@b.com"}');

        $this->assertCount(1, $events);
        $this->assertSame($expectedStatus, $events[0]->status());
        $this->assertSame($expectedTerminal, $events[0]->isTerminal());
        $this->assertSame($event, $events[0]->detail());
    }

    public static function statusMappingProvider(): array
    {
        return [
            'delivered'   => ['delivered', DeliveryStatus::DELIVERED, true],
            'hard_bounce' => ['hard_bounce', DeliveryStatus::BOUNCED, true],
            'soft_bounce' => ['soft_bounce', DeliveryStatus::BOUNCED, false],
            'blocked'     => ['blocked', DeliveryStatus::BLOCKED, true],
            'spam'        => ['spam', DeliveryStatus::SPAM, true],
            'deferred'    => ['deferred', DeliveryStatus::DEFERRED, false],
        ];
    }

    public function testUnknownEventYieldsNoEvents(): void
    {
        $this->assertSame([], $this->parse('{"event":"click"}'));
    }

    public function testMissingEventYieldsNoEvents(): void
    {
        $this->assertSame([], $this->parse('{"email":"a@b.com"}'));
    }

    public function testMalformedJsonYieldsNoEvents(): void
    {
        $this->assertSame([], $this->parse('not-json{'));
    }

    public function testUnixTimestampIsConvertedToUtc(): void
    {
        $events = $this->parse('{"event":"delivered","email":"a@b.com","ts":1704103200}');

        $this->assertSame(gmdate('Y-m-d H:i:s', 1704103200), $events[0]->occurredAt());
    }

    public function testMissingCorrelationIdsAreNull(): void
    {
        $events = $this->parse('{"event":"delivered","email":"a@b.com"}');

        $this->assertNull($events[0]->messageId());
        $this->assertNull($events[0]->trackingId());
    }

    /**
     * @return \BitApps\SMTP\Mail\Webhook\DeliveryEvent[]
     */
    private function parse(string $body): array
    {
        return $this->adapter->parseEvents(WebhookRequest::fromRaw($body, []));
    }
}
