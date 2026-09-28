<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Notifications\AlertDeliveryStatus;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;

/**
 * @internal
 *
 * @coversNothing
 */
final class AlertDeliveryStatusTest extends BaseUnitTestCase
{
    private const NOW = 1700000000;

    public function testNothingQueuedIsNotOverdue(): void
    {
        Functions\when('_get_cron_array')->justReturn([]);

        $this->assertSame(['cron_disabled' => false, 'overdue' => false], AlertDeliveryStatus::snapshot(self::NOW));
    }

    public function testARecentlyDueAlertIsNotYetOverdue(): void
    {
        $this->cron([self::NOW - AlertDeliveryStatus::OVERDUE_AFTER_SECONDS + 1 => Config::FAILURE_ALERT_HOOK]);

        $this->assertFalse(AlertDeliveryStatus::snapshot(self::NOW)['overdue']);
    }

    public function testAFailureAlertDueOverTenMinutesAgoIsOverdue(): void
    {
        $this->cron([self::NOW - AlertDeliveryStatus::OVERDUE_AFTER_SECONDS - 1 => Config::FAILURE_ALERT_HOOK]);

        $this->assertTrue(AlertDeliveryStatus::snapshot(self::NOW)['overdue']);
    }

    public function testAHealthAlertDueOverTenMinutesAgoIsOverdue(): void
    {
        $this->cron([self::NOW - 3600 => Config::HEALTH_ALERT_HOOK]);

        $this->assertTrue(AlertDeliveryStatus::snapshot(self::NOW)['overdue']);
    }

    public function testOtherLateHooksDoNotCount(): void
    {
        $this->cron([self::NOW - 3600 => Config::RETENTION_GC_HOOK]);

        $this->assertFalse(AlertDeliveryStatus::snapshot(self::NOW)['overdue']);
    }

    /**
     * Stub the cron array with one event per timestamp => hook.
     *
     * @param array<int,string> $events
     */
    private function cron(array $events): void
    {
        $crons = [];
        foreach ($events as $timestamp => $hook) {
            $crons[$timestamp] = [$hook => ['key' => ['schedule' => false, 'args' => []]]];
        }
        Functions\when('_get_cron_array')->justReturn($crons);
    }
}
