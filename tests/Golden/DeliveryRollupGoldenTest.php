<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Golden;

use BitApps\SMTP\Mail\Webhook\DeliveryRollup;
use BitApps\SMTP\Tests\Golden\Support\GoldenTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Freezes DeliveryRollup::compute() over representative child-event row sets (shape from
 * LogService::deliveryEvents()). A later PR introducing a DeliveryState enum / VO must reproduce
 * these {status, updated_at} outputs byte-for-byte.
 *
 * @internal
 *
 * @coversNothing
 */
final class DeliveryRollupGoldenTest extends GoldenTestCase
{
    /**
     * @return iterable<string, array{0: list<array<string,mixed>>, 1: string}>
     */
    public static function rowSets(): iterable
    {
        yield 'delivered_then_deferred' => [[
            ['recipient' => 'a@x.test', 'status' => 'deferred', 'terminal' => 0, 'occurred_at' => '2024-01-01 10:00:00', 'created_at' => '2024-01-01 10:00:00'],
            ['recipient' => 'a@x.test', 'status' => 'delivered', 'terminal' => 1, 'occurred_at' => '2024-01-01 10:05:00', 'created_at' => '2024-01-01 10:05:00'],
        ], 'delivered_then_deferred'];

        yield 'bounced' => [[
            ['recipient' => 'b@x.test', 'status' => 'bounced', 'terminal' => 1, 'occurred_at' => '2024-01-01 10:00:00', 'created_at' => '2024-01-01 10:00:00'],
        ], 'bounced'];

        yield 'multi_recipient_mixed_outcomes' => [[
            ['recipient' => 'c1@x.test', 'status' => 'delivered', 'terminal' => 1, 'occurred_at' => '2024-01-01 10:00:00', 'created_at' => '2024-01-01 10:00:00'],
            ['recipient' => 'c2@x.test', 'status' => 'bounced', 'terminal' => 1, 'occurred_at' => '2024-01-01 10:01:00', 'created_at' => '2024-01-01 10:01:00'],
        ], 'multi_recipient_mixed_outcomes'];

        yield 'non_terminal_only_falls_back_to_occurred_at' => [[
            ['recipient' => 'd@x.test', 'status' => 'accepted', 'terminal' => 0, 'occurred_at' => null, 'created_at' => '2024-01-01 09:00:00'],
        ], 'non_terminal_only_falls_back_to_occurred_at'];

        yield 'empty' => [[], 'empty'];
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    #[DataProvider('rowSets')]
    public function testRollupGolden(array $rows, string $name): void
    {
        $this->assertMatchesGolden(DeliveryRollup::compute($rows), 'rollup_' . $name);
    }
}
