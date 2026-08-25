<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Health\ConnectionHealth;
use BitApps\SMTP\Mail\Health\ConnectionHealthService;
use BitApps\SMTP\Mail\Health\ConnectionHealthStore;
use BitApps\SMTP\Mail\Health\HealthStatus;
use BitApps\SMTP\Mail\Health\HealthTransition;
use BitApps\SMTP\Mail\Notifications\AlertChannelDispatcher;
use BitApps\SMTP\Mail\Notifications\Contracts\NotificationMessage;
use BitApps\SMTP\Mail\Notifications\HealthNotification;
use BitApps\SMTP\Mail\Notifications\HealthNotifier;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
final class HealthNotifierTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_bloginfo')->justReturn('Example Site');
        Functions\when('home_url')->justReturn('https://example.test/');
    }

    public function testUnhealthyTransitionFiresWhenSubscribed(): void
    {
        $this->preferences([HealthNotification::EVENT_UNHEALTHY]);
        $store      = $this->store(['conn_1' => $this->unhealthyRecord()]);
        $dispatcher = $this->dispatcherExpecting(HealthNotification::EVENT_UNHEALTHY, true);

        $this->notifier($dispatcher, $store)->notifyTransitions([$this->transition(HealthStatus::DEGRADED, HealthStatus::UNHEALTHY)]);

        $this->assertSame(HealthNotification::EVENT_UNHEALTHY, $store->records['conn_1']->getLastAlertedState());
        $this->assertNotNull($store->records['conn_1']->getLastAlertedAt());
    }

    public function testNothingFiresWhenTheEventIsNotSubscribed(): void
    {
        $this->preferences([HealthNotification::EVENT_RECOVERED]);
        $store      = $this->store(['conn_1' => $this->unhealthyRecord()]);
        $dispatcher = Mockery::mock(AlertChannelDispatcher::class);
        $dispatcher->shouldReceive('dispatch')->never();

        $this->notifier($dispatcher, $store)->notifyTransitions([$this->transition(HealthStatus::DEGRADED, HealthStatus::UNHEALTHY)]);

        $this->assertNull($store->records['conn_1']->getLastAlertedState());
    }

    public function testAlertOncePerTransitionSuppressesASecondUnhealthyTick(): void
    {
        $this->preferences([HealthNotification::EVENT_UNHEALTHY]);
        $store      = $this->store(['conn_1' => $this->unhealthyRecord()]);
        $dispatcher = $this->dispatcherExpecting(HealthNotification::EVENT_UNHEALTHY, true);

        $notifier   = $this->notifier($dispatcher, $store);
        $transition = $this->transition(HealthStatus::DEGRADED, HealthStatus::UNHEALTHY);

        $notifier->notifyTransitions([$transition]);
        // The marker now records 'connection_unhealthy', so a repeat unhealthy transition must not re-alert.
        $notifier->notifyTransitions([$transition]);
    }

    public function testRecoveryFiresOnTheReverseCrossing(): void
    {
        $this->preferences([HealthNotification::EVENT_RECOVERED]);
        $store      = $this->store(['conn_1' => $this->record([
            'status'             => HealthStatus::HEALTHY,
            'last_alerted_state' => HealthNotification::EVENT_UNHEALTHY,
        ])]);
        $dispatcher = $this->dispatcherExpecting(HealthNotification::EVENT_RECOVERED, true);

        $this->notifier($dispatcher, $store)->notifyTransitions([$this->transition(HealthStatus::UNHEALTHY, HealthStatus::HEALTHY)]);

        $this->assertSame(HealthNotification::EVENT_RECOVERED, $store->records['conn_1']->getLastAlertedState());
    }

    public function testCooldownSuppressesARepeatAlertWithinTheWindow(): void
    {
        $this->preferences([HealthNotification::EVENT_UNHEALTHY], 60);
        $store      = $this->store(['conn_1' => $this->record([
            'status'             => HealthStatus::UNHEALTHY,
            'last_alerted_state' => HealthNotification::EVENT_RECOVERED,
            'last_alerted_at'    => gmdate('Y-m-d H:i:s'),
        ])]);
        $dispatcher = Mockery::mock(AlertChannelDispatcher::class);
        $dispatcher->shouldReceive('dispatch')->never();

        $this->notifier($dispatcher, $store)->notifyTransitions([$this->transition(HealthStatus::DEGRADED, HealthStatus::UNHEALTHY)]);
    }

    public function testOauthExpiringFiresWhenTheTokenHasNoRefreshToken(): void
    {
        $this->preferences([HealthNotification::EVENT_OAUTH_EXPIRING]);
        $connection = $this->connection('conn_oauth', ['token_expires_at' => time() + 3600]);
        $store      = $this->store([]);
        $dispatcher = $this->dispatcherExpecting(HealthNotification::EVENT_OAUTH_EXPIRING, true);

        $this->notifier($dispatcher, $store, [$connection])->notifyOauthExpiry($connection);

        $this->assertSame(HealthNotification::EVENT_OAUTH_EXPIRING, $store->records['conn_oauth']->getLastAlertedState());
    }

    public function testOauthExpiredFiresWhenAlreadyPastAndNoRefreshToken(): void
    {
        $this->preferences([HealthNotification::EVENT_OAUTH_EXPIRED]);
        $connection = $this->connection('conn_oauth', ['token_expires_at' => time() - 10]);
        $store      = $this->store([]);
        $dispatcher = $this->dispatcherExpecting(HealthNotification::EVENT_OAUTH_EXPIRED, true);

        $this->notifier($dispatcher, $store, [$connection])->notifyOauthExpiry($connection);

        $this->assertSame(HealthNotification::EVENT_OAUTH_EXPIRED, $store->records['conn_oauth']->getLastAlertedState());
    }

    public function testARefreshTokenSuppressesTheOauthAlert(): void
    {
        $this->preferences([HealthNotification::EVENT_OAUTH_EXPIRING, HealthNotification::EVENT_OAUTH_EXPIRED]);
        $connection = $this->connection(
            'conn_oauth',
            ['token_expires_at' => time() - 10],
            ['refresh_token'    => ['source' => 'database', 'value' => 'a-refresh-token']]
        );
        $dispatcher = Mockery::mock(AlertChannelDispatcher::class);
        $dispatcher->shouldReceive('dispatch')->never();

        $this->notifier($dispatcher, $this->store([]), [$connection])->notifyOauthExpiry($connection);
    }

    public function testNoMarkerIsPersistedWhenNoChannelDelivers(): void
    {
        $this->preferences([HealthNotification::EVENT_UNHEALTHY]);
        $store      = $this->store(['conn_1' => $this->unhealthyRecord()]);
        $dispatcher = Mockery::mock(AlertChannelDispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andReturn(false);

        $this->notifier($dispatcher, $store)->notifyTransitions([$this->transition(HealthStatus::DEGRADED, HealthStatus::UNHEALTHY)]);

        // Nothing delivered, so the marker stays unset and the next run will retry.
        $this->assertNull($store->records['conn_1']->getLastAlertedState());
    }

    private function notifier(AlertChannelDispatcher $dispatcher, InMemoryHealthStore $store, ?array $connections = null): HealthNotifier
    {
        $config = Mockery::mock(MailConfigService::class);
        $config->shouldReceive('load')->andReturn($this->settings($connections ?? [$this->connection('conn_1')]));

        return new HealthNotifier($dispatcher, new ConnectionHealthService($store, $config));
    }

    private function dispatcherExpecting(string $event, bool $delivered): AlertChannelDispatcher
    {
        $dispatcher = Mockery::mock(AlertChannelDispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(static fn (NotificationMessage $m): bool => $m->toArray()['event'] === $event))
            ->andReturn($delivered);

        return $dispatcher;
    }

    /**
     * @param array<string,ConnectionHealth> $records
     */
    private function store(array $records): InMemoryHealthStore
    {
        $store          = new InMemoryHealthStore();
        $store->records = $records;

        return $store;
    }

    private function transition(string $before, string $after): HealthTransition
    {
        return new HealthTransition($before, $after, $this->connection('conn_1'));
    }

    private function unhealthyRecord(): ConnectionHealth
    {
        return $this->record(['status' => HealthStatus::UNHEALTHY]);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function record(array $overrides): ConnectionHealth
    {
        return ConnectionHealth::fromArray($overrides);
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $credentials
     */
    private function connection(string $id, array $settings = [], array $credentials = []): Connection
    {
        return Connection::fromArray([
            'id'          => $id,
            'provider'    => 'gmail',
            'kind'        => 'api',
            'name'        => $id,
            'settings'    => $settings,
            'credentials' => $credentials,
        ]);
    }

    /**
     * @param Connection[] $connections
     */
    private function settings(array $connections): MailSettings
    {
        return MailSettings::fromArray([
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => '',
            'fallback_connection_ids' => [],
            'connections'             => array_map(static fn (Connection $c): array => $c->toArray(), $connections),
            'features'                => [],
        ]);
    }

    /**
     * @param string[] $events
     */
    private function preferences(array $events, int $cooldownMinutes = 0): void
    {
        Functions\when('get_option')->justReturn([
            'notify_events'           => $events,
            'notify_cooldown_minutes' => $cooldownMinutes,
        ]);
    }
}

/**
 * In-memory ConnectionHealthStore double so the notifier test drives dedup/persistence across ticks
 * without touching WordPress options.
 */
final class InMemoryHealthStore extends ConnectionHealthStore
{
    /**
     * @var array<string,ConnectionHealth>
     */
    public array $records = [];

    public function all(): array
    {
        return $this->records;
    }

    public function get(string $id): ?ConnectionHealth
    {
        return $this->records[$id] ?? null;
    }

    public function put(string $id, ConnectionHealth $health): void
    {
        $this->records[$id] = $health;
    }

    public function putMany(array $records): void
    {
        $this->records = $records;
    }

    public function pruneTo(array $liveIds): void
    {
        $this->records = array_intersect_key($this->records, array_flip($liveIds));
    }

    public function delete(string $id): void
    {
        unset($this->records[$id]);
    }
}
