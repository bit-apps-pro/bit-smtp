<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Notifications\AlertScheduler;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * @internal
 *
 * @coversNothing
 */
final class AlertSchedulerTest extends BaseUnitTestCase
{
    /**
     * @var list<array{0:int,1:string,2:array<int,mixed>}>
     */
    private array $scheduled = [];

    private bool $scheduleResult = true;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_doing_cron')->justReturn(false);
        Functions\when('wp_schedule_single_event')->alias(function (int $timestamp, string $hook, array $args): bool {
            $this->scheduled[] = [$timestamp, $hook, $args];

            return $this->scheduleResult;
        });
    }

    public function testQueueSchedulesTheEventAndKicksCronOnceAtShutdown(): void
    {
        Actions\expectAdded('shutdown')->once();
        $scheduler = new AlertScheduler();

        $scheduler->queue(Config::FAILURE_ALERT_HOOK, ['send_failure.opened', 'incident-1', 1], 100);
        $scheduler->queue(Config::HEALTH_ALERT_HOOK, ['connection_unhealthy', 'conn_1', 100], 100);

        $this->assertSame([
            [100, Config::FAILURE_ALERT_HOOK, ['send_failure.opened', 'incident-1', 1]],
            [100, Config::HEALTH_ALERT_HOOK, ['connection_unhealthy', 'conn_1', 100]],
        ], $this->scheduled);
    }

    public function testTheShutdownKickSpawnsCron(): void
    {
        $kick = null;
        Actions\expectAdded('shutdown')->once()->whenHappen(static function (callable $callback) use (&$kick): void {
            $kick = $callback;
        });
        Functions\expect('spawn_cron')->once();

        (new AlertScheduler())->queue(Config::FAILURE_ALERT_HOOK, ['send_failure.opened', 'incident-1', 1], 100);

        $this->assertIsCallable($kick);
        $kick();
    }

    public function testNoKickInsideWpCron(): void
    {
        Functions\when('wp_doing_cron')->justReturn(true);
        Actions\expectAdded('shutdown')->never();

        (new AlertScheduler())->queue(Config::FAILURE_ALERT_HOOK, ['send_failure.opened', 'incident-1', 1], 100);

        $this->assertCount(1, $this->scheduled);
    }

    public function testNoKickWhenWordPressRejectsTheEvent(): void
    {
        $this->scheduleResult = false;
        Actions\expectAdded('shutdown')->never();

        (new AlertScheduler())->queue(Config::FAILURE_ALERT_HOOK, ['send_failure.opened', 'incident-1', 1], 100);

        $this->assertCount(1, $this->scheduled);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNoKickInAlternateWpCronMode(): void
    {
        \define('ALTERNATE_WP_CRON', true);
        Actions\expectAdded('shutdown')->never();

        (new AlertScheduler())->queue(Config::FAILURE_ALERT_HOOK, ['send_failure.opened', 'incident-1', 1], 100);

        $this->assertCount(1, $this->scheduled);
    }

    public function testHasPendingMatchesQueuedEventsByLeadingArgs(): void
    {
        Functions\when('_get_cron_array')->justReturn([
            100 => [Config::HEALTH_ALERT_HOOK => ['k1' => ['args' => ['oauth_expiring', 'conn_1', 100]]]],
            200 => [Config::FAILURE_ALERT_HOOK => ['k2' => ['args' => ['oauth_expiring', 'conn_2', 200]]]],
        ]);
        $scheduler = new AlertScheduler();

        $this->assertTrue($scheduler->hasPending(Config::HEALTH_ALERT_HOOK, ['oauth_expiring', 'conn_1']));
        $this->assertFalse($scheduler->hasPending(Config::HEALTH_ALERT_HOOK, ['oauth_expired', 'conn_1']));
        $this->assertFalse($scheduler->hasPending(Config::HEALTH_ALERT_HOOK, ['oauth_expiring', 'conn_2']));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNoKickWhenWpCronIsDisabled(): void
    {
        \define('DISABLE_WP_CRON', true);
        Actions\expectAdded('shutdown')->never();

        (new AlertScheduler())->queue(Config::FAILURE_ALERT_HOOK, ['send_failure.opened', 'incident-1', 1], 100);

        $this->assertCount(1, $this->scheduled);
        $this->assertTrue(AlertScheduler::isWpCronDisabled());
    }
}
