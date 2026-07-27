<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Webhook\Adapters;

use BitApps\SMTP\Mail\Webhook\Adapters\ZeptoWebhookAdapter;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class ZeptoWebhookAdapterTest extends BaseUnitTestCase
{
    public function testParsesDocumentedFormEncodedBouncePayload(): void
    {
        $payload = [
            'event_name'    => ['hardbounce'],
            'event_message' => [[
                'email_info' => [
                    'email_reference'  => 'email-1',
                    'client_reference' => 'tracking-1',
                    'processed_time'   => '2026-07-23T10:00:00Z',
                    'to'               => [['email_address' => ['address' => 'user@example.test']]],
                ],
                'event_data' => [['details' => [['diagnostic_message' => 'bad-mailbox']]]],
                'request_id' => 'request-1',
            ]],
        ];
        $request = WebhookRequest::fromRaw(http_build_query(['data' => json_encode($payload)]));
        $events  = (new ZeptoWebhookAdapter())->parseEvents($request);

        $this->assertCount(1, $events);
        $this->assertSame('bounced', $events[0]->status());
        $this->assertSame('email-1', $events[0]->messageId());
        $this->assertSame('tracking-1', $events[0]->trackingId());
        $this->assertSame('user@example.test', $events[0]->recipient());
        $this->assertSame('bad-mailbox', $events[0]->detail());
    }

    /**
     * Regression: event_name and event_message are both attacker-sized arrays, so the parser must not
     * cross-product them (that is quadratic → DoS). The emitted count is bounded by
     * messages × DISTINCT recognised names; duplicate and unknown names never multiply the work.
     */
    public function testBoundsEventCountToMessagesTimesDistinctRecognisedNames(): void
    {
        $names = array_merge(
            array_fill(0, 5000, 'hardbounce'),   // duplicates must collapse to one
            array_fill(0, 5000, 'not-an-event'), // unknown names must be dropped
            ['delivered']
        );
        $message = [
            'email_info' => ['email_reference' => 'm', 'to' => [['email_address' => ['address' => 'u@example.test']]]],
            'request_id' => 'r',
        ];
        $payload = ['event_name' => $names, 'event_message' => [$message, $message]];
        $request = WebhookRequest::fromRaw(http_build_query(['data' => json_encode($payload)]));

        $events = (new ZeptoWebhookAdapter())->parseEvents($request);

        // 2 messages × 2 distinct recognised names (hardbounce, delivered) — NOT 2 × 10001.
        $this->assertCount(4, $events);
        $statuses = array_map(static fn ($e) => $e->status(), $events);
        $this->assertSame(['bounced', 'delivered', 'bounced', 'delivered'], $statuses);
    }
}
