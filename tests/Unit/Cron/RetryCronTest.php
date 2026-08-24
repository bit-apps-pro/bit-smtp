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
 * Covers the retry-queue cron wiring (the Scheduler's second consumer, alongside retention): the
 * every-5-minutes schedule/job registration, the in-callback retry_enabled guard (so toggling the
 * preference takes effect without re-scheduling), and the deactivate-time cron clear.
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
        // Default get_option stub (setUp) means retry_enabled reads its schema default of false.

        $capturedCallback = null;
        Functions\when('add_action')->alias(static function (string $hook, $callback) use (&$capturedCallback): void {
            if ($hook === Config::RETRY_QUEUE_HOOK) {
                $capturedCallback = $callback;
            }
        });

        $app = new Application();
        $app->register(new CoreServiceProvider($app));
        // A container that fatals if RetryWorker is ever resolved proves the guard short-circuits
        // before the container is touched, not merely that process() happens not to have been called.
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

    public function testDeactivateClearsTheRetryCronHookByExplicitName(): void
    {
        Functions\when('register_activation_hook')->justReturn(null);
        Functions\when('register_deactivation_hook')->justReturn(null);
        Functions\when('register_uninstall_hook')->justReturn(null);

        $clearedHooks = [];
        Functions\when('wp_clear_scheduled_hook')->alias(static function (string $hook) use (&$clearedHooks): void {
            $clearedHooks[] = $hook;
        });

        (new InstallerProvider())->deactivate(false);

        self::assertContains(Config::RETRY_QUEUE_HOOK, $clearedHooks);
    }
}
