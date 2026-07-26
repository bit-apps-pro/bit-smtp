<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers;

use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Exceptions\DuplicateProviderException;
use BitApps\SMTP\Mail\Exceptions\ProviderNotFoundException;
use BitApps\SMTP\Mail\Providers\ProviderRegistry;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
class ProviderRegistryTest extends BaseUnitTestCase
{
    public function testRegisterThenHasReturnsTrue(): void
    {
        $registry = new ProviderRegistry();
        $provider = $this->makeProvider('test_key');

        $registry->register($provider);

        $this->assertTrue($registry->has('test_key'));
    }

    public function testRegisterThenGetReturnsSameInstance(): void
    {
        $registry = new ProviderRegistry();
        $provider = $this->makeProvider('test_key');

        $registry->register($provider);
        $result = $registry->get('test_key');

        $this->assertSame($provider, $result);
    }

    public function testAllReturnsAllRegisteredProviders(): void
    {
        $registry  = new ProviderRegistry();
        $provider1 = $this->makeProvider('key1');
        $provider2 = $this->makeProvider('key2');

        $registry->register($provider1);
        $registry->register($provider2);

        $all = $registry->all();

        $this->assertCount(2, $all);
        $this->assertContains($provider1, $all);
        $this->assertContains($provider2, $all);
    }

    public function testDuplicateKeyThrowsDuplicateProviderException(): void
    {
        $registry  = new ProviderRegistry();
        $provider1 = $this->makeProvider('duplicate_key');
        $provider2 = $this->makeProvider('duplicate_key');

        $registry->register($provider1);

        $this->expectException(DuplicateProviderException::class);
        $registry->register($provider2);
    }

    public function testGetUnknownKeyThrowsProviderNotFoundException(): void
    {
        $registry = new ProviderRegistry();

        $this->expectException(ProviderNotFoundException::class);
        $registry->get('nonexistent');
    }

    public function testHasReturnsFalseForUnknownKey(): void
    {
        $registry = new ProviderRegistry();

        $this->assertFalse($registry->has('nonexistent'));
    }

    public function testMetadataReturnsCorrectShape(): void
    {
        $registry = new ProviderRegistry();
        $provider = $this->makeProvider('test_provider', 'Test Provider', 'smtp');

        $registry->register($provider);
        $metadata = $registry->metadata();

        $this->assertIsArray($metadata);
        $this->assertCount(1, $metadata);
        $this->assertArrayHasKey('key', $metadata[0]);
        $this->assertArrayHasKey('label', $metadata[0]);
        $this->assertArrayHasKey('kind', $metadata[0]);
        $this->assertArrayHasKey('supports_webhook', $metadata[0]);
        $this->assertArrayHasKey('supports_webhook_provisioning', $metadata[0]);
        $this->assertArrayHasKey('fields', $metadata[0]);
        $this->assertSame('test_provider', $metadata[0]['key']);
        $this->assertSame('Test Provider', $metadata[0]['label']);
        $this->assertSame('smtp', $metadata[0]['kind']);
        $this->assertFalse($metadata[0]['supports_webhook']);
        $this->assertFalse($metadata[0]['supports_webhook_provisioning']);
    }

    private function makeProvider(string $key, string $label = 'Test', string $kind = 'smtp'): ProviderInterface
    {
        $mock = Mockery::mock(ProviderInterface::class);
        $mock->shouldReceive('key')->andReturn($key);
        $mock->shouldReceive('label')->andReturn($label);
        $mock->shouldReceive('kind')->andReturn($kind);
        $mock->shouldReceive('fields')->andReturn([
            ['key' => 'host', 'label' => 'Host', 'type' => 'text', 'required' => true, 'secret' => false],
        ]);

        return $mock;
    }
}
