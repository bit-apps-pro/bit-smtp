<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Connections;

use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Connections\ConnectionResolver;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
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

    public function testResolveOrderedPutsEnabledDefaultConnectionFirst(): void
    {
        $settings = MailSettings::fromArray([
            'default_connection_id' => 'conn-2',
            'connections'           => [
                ['id' => 'conn-1', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => true],
                ['id' => 'conn-2', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => true],
                ['id' => 'conn-3', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => true],
            ],
        ]);

        $result = $this->resolver->resolveOrdered($settings);

        $this->assertSame(['conn-2', 'conn-1', 'conn-3'], $this->ids($result));
    }

    public function testResolveOrderedThenIncludesFallbackConnectionsInListedOrder(): void
    {
        $settings = MailSettings::fromArray([
            'default_connection_id'   => 'conn-1',
            'fallback_connection_ids' => ['conn-3', 'conn-2'],
            'connections'             => [
                ['id' => 'conn-1', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => true],
                ['id' => 'conn-2', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => true],
                ['id' => 'conn-3', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => true],
                ['id' => 'conn-4', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => true],
            ],
        ]);

        $result = $this->resolver->resolveOrdered($settings);

        $this->assertSame(['conn-1', 'conn-3', 'conn-2', 'conn-4'], $this->ids($result));
    }

    public function testResolveOrderedAppendsRemainingEnabledConnectionsAfterFallback(): void
    {
        $settings = MailSettings::fromArray([
            'default_connection_id'   => 'conn-2',
            'fallback_connection_ids' => ['conn-4'],
            'connections'             => [
                ['id' => 'conn-1', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => true],
                ['id' => 'conn-2', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => true],
                ['id' => 'conn-3', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => true],
                ['id' => 'conn-4', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => true],
            ],
        ]);

        $result = $this->resolver->resolveOrdered($settings);

        $this->assertSame(['conn-2', 'conn-4', 'conn-1', 'conn-3'], $this->ids($result));
    }

    public function testResolveOrderedExcludesDisabledDefaultConnection(): void
    {
        $settings = MailSettings::fromArray([
            'default_connection_id' => 'conn-1',
            'connections'           => [
                ['id' => 'conn-1', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => false],
                ['id' => 'conn-2', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => true],
            ],
        ]);

        $result = $this->resolver->resolveOrdered($settings);

        $this->assertSame(['conn-2'], $this->ids($result));
    }

    public function testResolveOrderedSkipsDisabledFallbackConnections(): void
    {
        $settings = MailSettings::fromArray([
            'default_connection_id'   => 'conn-1',
            'fallback_connection_ids' => ['conn-2', 'conn-3'],
            'connections'             => [
                ['id' => 'conn-1', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => true],
                ['id' => 'conn-2', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => false],
                ['id' => 'conn-3', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => true],
            ],
        ]);

        $result = $this->resolver->resolveOrdered($settings);

        $this->assertSame(['conn-1', 'conn-3'], $this->ids($result));
    }

    public function testResolveOrderedExcludesDisabledConnectionsFromRemaining(): void
    {
        $settings = MailSettings::fromArray([
            'default_connection_id' => 'conn-1',
            'connections'           => [
                ['id' => 'conn-1', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => true],
                ['id' => 'conn-2', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => false],
                ['id' => 'conn-3', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => true],
            ],
        ]);

        $result = $this->resolver->resolveOrdered($settings);

        $this->assertSame(['conn-1', 'conn-3'], $this->ids($result));
    }

    public function testResolveOrderedDoesNotDuplicateConnectionListedAsBothDefaultAndFallback(): void
    {
        $settings = MailSettings::fromArray([
            'default_connection_id'   => 'conn-1',
            'fallback_connection_ids' => ['conn-1', 'conn-2'],
            'connections'             => [
                ['id' => 'conn-1', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => true],
                ['id' => 'conn-2', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => true],
            ],
        ]);

        $result = $this->resolver->resolveOrdered($settings);

        $this->assertSame(['conn-1', 'conn-2'], $this->ids($result));
    }

    public function testResolveOrderedReturnsEmptyArrayWhenNoConnectionsEnabled(): void
    {
        $settings = MailSettings::fromArray([
            'default_connection_id' => 'conn-1',
            'connections'           => [
                ['id' => 'conn-1', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => false],
                ['id' => 'conn-2', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => false],
            ],
        ]);

        $result = $this->resolver->resolveOrdered($settings);

        $this->assertSame([], $result);
    }

    public function testResolveOrderedSkipsUnknownFallbackConnectionIds(): void
    {
        $settings = MailSettings::fromArray([
            'default_connection_id'   => 'conn-1',
            'fallback_connection_ids' => ['unknown', 'conn-2'],
            'connections'             => [
                ['id' => 'conn-1', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => true],
                ['id' => 'conn-2', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => true],
            ],
        ]);

        $result = $this->resolver->resolveOrdered($settings);

        $this->assertSame(['conn-1', 'conn-2'], $this->ids($result));
    }

    public function testResolveOrderedStartsWithFallbackOrderWhenDefaultConnectionIdIsMissing(): void
    {
        $settings = MailSettings::fromArray([
            'default_connection_id'   => '',
            'fallback_connection_ids' => ['conn-2', 'conn-1'],
            'connections'             => [
                ['id' => 'conn-1', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => true],
                ['id' => 'conn-2', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => true],
                ['id' => 'conn-3', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => true],
            ],
        ]);

        $result = $this->resolver->resolveOrdered($settings);

        $this->assertSame(['conn-2', 'conn-1', 'conn-3'], $this->ids($result));
    }

    public function testResolveOrderedStartsWithFallbackOrderWhenDefaultConnectionIsDisabled(): void
    {
        $settings = MailSettings::fromArray([
            'default_connection_id'   => 'conn-1',
            'fallback_connection_ids' => ['conn-2', 'conn-3'],
            'connections'             => [
                ['id' => 'conn-1', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => false],
                ['id' => 'conn-2', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => true],
                ['id' => 'conn-3', 'provider' => 'smtp', 'kind' => 'custom', 'enabled' => true],
            ],
        ]);

        $result = $this->resolver->resolveOrdered($settings);

        $this->assertSame(['conn-2', 'conn-3'], $this->ids($result));
    }

    /**
     * @param Connection[] $connections
     *
     * @return string[]
     */
    private function ids(array $connections): array
    {
        return array_map(static function (Connection $c): string {
            return $c->getId();
        }, $connections);
    }
}
