<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Controllers\ConnectionHealthController;
use BitApps\SMTP\Mail\Dispatch\FailureCategory;
use BitApps\SMTP\Mail\Health\ConnectionHealth;
use BitApps\SMTP\Mail\Health\ConnectionHealthStore;
use BitApps\SMTP\Mail\Health\HealthProbeRunner;
use BitApps\SMTP\Mail\Health\HealthStatus;
use BitApps\SMTP\Plugin;
use Mockery;

/**
 * Drives ConnectionHealthController against the real health engine: index exposes the live map keyed
 * by connection id in its public shape, check runs a probe and returns refreshed state, and neither
 * ever leaks the internal alert bookkeeping (last_alerted_state/at).
 *
 * @internal
 *
 * @coversNothing
 */
final class ConnectionHealthEndpointTest extends IntegrationTestCase
{
    /**
     * The exact, whitelisted keys the endpoint may surface — nothing else.
     */
    private const PUBLIC_KEYS = [
        'status',
        'circuit',
        'consecutive_failures',
        'last_ok_at',
        'last_error',
        'last_probe_at',
        'oauth_expires_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(ConnectionHealthStore::OPTION_NAME);
        delete_option(HealthProbeRunner::LAST_RUN_OPTION);
    }

    protected function tearDown(): void
    {
        delete_option(ConnectionHealthStore::OPTION_NAME);
        delete_option(HealthProbeRunner::LAST_RUN_OPTION);
        Mockery::close();
        parent::tearDown();
    }

    public function testIndexReturnsThePublicHealthMapAndHidesAlertBookkeeping(): void
    {
        $this->storeSmtpConnection('conn_smtp');
        (new ConnectionHealthStore())->put('conn_smtp', ConnectionHealth::fromArray([
            'status'               => HealthStatus::UNHEALTHY,
            'circuit'              => HealthStatus::CIRCUIT_OPEN,
            'consecutive_failures' => 3,
            'last_error'           => 'SMTP connect() failed',
            'last_error_class'     => FailureCategory::AUTH,
            'last_alerted_state'   => HealthStatus::UNHEALTHY,
            'last_alerted_at'      => '2026-08-24 10:00:00',
        ]));

        (new ConnectionHealthController())->index($this->request());
        $data = (array) Response::getData();

        $this->assertSame(Response::SUCCESS, Response::getStatus());
        $this->assertArrayHasKey('conn_smtp', $data['health']);

        $item = $data['health']['conn_smtp'];
        $this->assertSame(self::PUBLIC_KEYS, array_keys($item), 'only the whitelisted public keys may be returned');
        $this->assertSame(HealthStatus::UNHEALTHY, $item['status']);
        $this->assertSame('SMTP connect() failed', $item['last_error']);
        $this->assertArrayNotHasKey('last_alerted_state', $item, 'internal alert bookkeeping must never leak');
        $this->assertArrayNotHasKey('last_alerted_at', $item, 'internal alert bookkeeping must never leak');
    }

    public function testIndexFillsUnknownForAConnectionNeverObserved(): void
    {
        $this->storeSmtpConnection('conn_smtp');

        (new ConnectionHealthController())->index($this->request());
        $data = (array) Response::getData();

        $this->assertSame(Response::SUCCESS, Response::getStatus());
        $this->assertSame(HealthStatus::UNKNOWN, $data['health']['conn_smtp']['status']);
    }

    public function testCheckProbesAndReturnsRefreshedHealthWithoutAlertBookkeeping(): void
    {
        // A reachable mailpit SMTP endpoint probes healthy on the on-demand "Check now".
        $this->storeSmtpConnection('conn_smtp');

        (new ConnectionHealthController())->check($this->request());
        $data = (array) Response::getData();

        $this->assertSame(Response::SUCCESS, Response::getStatus());

        $item = $data['health']['conn_smtp'];
        $this->assertSame(self::PUBLIC_KEYS, array_keys($item), 'only the whitelisted public keys may be returned');
        $this->assertSame(HealthStatus::HEALTHY, $item['status']);
        $this->assertNotNull($item['last_probe_at'], 'the probe must stamp a fresh last_probe_at');
        $this->assertArrayNotHasKey('last_alerted_state', $item);
        $this->assertArrayNotHasKey('last_alerted_at', $item);
    }

    private function storeSmtpConnection(string $id): void
    {
        $this->storeOptions([
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => $id,
            'fallback_connection_ids' => [],
            'connections'             => [[
                'id'       => $id,
                'provider' => 'other_smtp',
                'kind'     => 'smtp',
                'name'     => $id,
                'enabled'  => true,
                'settings' => ['host' => self::SMTP_HOST, 'port' => self::SMTP_PORT, 'encryption' => 'none', 'auth' => false],
            ]],
            'features' => [],
        ]);
        Plugin::instance()->mailConfigService()->reload();
    }

    private function request(): Request
    {
        return Mockery::mock(Request::class);
    }
}
