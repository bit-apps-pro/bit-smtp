<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Health\ConnectionHealth;
use BitApps\SMTP\Mail\Health\HealthStatus;
use BitApps\SMTP\Mail\Notifications\HealthNotification;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;

/**
 * @internal
 *
 * @coversNothing
 */
final class HealthNotificationTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_bloginfo')->justReturn('Example Site');
        Functions\when('home_url')->justReturn('https://example.test/');
    }

    public function testUnhealthyPayloadCarriesOnlyNonSensitiveFields(): void
    {
        $notification = HealthNotification::unhealthy($this->connection(), $this->health([
            'status'           => HealthStatus::UNHEALTHY,
            'last_error'       => 'SMTP connect failed',
            'oauth_expires_at' => 1893456000,
        ]));

        $payload = $notification->toArray();

        $this->assertSame(HealthNotification::EVENT_UNHEALTHY, $payload['event']);
        $this->assertSame(['id' => 'conn_1', 'name' => 'My Gmail', 'provider' => 'gmail'], $payload['connection']);
        $this->assertSame(HealthStatus::UNHEALTHY, $payload['status']);
        $this->assertSame('SMTP connect failed', $payload['last_error']);
        $this->assertSame(1893456000, $payload['oauth_expires_at']);
    }

    public function testNoCredentialEverReachesTheSerializedAlert(): void
    {
        $notification = HealthNotification::oauthExpiring($this->connection(), $this->health([
            'oauth_expires_at' => 1893456000,
        ]));

        $serialized = json_encode($notification->toArray()) . $notification->emailBody() . $notification->emailSubject();

        $this->assertStringNotContainsString('super-secret-refresh', $serialized);
        $this->assertStringNotContainsString('super-secret-access', $serialized);
        $this->assertStringNotContainsString('smtp-password', $serialized);
    }

    public function testEmailSubjectAndBodyReadForTheEvent(): void
    {
        $notification = HealthNotification::recovered($this->connection(), $this->health(['status' => HealthStatus::HEALTHY]));

        $this->assertSame('[Bit SMTP] Connection recovered on Example Site', $notification->emailSubject());
        $this->assertStringContainsString('Connection: My Gmail (gmail)', $notification->emailBody());
        $this->assertFalse($notification->isTest());
    }

    private function connection(): Connection
    {
        return Connection::fromArray([
            'id'          => 'conn_1',
            'provider'    => 'gmail',
            'kind'        => 'api',
            'name'        => 'My Gmail',
            'settings'    => ['token_expires_at' => 1893456000, 'password' => 'smtp-password'],
            'credentials' => [
                'refresh_token' => ['source' => 'database', 'value' => 'super-secret-refresh'],
                'access_token'  => ['source' => 'database', 'value' => 'super-secret-access'],
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function health(array $overrides): ConnectionHealth
    {
        return ConnectionHealth::fromArray($overrides);
    }
}
