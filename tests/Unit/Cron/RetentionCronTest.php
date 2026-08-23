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
 * Covers Task 17: the retention cleanup cron wiring (Scheduler as the first cron consumer).
 * deleteOlder()'s deletion logic itself is unchanged and stays integration-covered
 * (tests/Integration/LogServiceDeliveryCleanupTest.php); this suite only proves the wiring:
 * CoreServiceProvider configures + boots the Scheduler, and deactivate clears the cron by name.
 *
 * add_action() is asserted directly (rather than via has_action()/do_action()) because Brain
 * Monkey's do_action() only replays registered *expectations*, it never actually invokes a
 * callback added through add_action(); capturing the callback is the only way to prove it's wired
 * to LogService::deleteOlder().
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
        // Swap in a spy after register(): the closure Scheduler::job() captured resolves LogService
        // from the container lazily when the cron callback fires, so this override is picked up
        // without ever constructing the real LogService (which would need a DB this test lacks).
        $app->singleton(LogService::class, static fn (): LogService => $logService);
        $app->boot();

        self::assertIsCallable($capturedCallback, 'boot() must register a callable on the retention hook');
        $capturedCallback();
    }

    public function testDeactivateClearsTheRetentionCronHookByExplicitNameEvenWithoutAnyJobRegisteredThisRequest(): void
    {
        Functions\when('register_activation_hook')->justReturn(null);
        Functions\when('register_deactivation_hook')->justReturn(null);
        Functions\when('register_uninstall_hook')->justReturn(null);
        Functions\expect('wp_clear_scheduled_hook')
            ->once()
            ->with(Config::RETENTION_GC_HOOK);

        // A fresh InstallerProvider, never touching CoreServiceProvider/Scheduler: proves the clear
        // doesn't depend on a Scheduler instance having had job() called this request (fixes M5).
        (new InstallerProvider())->deactivate(false);
    }
}
