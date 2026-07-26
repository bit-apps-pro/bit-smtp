<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Notifications\FailureNotificationGate;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
final class FailureNotificationGateTest extends BaseUnitTestCase
{
    public function testAcquireUsesAtomicAddOptionResult(): void
    {
        Functions\expect('add_option')
            ->once()
            ->with(
                Config::withPrefix('failure_notification_active'),
                Mockery::on(static function (array $marker): bool {
                    return \count($marker) === 1 && isset($marker['started_at']);
                }),
                '',
                'no'
            )
            ->andReturn(true);

        $gate = new FailureNotificationGate();

        $this->assertTrue($gate->acquire());
    }

    public function testResetDeletesTheIncidentOption(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with(Config::withPrefix('failure_notification_active'), null)
            ->andReturn(['started_at' => 'now']);
        Functions\expect('delete_option')
            ->once()
            ->with(Config::withPrefix('failure_notification_active'))
            ->andReturn(true);

        (new FailureNotificationGate())->reset();
    }

    public function testResetSkipsDeleteWhenNoIncidentActive(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with(Config::withPrefix('failure_notification_active'), null)
            ->andReturn(null);
        Functions\expect('delete_option')->never();

        (new FailureNotificationGate())->reset();
    }
}
