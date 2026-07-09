<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Config;

use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Tests\BaseUnitTestCase;

class MailSettingsTest extends BaseUnitTestCase
{
    private function v2Array(): array
    {
        return [
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => 'conn_abc',
            'fallback_connection_ids' => ['conn_xyz'],
            'connections'             => [
                [
                    'id'           => 'conn_abc',
                    'provider'     => 'other_smtp',
                    'kind'         => 'smtp',
                    'name'         => 'Primary',
                    'enabled'      => true,
                    'fromEmail'    => 'from@example.com',
                    'fromName'     => 'Sender',
                    'replyToEmail' => 'reply@example.com',
                    'settings'     => ['host' => 'smtp.example.com', 'port' => 587],
                    'credentials'  => [],
                ],
            ],
            'features' => [
                'logging' => ['enabled' => true],
            ],
        ];
    }

    public function testFromArrayAndGetters(): void
    {
        $settings = MailSettings::fromArray($this->v2Array());

        $this->assertSame(2, $settings->getSchemaVersion());
        $this->assertTrue($settings->isEnabled());
        $this->assertSame('conn_abc', $settings->getDefaultConnectionId());
        $this->assertSame(['conn_xyz'], $settings->getFallbackConnectionIds());
        $this->assertSame(1, $settings->getConnections()->count());
        $this->assertSame(['logging' => ['enabled' => true]], $settings->getFeatures());
    }

    public function testToArrayRoundTrip(): void
    {
        $data     = $this->v2Array();
        $settings = MailSettings::fromArray($data);
        $result   = $settings->toArray();

        $this->assertSame($data['schema_version'], $result['schema_version']);
        $this->assertSame($data['enabled'], $result['enabled']);
        $this->assertSame($data['default_connection_id'], $result['default_connection_id']);
        $this->assertSame($data['fallback_connection_ids'], $result['fallback_connection_ids']);
        $this->assertCount(count($data['connections']), $result['connections']);
        $this->assertSame($data['features'], $result['features']);
    }

    public function testDefaultConnectionReturnsCorrectConnection(): void
    {
        $settings    = MailSettings::fromArray($this->v2Array());
        $defaultConn = $settings->defaultConnection();

        $this->assertNotNull($defaultConn);
        $this->assertSame('conn_abc', $defaultConn->getId());
    }

    public function testDefaultConnectionReturnsNullWhenNotFound(): void
    {
        $data                          = $this->v2Array();
        $data['default_connection_id'] = 'nonexistent';
        $settings = MailSettings::fromArray($data);

        $this->assertNull($settings->defaultConnection());
    }

    public function testEmptyConnectionsCollection(): void
    {
        $data                = $this->v2Array();
        $data['connections'] = [];
        $settings = MailSettings::fromArray($data);

        $this->assertSame(0, $settings->getConnections()->count());
    }
}
