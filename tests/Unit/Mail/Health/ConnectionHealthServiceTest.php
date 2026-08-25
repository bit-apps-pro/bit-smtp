<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Health;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Dispatch\FailureCategory;
use BitApps\SMTP\Mail\Health\ConnectionHealth;
use BitApps\SMTP\Mail\Health\ConnectionHealthService;
use BitApps\SMTP\Mail\Health\ConnectionHealthStore;
use BitApps\SMTP\Mail\Health\HealthStatus;
use BitApps\SMTP\Mail\Health\ProbeResult;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
final class ConnectionHealthServiceTest extends BaseUnitTestCase
{
    public function testThreeConsecutiveTransientFailuresTripToUnhealthy(): void
    {
        $store   = new InMemoryHealthStore();
        $service = $this->service($store, ['conn_1']);
        $conn    = $this->connection('conn_1');

        $first = $service->recordOutcome($conn, FailureCategory::TRANSIENT);
        $this->assertNotNull($first);
        $this->assertSame(HealthStatus::DEGRADED, $first->after());
        $this->assertTrue($store->records['conn_1']->isDegraded());

        // Second failure stays degraded, so no status transition is emitted.
        $this->assertNull($service->recordOutcome($conn, FailureCategory::TRANSIENT));
        $this->assertSame(2, $store->records['conn_1']->getConsecutiveFailures());

        $third = $service->recordOutcome($conn, FailureCategory::TRANSIENT);
        $this->assertNotNull($third);
        $this->assertSame(HealthStatus::DEGRADED, $third->before());
        $this->assertSame(HealthStatus::UNHEALTHY, $third->after());

        $record = $store->records['conn_1'];
        $this->assertTrue($record->isUnhealthy());
        $this->assertSame(HealthStatus::CIRCUIT_OPEN, $record->getCircuit());
        $this->assertSame(3, $record->getConsecutiveFailures());
    }

    public function testAuthFailureTripsImmediately(): void
    {
        $store   = new InMemoryHealthStore();
        $service = $this->service($store, ['conn_1']);

        $transition = $service->recordOutcome($this->connection('conn_1'), FailureCategory::AUTH);

        $this->assertNotNull($transition);
        $this->assertSame(HealthStatus::UNKNOWN, $transition->before());
        $this->assertSame(HealthStatus::UNHEALTHY, $transition->after());

        $record = $store->records['conn_1'];
        $this->assertTrue($record->isUnhealthy());
        $this->assertSame(HealthStatus::CIRCUIT_OPEN, $record->getCircuit());
        $this->assertSame(1, $record->getConsecutiveFailures());
        $this->assertSame(FailureCategory::AUTH, $record->getLastErrorClass());
    }

    public function testMessageScopedFailuresNeverTouchHealth(): void
    {
        $store   = new InMemoryHealthStore();
        $service = $this->service($store, ['conn_1']);
        $conn    = $this->connection('conn_1');

        $this->assertNull($service->recordOutcome($conn, FailureCategory::INVALID_RECIPIENT));
        $this->assertNull($service->recordOutcome($conn, FailureCategory::PERMANENT));
        $this->assertSame([], $store->records);
        $this->assertSame(0, $store->writes);
    }

    public function testSuccessResetsThenThrottlesTheSteadyStateWrite(): void
    {
        $store   = new InMemoryHealthStore();
        $service = $this->service($store, ['conn_1']);
        $conn    = $this->connection('conn_1');

        $service->recordOutcome($conn, FailureCategory::TRANSIENT);
        $this->assertSame(1, $store->writes);

        $recovery = $service->recordOutcome($conn, FailureCategory::OK);
        $this->assertNotNull($recovery);
        $this->assertSame(HealthStatus::HEALTHY, $recovery->after());
        $this->assertSame(0, $store->records['conn_1']->getConsecutiveFailures());
        $this->assertNotNull($store->records['conn_1']->getLastOkAt());
        $this->assertSame(2, $store->writes);

        // A second, steady-state success within the throttle window is neither a transition nor a write.
        $this->assertNull($service->recordOutcome($conn, FailureCategory::OK));
        $this->assertSame(2, $store->writes);
    }

    public function testEveryWritePrunesStaleConnectionRecords(): void
    {
        $store           = new InMemoryHealthStore();
        $store->records  = ['conn_dead' => ConnectionHealth::unknown()];
        $service         = $this->service($store, ['conn_1']);

        $service->recordOutcome($this->connection('conn_1'), FailureCategory::TRANSIENT);

        $this->assertArrayHasKey('conn_1', $store->records);
        $this->assertArrayNotHasKey('conn_dead', $store->records);
    }

    public function testOauthExpiryIsMirroredFromConnectionSettings(): void
    {
        $store   = new InMemoryHealthStore();
        $service = $this->service($store, ['conn_oauth', 'conn_smtp']);

        $service->recordOutcome($this->connection('conn_oauth', ['token_expires_at' => 1893456000]), FailureCategory::AUTH);
        $service->recordOutcome($this->connection('conn_smtp'), FailureCategory::TRANSIENT);

        $this->assertSame(1893456000, $store->records['conn_oauth']->getOauthExpiresAt());
        $this->assertNull($store->records['conn_smtp']->getOauthExpiresAt());
    }

