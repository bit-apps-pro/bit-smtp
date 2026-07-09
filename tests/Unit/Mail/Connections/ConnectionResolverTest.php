<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Connections;

use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Connections\ConnectionResolver;
use BitApps\SMTP\Tests\BaseUnitTestCase;

class ConnectionResolverTest extends BaseUnitTestCase
{
    private ConnectionResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ConnectionResolver();
    }

    public function testResolveReturnsDefaultConnectionWhenItExistsAndIsEnabled(): void
    {
        $settings = MailSettings::fromArray([
            'default_connection_id' => 'conn-1',
            'connections'           => [
                [
                    'id'       => 'conn-1',
                    'provider' => 'smtp',
                    'kind'     => 'custom',
                    'enabled'  => true,
                ],
                [
                    'id'       => 'conn-2',
                    'provider' => 'smtp',
                    'kind'     => 'custom',
                    'enabled'  => true,
                ],
            ],
        ]);

        $result = $this->resolver->resolve($settings);

        $this->assertNotNull($result);
        $this->assertSame('conn-1', $result->getId());
    }

    public function testResolveIgnoresDisabledDefaultConnection(): void
    {
        $settings = MailSettings::fromArray([
            'default_connection_id' => 'conn-1',
            'connections'           => [
                [
                    'id'       => 'conn-1',
                    'provider' => 'smtp',
                    'kind'     => 'custom',
                    'enabled'  => false,
                ],
                [
                    'id'       => 'conn-2',
                    'provider' => 'smtp',
                    'kind'     => 'custom',
                    'enabled'  => true,
                ],
            ],
        ]);

        $result = $this->resolver->resolve($settings);

        $this->assertNotNull($result);
        $this->assertSame('conn-2', $result->getId());
    }

    public function testResolveReturnsFirstEnabledWhenDefaultIdIsMissing(): void
    {
        $settings = MailSettings::fromArray([
            'default_connection_id' => 'unknown',
            'connections'           => [
                [
                    'id'       => 'conn-1',
                    'provider' => 'smtp',
                    'kind'     => 'custom',
                    'enabled'  => true,
                ],
                [
                    'id'       => 'conn-2',
                    'provider' => 'smtp',
                    'kind'     => 'custom',
                    'enabled'  => true,
                ],
            ],
        ]);

        $result = $this->resolver->resolve($settings);

        $this->assertNotNull($result);
        $this->assertSame('conn-1', $result->getId());
    }

    public function testResolveReturnsFirstEnabledWhenFirstConnectionIsDisabled(): void
    {
        $settings = MailSettings::fromArray([
            'default_connection_id' => '',
            'connections'           => [
                [
                    'id'       => 'conn-1',
                    'provider' => 'smtp',
                    'kind'     => 'custom',
                    'enabled'  => false,
                ],
                [
                    'id'       => 'conn-2',
                    'provider' => 'smtp',
                    'kind'     => 'custom',
                    'enabled'  => true,
                ],
                [
                    'id'       => 'conn-3',
                    'provider' => 'smtp',
                    'kind'     => 'custom',
                    'enabled'  => true,
                ],
            ],
        ]);

        $result = $this->resolver->resolve($settings);

        $this->assertNotNull($result);
        $this->assertSame('conn-2', $result->getId());
    }

    public function testResolveReturnsNullWhenNoEnabledConnections(): void
    {
        $settings = MailSettings::fromArray([
            'default_connection_id' => 'conn-1',
            'connections'           => [
                [
                    'id'       => 'conn-1',
                    'provider' => 'smtp',
                    'kind'     => 'custom',
                    'enabled'  => false,
                ],
                [
                    'id'       => 'conn-2',
                    'provider' => 'smtp',
                    'kind'     => 'custom',
                    'enabled'  => false,
                ],
            ],
        ]);

        $result = $this->resolver->resolve($settings);

        $this->assertNull($result);
    }

    public function testResolveReturnsNullWhenCollectionIsEmpty(): void
    {
        $settings = MailSettings::fromArray([
            'default_connection_id' => 'conn-1',
            'connections'           => [],
        ]);

        $result = $this->resolver->resolve($settings);

        $this->assertNull($result);
    }
}
