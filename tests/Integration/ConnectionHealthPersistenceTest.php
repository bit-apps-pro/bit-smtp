<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Dispatch\FailureCategory;
use BitApps\SMTP\Mail\Health\ConnectionHealth;
use BitApps\SMTP\Mail\Health\ConnectionHealthService;
use BitApps\SMTP\Mail\Health\ConnectionHealthStore;
use BitApps\SMTP\Mail\Health\HealthStatus;

/**
 * @internal
 *
 * @coversNothing
 */
final class ConnectionHealthPersistenceTest extends IntegrationTestCase
{
    private const OPTION = 'bit_smtp_connection_health';

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(self::OPTION);
    }

    protected function tearDown(): void
    {
        delete_option(self::OPTION);
        parent::tearDown();
    }

    public function testARecordRoundTripsThroughTheOption(): void
    {
        $record = ConnectionHealth::fromArray([
            'status'               => HealthStatus::UNHEALTHY,
            'circuit'              => HealthStatus::CIRCUIT_OPEN,
            'consecutive_failures' => 3,
            'last_ok_at'           => '2026-08-24 09:00:00',
            'last_error_class'     => FailureCategory::AUTH,
            'opened_at'            => '2026-08-24 10:00:00',
            'oauth_expires_at'     => 1893456000,
            'updated_at'           => '2026-08-24 10:00:00',
        ]);

        (new ConnectionHealthStore())->put('conn_1', $record);

        $reloaded = (new ConnectionHealthStore())->get('conn_1');

        $this->assertNotNull($reloaded);
        $this->assertSame($record->toArray(), $reloaded->toArray());
    }

    public function testTheOptionIsNotAutoloaded(): void
    {
        (new ConnectionHealthStore())->put('conn_1', ConnectionHealth::unknown());

        wp_cache_delete('alloptions', 'options');

        // Health is read on demand (admin/cron), so it must never join the autoloaded blob loaded on
        // every page request.
        $this->assertArrayNotHasKey(self::OPTION, wp_load_alloptions());
    }

    public function testRecordingPrunesRecordsForConnectionsThatNoLongerExist(): void
    {
        $this->storeOptions([
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => 'conn_live',
            'fallback_connection_ids' => [],
            'connections'             => [
                [
                    'id'       => 'conn_live',
                    'provider' => 'smtp',
                    'kind'     => 'smtp',
                    'name'     => 'Live SMTP',
                    'enabled'  => true,
                    'settings' => ['host' => 'smtp.example.test'],
                ],
            ],
            'features' => [],
        ]);

        $config = new MailConfigService();
        $store  = new ConnectionHealthStore();
        $store->put('conn_dead', ConnectionHealth::unknown());

        $connection = $config->load()->getConnections()->byId('conn_live');
        $this->assertNotNull($connection);

        (new ConnectionHealthService($store, $config))->recordOutcome($connection, FailureCategory::TRANSIENT);

        $persisted = (new ConnectionHealthStore())->all();
        $this->assertArrayHasKey('conn_live', $persisted);
        $this->assertArrayNotHasKey('conn_dead', $persisted);
        $this->assertTrue($persisted['conn_live']->isDegraded());
    }
}