    public function testRecordProbeSuccessStampsLastProbeAtAndBypassesTheOkWriteThrottle(): void
    {
        $store   = new InMemoryHealthStore();
        $service = $this->service($store, ['conn_1']);
        $conn    = $this->connection('conn_1');

        $first = $service->recordProbe($conn, ProbeResult::ok());
        $this->assertNotNull($first);
        $this->assertSame(HealthStatus::HEALTHY, $first->after());
        $this->assertNotNull($store->records['conn_1']->getLastProbeAt());
        $this->assertSame(1, $store->writes);

        // A send success here would be throttled, but a probe always persists to keep last_probe_at
        // fresh; the status does not change, so no transition is emitted.
        $this->assertNull($service->recordProbe($conn, ProbeResult::ok()));
        $this->assertSame(2, $store->writes);
    }

    public function testRecordProbeFailureTripsUsingTheProbeFailureClass(): void
    {
        $store   = new InMemoryHealthStore();
        $service = $this->service($store, ['conn_1']);

        $transition = $service->recordProbe(
            $this->connection('conn_1'),
            ProbeResult::failure('Could not authenticate', FailureCategory::AUTH)
        );

        $this->assertNotNull($transition);
        $this->assertSame(HealthStatus::UNHEALTHY, $transition->after());

        $record = $store->records['conn_1'];
        $this->assertTrue($record->isUnhealthy());
        $this->assertSame(FailureCategory::AUTH, $record->getLastErrorClass());
        $this->assertSame('Could not authenticate', $record->getLastError(), 'the probe error is surfaced as last_error');
        $this->assertNotNull($record->getLastProbeAt());
    }

    public function testListFillsUnknownForUnobservedLiveConnections(): void
    {
        $store          = new InMemoryHealthStore();
        $store->records = ['conn_1' => ConnectionHealth::fromArray(['status' => HealthStatus::UNHEALTHY])];
        $service        = $this->serviceWithConnections($store, [
            $this->connection('conn_1'),
            $this->connection('conn_2', ['token_expires_at' => 1893456000]),
        ]);

        $list = $service->list();

        $this->assertSame(['conn_1', 'conn_2'], array_keys($list));
        $this->assertTrue($list['conn_1']->isUnhealthy());
        $this->assertSame(HealthStatus::UNKNOWN, $list['conn_2']->getStatus());
        $this->assertSame(1893456000, $list['conn_2']->getOauthExpiresAt());
    }

    public function testRecoveryClearsTheAlertMarkerSoALaterOutageCanAlertAgain(): void
    {
        $store          = new InMemoryHealthStore();
        $store->records = ['conn_1' => ConnectionHealth::fromArray([
            'status'             => HealthStatus::UNHEALTHY,
            'last_alerted_state' => 'connection_unhealthy',
            'last_alerted_at'    => '2026-08-24 10:00:00',
        ])];
        $service = $this->service($store, ['conn_1']);

        $transition = $service->recordOutcome($this->connection('conn_1'), FailureCategory::OK);

        $this->assertNotNull($transition);
        $this->assertSame(HealthStatus::HEALTHY, $transition->after());
        // Marker cleared so a later unhealthy crossing is not deduped away as a repeat...
        $this->assertNull($store->records['conn_1']->getLastAlertedState());
        // ...but the cooldown timestamp survives so rapid flapping is still throttled.
        $this->assertSame('2026-08-24 10:00:00', $store->records['conn_1']->getLastAlertedAt());
    }

    public function testRecordProbeRecordsAConnectionScopedFailureEvenForAMessageScopedClass(): void
    {
        $store   = new InMemoryHealthStore();
        $service = $this->service($store, ['conn_1']);

        // A blocklisted host (554) classifies PERMANENT — message-scoped for a SEND, but for a PROBE it
        // is a real connection problem that must be recorded, not silently skipped.
        $transition = $service->recordProbe(
            $this->connection('conn_1'),
            ProbeResult::failure('554 5.7.1 blocked', FailureCategory::PERMANENT)
        );

        $this->assertNotNull($transition);
        $this->assertSame(HealthStatus::DEGRADED, $transition->after());

        $record = $store->records['conn_1'];
        $this->assertNotNull($record->getLastProbeAt());
        $this->assertSame('554 5.7.1 blocked', $record->getLastError());
        $this->assertSame(FailureCategory::PERMANENT, $record->getLastErrorClass());
    }

    /**
     * @param string[] $liveConnectionIds
     */
    private function service(ConnectionHealthStore $store, array $liveConnectionIds): ConnectionHealthService
    {
        return $this->serviceWithConnections(
            $store,
            array_map(fn (string $id): Connection => $this->connection($id), $liveConnectionIds)
        );
    }

    /**
     * @param Connection[] $connections
     */
    private function serviceWithConnections(ConnectionHealthStore $store, array $connections): ConnectionHealthService
    {
        $settings = MailSettings::fromArray([
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => '',
            'fallback_connection_ids' => [],
            'connections'             => array_map(static fn (Connection $c): array => $c->toArray(), $connections),
            'features'                => [],
        ]);

        $config = Mockery::mock(MailConfigService::class);
        $config->shouldReceive('load')->andReturn($settings);

        return new ConnectionHealthService($store, $config);
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function connection(string $id, array $settings = []): Connection
    {
        return Connection::fromArray([
            'id'       => $id,
            'provider' => 'smtp',
            'kind'     => 'smtp',
            'settings' => $settings,
        ]);
    }
}

/**
 * In-memory ConnectionHealthStore double: keeps records in a public array and counts put() writes so
 * a test can assert the write-throttle skipped a persist without stubbing WordPress option functions.
 */
final class InMemoryHealthStore extends ConnectionHealthStore
{
    /**
     * @var array<string,ConnectionHealth>
     */
    public array $records = [];

    public int $writes = 0;

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
        ++$this->writes;
    }

    public function putMany(array $records): void
    {
        $this->records = $records;
        ++$this->writes;
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
