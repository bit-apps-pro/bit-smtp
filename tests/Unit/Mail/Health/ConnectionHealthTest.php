<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Health;

use BitApps\SMTP\Mail\Dispatch\FailureCategory;
use BitApps\SMTP\Mail\Health\ConnectionHealth;
use BitApps\SMTP\Mail\Health\HealthStatus;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class ConnectionHealthTest extends BaseUnitTestCase
{
    public function testUnknownRecordCarriesTheZeroDataDefaults(): void
    {
        $record = ConnectionHealth::unknown();

        $this->assertSame(HealthStatus::UNKNOWN, $record->getStatus());
        $this->assertSame(HealthStatus::CIRCUIT_CLOSED, $record->getCircuit());
        $this->assertSame(0, $record->getConsecutiveFailures());
        $this->assertNull($record->getLastOkAt());
        $this->assertNull($record->getOauthExpiresAt());
        $this->assertFalse($record->isUnhealthy());
        $this->assertFalse($record->isDegraded());
    }

    public function testFromArrayToArrayRoundTripsEveryField(): void
    {
        $data = [
            'status'               => HealthStatus::UNHEALTHY,
            'circuit'              => HealthStatus::CIRCUIT_OPEN,
            'consecutive_failures' => 4,
            'last_ok_at'           => '2026-08-24 10:00:00',
            'last_error'           => 'connect failed',
            'last_error_class'     => FailureCategory::AUTH,
            'last_probe_at'        => '2026-08-24 12:00:00',
            'oauth_expires_at'     => 1893456000,
            'last_alerted_state'   => HealthStatus::UNHEALTHY,
            'last_alerted_at'      => '2026-08-24 12:05:00',
            'updated_at'           => '2026-08-24 12:10:00',
        ];

        $this->assertSame($data, ConnectionHealth::fromArray($data)->toArray());
    }

    public function testFromArrayCoercesTypesAndDefaultsAbsentFields(): void
    {
        $record = ConnectionHealth::fromArray([
            'consecutive_failures' => '2',
            'oauth_expires_at'     => '1893456000',
            'last_ok_at'           => '',
        ]);

        $this->assertSame(HealthStatus::UNKNOWN, $record->getStatus());
        $this->assertSame(HealthStatus::CIRCUIT_CLOSED, $record->getCircuit());
        $this->assertSame(2, $record->getConsecutiveFailures());
        $this->assertSame(1893456000, $record->getOauthExpiresAt());
        // An empty-string timestamp normalizes to null, never a stray ''.
        $this->assertNull($record->getLastOkAt());
        $this->assertNotSame('', $record->getUpdatedAt());
    }

    public function testWithOverridesOnlyTheGivenFields(): void
    {
        $record = ConnectionHealth::unknown()->with([
            'status'               => HealthStatus::DEGRADED,
            'consecutive_failures' => 1,
        ]);

        $this->assertSame(HealthStatus::DEGRADED, $record->getStatus());
        $this->assertSame(1, $record->getConsecutiveFailures());
        $this->assertTrue($record->isDegraded());
        // Untouched fields keep their prior values.
        $this->assertSame(HealthStatus::CIRCUIT_CLOSED, $record->getCircuit());
        $this->assertNull($record->getLastOkAt());
    }

    public function testDerivationHelpersReflectStatus(): void
    {
        $unhealthy = ConnectionHealth::fromArray(['status' => HealthStatus::UNHEALTHY]);
        $this->assertTrue($unhealthy->isUnhealthy());
        $this->assertFalse($unhealthy->isDegraded());

        $degraded = ConnectionHealth::fromArray(['status' => HealthStatus::DEGRADED]);
        $this->assertTrue($degraded->isDegraded());
        $this->assertFalse($degraded->isUnhealthy());
    }
}
