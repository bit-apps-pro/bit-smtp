<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Health\HealthStatus;
use BitApps\SMTP\Mail\Notifications\AlertChannelDispatcher;
use BitApps\SMTP\Mail\Notifications\AlertScheduler;
use BitApps\SMTP\Mail\Notifications\HealthNotification;
use BitApps\SMTP\Mail\Notifications\HealthNotifier;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use BitApps\SMTP\Tests\Unit\Mail\Notifications\Support\HealthAlertFixtures;
use BitApps\SMTP\Tests\Unit\Mail\Notifications\Support\InMemoryHealthStore;
use Brain\Monkey\Functions;

/**
 * @internal
 *
 * @coversNothing
 */
final class HealthNotifierTest extends BaseUnitTestCase
{
    use HealthAlertFixtures;

    private const NOW = 1700000000;

    /**
     * @var list<array{timestamp:int,hook:string,args:array<int,mixed>}>
     */
    private array $scheduled = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_bloginfo')->justReturn('Example Site');
        Functions\when('home_url')->justReturn('https://example.test/');
        Functions\when('wp_doing_cron')->justReturn(false);
        Functions\when('wp_schedule_single_event')->alias(function (int $timestamp, string $hook, array $args): bool {
            $this->scheduled[] = ['timestamp' => $timestamp, 'hook' => $hook, 'args' => $args];

            return true;
        });
        Functions\when('_get_cron_array')->alias(fn (): array => array_map(
            static fn (array $event): array => [$event['hook'] => [md5(serialize($event['args'])) => ['args' => $event['args']]]],
            $this->scheduled
        ));
    }

    public function testAnUnhealthyTransitionIsQueuedWithIdentifiersOnlyAndNotDeliveredInline(): void
    {
        $this->preferences([HealthNotification::EVENT_UNHEALTHY]);
        $dispatcher = $this->dispatcher();
        $dispatcher->shouldReceive('dispatch')->never();
        $store = $this->store(['conn_1' => $this->record(['status' => HealthStatus::UNHEALTHY])]);

        $this->notifier($dispatcher, $store)->notifyTransitions([$this->transition(HealthStatus::DEGRADED, HealthStatus::UNHEALTHY)]);

        $this->assertSame(
            [['timestamp' => self::NOW, 'hook' => Config::HEALTH_ALERT_HOOK, 'args' => [HealthNotification::EVENT_UNHEALTHY, 'conn_1', self::NOW]]],
            $this->scheduled
        );
        $this->assertNull($store->records['conn_1']->getLastAlertedState());
    }

    public function testARecoveryIsQueuedOnTheReverseCrossing(): void
    {
        $this->preferences([HealthNotification::EVENT_RECOVERED]);
        $store = $this->store(['conn_1' => $this->record(['status' => HealthStatus::HEALTHY, 'last_alerted_state' => HealthNotification::EVENT_UNHEALTHY])]);

        $this->notifier($this->dispatcher(), $store)->notifyTransitions([$this->transition(HealthStatus::UNHEALTHY, HealthStatus::HEALTHY)]);

        $this->assertSame([HealthNotification::EVENT_RECOVERED, 'conn_1', self::NOW], $this->scheduled[0]['args']);
    }

    public function testNothingIsQueuedForANonAlertableTransition(): void
    {
        $this->preferences([HealthNotification::EVENT_UNHEALTHY, HealthNotification::EVENT_RECOVERED]);

        $this->notifier($this->dispatcher(), $this->store([]))->notifyTransitions([
            $this->transition(HealthStatus::HEALTHY, HealthStatus::DEGRADED),
            $this->transition(HealthStatus::DEGRADED, HealthStatus::HEALTHY),
        ]);

        $this->assertSame([], $this->scheduled);
    }

    public function testNothingIsQueuedWhenTheEventIsNotSubscribed(): void
    {
        $this->preferences([HealthNotification::EVENT_RECOVERED]);

        $this->notifier($this->dispatcher(), $this->store(['conn_1' => $this->record(['status' => HealthStatus::UNHEALTHY])]))
            ->notifyTransitions([$this->transition(HealthStatus::DEGRADED, HealthStatus::UNHEALTHY)]);

        $this->assertSame([], $this->scheduled);
    }

    public function testNothingIsQueuedWhenTheStateWasAlreadyAlerted(): void
    {
        $this->preferences([HealthNotification::EVENT_UNHEALTHY]);
        $store = $this->store(['conn_1' => $this->record([
            'status'             => HealthStatus::UNHEALTHY,
            'last_alerted_state' => HealthNotification::EVENT_UNHEALTHY,
        ])]);

        $this->notifier($this->dispatcher(), $store)->notifyTransitions([$this->transition(HealthStatus::DEGRADED, HealthStatus::UNHEALTHY)]);

        $this->assertSame([], $this->scheduled);
    }

    public function testNothingIsQueuedWithoutAnEnabledChannel(): void
    {
        $this->preferences([HealthNotification::EVENT_UNHEALTHY]);

        $this->notifier($this->dispatcher(false), $this->store(['conn_1' => $this->record(['status' => HealthStatus::UNHEALTHY])]))
            ->notifyTransitions([$this->transition(HealthStatus::DEGRADED, HealthStatus::UNHEALTHY)]);

        $this->assertSame([], $this->scheduled);
    }

    public function testInsideWpCronTheAlertIsDeliveredDirectly(): void
    {
        Functions\when('wp_doing_cron')->justReturn(true);
        $this->preferences([HealthNotification::EVENT_UNHEALTHY]);
        $store = $this->store(['conn_1' => $this->record(['status' => HealthStatus::UNHEALTHY])]);

        $this->notifier($this->dispatcherExpecting(HealthNotification::EVENT_UNHEALTHY, true), $store)
            ->notifyTransitions([$this->transition(HealthStatus::DEGRADED, HealthStatus::UNHEALTHY)]);

        $this->assertSame([], $this->scheduled);
        $this->assertSame(HealthNotification::EVENT_UNHEALTHY, $store->records['conn_1']->getLastAlertedState());
    }

    public function testAnExpiringOauthTokenIsQueued(): void
    {
        $this->preferences([HealthNotification::EVENT_OAUTH_EXPIRING]);
        $connection = $this->connection('conn_oauth', ['token_expires_at' => self::NOW + 3600]);

        $this->notifier($this->dispatcher(), $this->store([]), [$connection])->notifyOauthExpiry($connection);

        $this->assertSame([HealthNotification::EVENT_OAUTH_EXPIRING, 'conn_oauth', self::NOW], $this->scheduled[0]['args']);
    }

    public function testALevelTriggeredOauthAlertIsNotQueuedAgainWhileOneIsPending(): void
    {
        $this->preferences([HealthNotification::EVENT_OAUTH_EXPIRING]);
        $connection = $this->connection('conn_oauth', ['token_expires_at' => self::NOW + 3600]);
        $notifier   = $this->notifier($this->dispatcher(), $this->store([]), [$connection]);

        $notifier->notifyOauthExpiry($connection);
        $notifier->notifyOauthExpiry($connection);

        $this->assertCount(1, $this->scheduled);
    }

    public function testAPendingEventForAnotherConnectionDoesNotBlockQueueing(): void
    {
        $this->preferences([HealthNotification::EVENT_OAUTH_EXPIRING]);
        $first    = $this->connection('conn_a', ['token_expires_at' => self::NOW + 3600]);
        $second   = $this->connection('conn_b', ['token_expires_at' => self::NOW + 3600]);
        $notifier = $this->notifier($this->dispatcher(), $this->store([]), [$first, $second]);

        $notifier->notifyOauthExpiry($first);
        $notifier->notifyOauthExpiry($second);

        $this->assertSame(['conn_a', 'conn_b'], array_map(static fn (array $e): string => $e['args'][1], $this->scheduled));
    }

    public function testARefreshTokenSuppressesTheOauthAlert(): void
    {
        $this->preferences([HealthNotification::EVENT_OAUTH_EXPIRING, HealthNotification::EVENT_OAUTH_EXPIRED]);
        $connection = $this->connection(
            'conn_oauth',
            ['token_expires_at' => self::NOW - 10],
            ['refresh_token'    => ['source' => 'database', 'value' => 'a-refresh-token']]
        );

        $this->notifier($this->dispatcher(), $this->store([]), [$connection])->notifyOauthExpiry($connection);

        $this->assertSame([], $this->scheduled);
    }

    /**
     * A request-path notifier over a real handler, scheduler, and fixed clock.
     *
     * @param null|Connection[] $connections
     */
    private function notifier(AlertChannelDispatcher $dispatcher, InMemoryHealthStore $store, ?array $connections = null): HealthNotifier
    {
        return new HealthNotifier($this->handler($dispatcher, $store, $connections), new AlertScheduler(), static fn (): int => self::NOW);
    }
}
