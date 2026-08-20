<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Webhook;

use BitApps\SMTP\Mail\Status\DeliveryStatus;
use BitApps\SMTP\Mail\Webhook\DeliveryRollup;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * Pins DeliveryRollup::compute() — the pure reducer folding per-recipient delivery events into
 * one parent status + timestamp: recency wins per recipient, worst-terminal wins for the parent,
 * and an absent event timestamp falls back to created_at so recency is never undefined.
 *
 * @internal
 *
 * @coversNothing
 */
class DeliveryRollupTest extends BaseUnitTestCase
{
    public function testEmptyInputYieldsNulls(): void
    {
        $this->assertSame(
            ['status' => null, 'updated_at' => null],
            DeliveryRollup::compute([])
        );
    }

    public function testSingleTerminalDeliveredIsDelivered(): void
    {
        $rows = [
            $this->row('a@example.com', DeliveryStatus::DELIVERED, true, '2024-01-01 10:00:00'),
        ];

        $result = DeliveryRollup::compute($rows);

        $this->assertSame(DeliveryStatus::DELIVERED, $result['status']);
        $this->assertSame('2024-01-01 10:00:00', $result['updated_at']);
    }

    public function testLaterTerminalEventWinsForSameRecipient(): void
    {
        $rows = [
            $this->row('a@example.com', DeliveryStatus::DELIVERED, true, '2024-01-01 10:00:00'),
            $this->row('a@example.com', DeliveryStatus::SPAM, true, '2024-01-01 11:00:00'),
        ];

        $result = DeliveryRollup::compute($rows);

        $this->assertSame(DeliveryStatus::SPAM, $result['status']);
        $this->assertSame('2024-01-01 11:00:00', $result['updated_at']);
    }

    public function testTerminalEventBeatsTransientEvenIfTransientHasWorseStatus(): void
    {
        $rows = [
            $this->row('a@example.com', DeliveryStatus::BOUNCED, false, '2024-01-01 10:00:00'),
            $this->row('a@example.com', DeliveryStatus::DELIVERED, true, '2024-01-01 11:00:00'),
        ];

        $result = DeliveryRollup::compute($rows);

        $this->assertSame(DeliveryStatus::DELIVERED, $result['status']);
    }

    public function testWorstTerminalWinsAcrossRecipients(): void
    {
        $rows = [
            $this->row('a@example.com', DeliveryStatus::DELIVERED, true, '2024-01-01 10:00:00'),
            $this->row('b@example.com', DeliveryStatus::BOUNCED, true, '2024-01-01 10:00:00'),
        ];

        $result = DeliveryRollup::compute($rows);

        $this->assertSame(DeliveryStatus::BOUNCED, $result['status']);
    }

    public function testOnlyTransientEventsYieldTransientStatus(): void
    {
        $rows = [
            $this->row('a@example.com', DeliveryStatus::DEFERRED, false, '2024-01-01 10:00:00'),
        ];

        $result = DeliveryRollup::compute($rows);

        $this->assertSame(DeliveryStatus::DEFERRED, $result['status']);
    }

    public function testMissingOccurredAtFallsBackToCreatedAtForRecency(): void
    {
        // Same recipient, both terminal. Spam has no provider timestamp, so its recency must fall
        // back to created_at (09:00) — making delivered (10:00) the deterministic later winner.
        $rows = [
            $this->row('a@example.com', DeliveryStatus::DELIVERED, true, '2024-01-01 10:00:00', '2024-01-01 09:30:00'),
            $this->row('a@example.com', DeliveryStatus::SPAM, true, null, '2024-01-01 09:00:00'),
        ];

        $result = DeliveryRollup::compute($rows);

        $this->assertSame(DeliveryStatus::DELIVERED, $result['status']);
    }

    public function testUpdatedAtIsGreatestEffectiveTimeAcrossAllRows(): void
    {
        $rows = [
            $this->row('a@example.com', DeliveryStatus::DEFERRED, false, '2024-01-01 08:00:00'),
            $this->row('a@example.com', DeliveryStatus::DELIVERED, true, '2024-01-01 12:30:00'),
            // occurred_at absent → effective time is created_at (13:00), the true maximum in the set.
            $this->row('b@example.com', DeliveryStatus::DELIVERED, true, null, '2024-01-01 13:00:00'),
        ];

        $result = DeliveryRollup::compute($rows);

        $this->assertSame('2024-01-01 13:00:00', $result['updated_at']);
    }

    /**
     * @param bool|int $terminal
     *
     * @return array<string,mixed>
     */
    private function row(string $recipient, string $status, $terminal, ?string $occurredAt, ?string $createdAt = null): array
    {
        return [
            'recipient'   => $recipient,
            'status'      => $status,
            'terminal'    => $terminal,
            'occurred_at' => $occurredAt,
            'created_at'  => $createdAt,
        ];
    }
}
