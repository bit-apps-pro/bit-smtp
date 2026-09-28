<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Cron;

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPKit\Container\Application;
use BitApps\SMTP\Mail\Dispatch\RetryWorker;
use BitApps\SMTP\Providers\CoreServiceProvider;
use BitApps\SMTP\Providers\InstallerProvider;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers retry-queue cron wiring: 5-minute schedule, in-callback retry_enabled guard, deactivate cleanup.
 *
 * @internal
 *
 * @coversNothing
 */
final class RetryCronTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_option')->justReturn(false);
    }

    public function testBootRegistersTheRetryHookAndScheduleWhenNotAlreadyScheduled(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\expect('add_action')
            ->withArgs(static function (string $hook, $callback, int $priority, int $acceptedArgs): bool {
                return $hook === Config::RETRY_QUEUE_HOOK && \is_callable($callback) && $priority === 10 && $acceptedArgs === 0;
            })
            ->once();
        Functions\expect('wp_schedule_event')
            ->withArgs(static function ($timestamp, string $recurrence, string $hook, array $args): bool {
                return \is_int($timestamp) && $recurrence === 'bit_smtp_five_minutes' && $hook === Config::RETRY_QUEUE_HOOK && $args === [];
            })
            ->once()
            ->andReturn(true);

        $app = new Application();
        $app->register(new CoreServiceProvider($app));
        $app->boot();
    }

    public function testRetryCallbackSkipsResolvingRetryWorkerWhenRetryIsDisabled(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(true);

        $capturedCallback = null;
        Functions\when('add_action')->alias(static function (string $hook, $callback) use (&$capturedCallback): void {
            if ($hook === Config::RETRY_QUEUE_HOOK) {
                $capturedCallback = $callback;
            }
        });

        $app = new Application();
        $app->register(new CoreServiceProvider($app));
        $app->singleton(RetryWorker::class, static function (): RetryWorker {
            self::fail('RetryWorker must not be resolved when retry_enabled is false.');
        });
        $app->boot();

        self::assertIsCallable($capturedCallback, 'boot() must register a callable on the retry hook');
        $capturedCallback();
    }

    public function testRetryCallbackResolvesRetryWorkerFromTheContainerAndCallsProcessWhenEnabled(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(true);
        Functions\when('get_option')->justReturn(['retry_enabled' => true]);

        $capturedCallback = null;
        Functions\when('add_action')->alias(static function (string $hook, $callback) use (&$capturedCallback): void {
            if ($hook === Config::RETRY_QUEUE_HOOK) {
                $capturedCallback = $callback;
            }
        });

        $worker = Mockery::mock(RetryWorker::class);
        $worker->shouldReceive('process')->once();

        $app = new Application();
        $app->register(new CoreServiceProvider($app));
        $app->singleton(RetryWorker::class, static fn (): RetryWorker => $worker);
        $app->boot();

        self::assertIsCallable($capturedCallback, 'boot() must register a callable on the retry hook');
        $capturedCallback();
    }

    public function testDeactivateUnschedulesTheRetryCronHookByExplicitName(): void
    {
        Functions\when('register_activation_hook')->justReturn(null);
        Functions\when('register_deactivation_hook')->justReturn(null);
        Functions\when('register_uninstall_hook')->justReturn(null);

        $unscheduledHooks = [];
        Functions\when('wp_unschedule_hook')->alias(static function (string $hook) use (&$unscheduledHooks): void {
            $unscheduledHooks[] = $hook;
        });
        Functions\when('delete_option')->justReturn(true);

        (new InstallerProvider())->deactivate(false);

        self::assertContains(Config::RETRY_QUEUE_HOOK, $unscheduledHooks);
    }
}
