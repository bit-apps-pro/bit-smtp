<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications\Support;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Health\ConnectionHealth;
use BitApps\SMTP\Mail\Health\ConnectionHealthService;
use BitApps\SMTP\Mail\Health\HealthTransition;
use BitApps\SMTP\Mail\Notifications\AlertChannelDispatcher;
use BitApps\SMTP\Mail\Notifications\Contracts\NotificationMessage;
use BitApps\SMTP\Mail\Notifications\HealthAlertHandler;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Shared wiring for health-alert tests: in-memory health store, handler, and builders for connections/transitions.
 */
trait HealthAlertFixtures
{
    /**
     * The shared alerts repeat interval (`reminder_minutes`) the mocked dispatcher reports.
     */
    private int $repeatMinutes = 0;

    /**
     * A delivery handler over the given dispatcher and store, resolving the given connections by id.
     *
     * @param null|Connection[] $connections
     */
    private function handler(AlertChannelDispatcher $dispatcher, InMemoryHealthStore $store, ?array $connections = null): HealthAlertHandler
    {
        $connections = $connections ?? [$this->connection('conn_1')];
        $config      = Mockery::mock(MailConfigService::class);
        $config->shouldReceive('load')->andReturn($this->settings($connections));
        $byId = [];
        foreach ($connections as $connection) {
            $byId[$connection->getId()] = $connection;
        }
        $config->shouldReceive('connectionById')->andReturnUsing(static fn (string $id): ?Connection => $byId[$id] ?? null);

        return new HealthAlertHandler($dispatcher, new ConnectionHealthService($store, $config), $config);
    }

    /**
     * A dispatcher with an enabled channel that must deliver exactly one alert for $event.
     */
    private function dispatcherExpecting(string $event, bool $delivered): AlertChannelDispatcher
    {
        $dispatcher = $this->dispatcher();
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(static fn (NotificationMessage $m): bool => $m->toArray()['event'] === $event))
            ->andReturn($delivered);

        return $dispatcher;
    }

    /**
     * A dispatcher mock reporting whether any channel is enabled.
     *
     * @return AlertChannelDispatcher&Mockery\MockInterface
     */
    private function dispatcher(bool $hasEnabledChannel = true): AlertChannelDispatcher
    {
        $dispatcher = Mockery::mock(AlertChannelDispatcher::class);
        $dispatcher->shouldReceive('hasEnabledChannel')->andReturn($hasEnabledChannel);
        $dispatcher->shouldReceive('alerts')->andReturnUsing(fn (): array => [
            'enabled'          => $hasEnabledChannel,
            'reminder_minutes' => $this->repeatMinutes,
            'channels'         => [],
        ]);

        return $dispatcher;
    }

    /**
     * A store seeded with the given records.
     *
     * @param array<string,ConnectionHealth> $records
     */
    private function store(array $records): InMemoryHealthStore
    {
        $store          = new InMemoryHealthStore();
        $store->records = $records;

        return $store;
    }

    /**
     * A status transition of the default connection.
     */
    private function transition(string $before, string $after): HealthTransition
    {
        return new HealthTransition($before, $after, $this->connection('conn_1'));
    }

    /**
     * A health record with the given fields.
     *
     * @param array<string,mixed> $overrides
     */
    private function record(array $overrides): ConnectionHealth
    {
        return ConnectionHealth::fromArray($overrides);
    }

    /**
     * An API connection with the given settings and credentials.
     *
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
     * Mail settings holding the given connections.
     *
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
     * Subscribe to the given events with the given shared repeat interval.
     *
     * @param string[] $events
     */
    private function preferences(array $events, int $repeatMinutes = 0): void
    {
        $this->repeatMinutes = $repeatMinutes;
        Functions\when('get_option')->justReturn(['notify_events' => $events]);
    }
}
