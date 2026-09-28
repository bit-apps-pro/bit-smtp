<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Cron;

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPKit\Container\Application;
use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Providers\CoreServiceProvider;
use BitApps\SMTP\Providers\InstallerProvider;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers Task 17: retention cleanup cron wiring via Scheduler, CoreServiceProvider boot, deactivate cleanup.
 *
 * @internal
 *
 * @coversNothing
 */
final class RetentionCronTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_option')->justReturn(false);
    }

    public function testBootRegistersTheRetentionHookAndSchedulesItWhenNotAlreadyScheduled(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\expect('add_action')
            ->once()
            ->withArgs(static function (string $hook, $callback, int $priority, int $acceptedArgs): bool {
                return $hook === Config::RETENTION_GC_HOOK && \is_callable($callback) && $priority === 10 && $acceptedArgs === 0;
            });
        Functions\expect('wp_schedule_event')
            ->once()
            ->withArgs(static function ($timestamp, string $recurrence, string $hook, array $args): bool {
                return \is_int($timestamp) && $recurrence === 'daily' && $hook === Config::RETENTION_GC_HOOK && $args === [];
            })
            ->andReturn(true);

        $app = new Application();
        $app->register(new CoreServiceProvider($app));
        $app->boot();
    }

    public function testBootDoesNotRescheduleWhenAlreadyScheduled(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(12345);
        Functions\expect('add_action')
            ->once()
            ->withArgs(static function (string $hook, $callback): bool {
                return $hook === Config::RETENTION_GC_HOOK && \is_callable($callback);
            });
        Functions\expect('wp_schedule_event')->never();

        $app = new Application();
        $app->register(new CoreServiceProvider($app));
        $app->boot();
    }

    public function testTheRegisteredCallbackResolvesLogServiceFromTheContainerAndCallsDeleteOlder(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(true);

        $capturedCallback = null;
        Functions\expect('add_action')
            ->once()
            ->withArgs(static function (string $hook, $callback) use (&$capturedCallback): bool {
                if ($hook !== Config::RETENTION_GC_HOOK) {
                    return false;
                }
                $capturedCallback = $callback;

                return true;
            });

        $logService = Mockery::mock(LogService::class);
        $logService->shouldReceive('deleteOlder')->once();

        $app = new Application();
        $app->register(new CoreServiceProvider($app));
        $app->singleton(LogService::class, static fn (): LogService => $logService);
        $app->boot();

        self::assertIsCallable($capturedCallback, 'boot() must register a callable on the retention hook');
        $capturedCallback();
    }

    public function testDeactivateUnschedulesTheRetentionCronHookByExplicitNameEvenWithoutAnyJobRegisteredThisRequest(): void
    {
        Functions\when('register_activation_hook')->justReturn(null);
        Functions\when('register_deactivation_hook')->justReturn(null);
        Functions\when('register_uninstall_hook')->justReturn(null);
        $unscheduledHooks = [];
        Functions\when('wp_unschedule_hook')->alias(static function (string $hook) use (&$unscheduledHooks): void {
            $unscheduledHooks[] = $hook;
        });
        Functions\expect('delete_option')
            ->once()
            ->with(Config::withPrefix('failure_resolved_incidents'));

        (new InstallerProvider())->deactivate(false);

        self::assertSame(Config::scheduledHooks(), $unscheduledHooks);
    }
}
