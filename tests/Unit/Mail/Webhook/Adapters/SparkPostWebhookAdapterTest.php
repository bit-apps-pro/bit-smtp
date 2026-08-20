<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Webhook\Adapters;

use BitApps\SMTP\Mail\Webhook\Adapters\SparkPostWebhookAdapter;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class SparkPostWebhookAdapterTest extends BaseUnitTestCase
{
    public function testMapsDocumentedBatchedMessageEventsAndRecipientMetadata(): void
    {
        $events = (new SparkPostWebhookAdapter())->parseEvents(WebhookRequest::fromRaw(json_encode([
            ['msys' => ['message_event' => [
                'type'            => 'delivery', 'timestamp' => '1720000000', 'message_id' => 'message-1',
                'transmission_id' => 'transmission-1',
                'rcpt_to'         => 'user@example.test', 'rcpt_meta' => ['bit_tracking_id' => 'tracking-1'],
            ]]],
            ['msys' => ['message_event' => [
                'type' => 'delay', 'timestamp' => '1720000001', 'reason' => 'temporary',
            ]]],
        ])));

        $this->assertCount(2, $events);
        $this->assertSame('delivered', $events[0]->status());
        $this->assertSame('transmission-1', $events[0]->messageId());
        $this->assertSame('tracking-1', $events[0]->trackingId());
        $this->assertSame(gmdate('Y-m-d H:i:s', 1720000000), $events[0]->occurredAt());
        $this->assertSame('deferred', $events[1]->status());
        $this->assertFalse($events[1]->isTerminal());
    }

    public function testMapsDocumentedGenerationEventsAsTerminalBlockedOutcomes(): void
    {
        $events = (new SparkPostWebhookAdapter())->parseEvents(WebhookRequest::fromRaw(json_encode([
            ['msys' => ['gen_event' => [
                'type'            => 'generation_failure', 'timestamp' => '1720000002',
                'transmission_id' => 'transmission-2', 'reason' => 'template rendering failed',
                'rcpt_to'         => 'failure@example.test', 'rcpt_meta' => ['bit_tracking_id' => 'tracking-2'],
            ]]],
            ['msys' => ['gen_event' => [
                'type'            => 'generation_rejection', 'timestamp' => '1720000003',
                'transmission_id' => 'transmission-3', 'reason' => 'policy rejection',
                'rcpt_to'         => 'rejected@example.test', 'rcpt_meta' => ['bit_tracking_id' => 'tracking-3'],
            ]]],
        ])));

        $this->assertCount(2, $events);
        $this->assertSame('blocked', $events[0]->status());
        $this->assertTrue($events[0]->isTerminal());
        $this->assertSame('transmission-2', $events[0]->messageId());
        $this->assertSame('tracking-2', $events[0]->trackingId());
        $this->assertSame('blocked', $events[1]->status());
        $this->assertTrue($events[1]->isTerminal());
        $this->assertSame('transmission-3', $events[1]->messageId());
        $this->assertSame('tracking-3', $events[1]->trackingId());
    }
}
