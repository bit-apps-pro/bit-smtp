<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Health;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Dispatch\FailureCategory;
use BitApps\SMTP\Mail\Health\ConnectionHealth;
use BitApps\SMTP\Mail\Health\ConnectionHealthService;
use BitApps\SMTP\Mail\Health\ConnectionHealthStore;
use BitApps\SMTP\Mail\Health\Contracts\ConnectionProbeInterface;
use BitApps\SMTP\Mail\Health\HealthProbeResolver;
use BitApps\SMTP\Mail\Health\HealthProbeRunner;
use BitApps\SMTP\Mail\Health\HealthStatus;
use BitApps\SMTP\Mail\Health\ProbeResult;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
final class HealthProbeRunnerTest extends BaseUnitTestCase
{
    public function testItProbesProbeableConnectionsSkipsApiAndReturnsTransitions(): void
    {
        // run() must NOT stamp LAST_RUN_OPTION (the cron callback owns the interval marker so a manual
        // "Check now" never shifts the schedule).
        Functions\expect('update_option')->never();

        $settings = $this->settings([
            $this->connection('conn_smtp', 'smtp', ['token_expires_at' => 1893456000]),
            $this->connection('conn_api', 'api'),
        ]);
        $config = Mockery::mock(MailConfigService::class);
        $config->shouldReceive('load')->andReturn($settings);

        $store   = new ArrayHealthStore();
        $service = new ConnectionHealthService($store, $config);

        $probeResult = ProbeResult::failure('Could not connect', FailureCategory::TRANSIENT);
        $probe       = Mockery::mock(ConnectionProbeInterface::class);
        $probe->shouldReceive('probe')
            ->once()
            ->with(Mockery::on(static fn (Connection $c): bool => $c->getId() === 'conn_smtp'))
            ->andReturn($probeResult);

        $resolver = Mockery::mock(HealthProbeResolver::class);
        $resolver->shouldReceive('probeFor')
            ->with(Mockery::on(static fn (Connection $c): bool => $c->getId() === 'conn_smtp'))
            ->andReturn($probe);
        $resolver->shouldReceive('probeFor')
            ->with(Mockery::on(static fn (Connection $c): bool => $c->getId() === 'conn_api'))
            ->andReturnNull();

        $transitions = (new HealthProbeRunner($config, $resolver, $service))->run();

        self::assertCount(1, $transitions);
        self::assertSame(HealthStatus::UNKNOWN, $transitions[0]->before());
        self::assertSame(HealthStatus::DEGRADED, $transitions[0]->after());

        // The API connection has no active probe (passive-only), so it is never recorded here.
        self::assertArrayHasKey('conn_smtp', $store->records);
        self::assertArrayNotHasKey('conn_api', $store->records);

        $record = $store->records['conn_smtp'];
        self::assertNotNull($record->getLastProbeAt());
        self::assertSame(1893456000, $record->getOauthExpiresAt());
        self::assertTrue($record->isDegraded());
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
     * @param array<string,mixed> $settings
     */
    private function connection(string $id, string $kind, array $settings = []): Connection
    {
        return Connection::fromArray([
            'id'       => $id,
            'provider' => 'provider',
            'kind'     => $kind,
            'settings' => $settings,
        ]);
    }
}

/**
 * Array-backed ConnectionHealthStore double so the runner test asserts recorded state without
 * touching WordPress options.
 */
final class ArrayHealthStore extends ConnectionHealthStore
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
