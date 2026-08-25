<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers;

use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Exceptions\DuplicateProviderException;
use BitApps\SMTP\Mail\Exceptions\ProviderNotFoundException;
use BitApps\SMTP\Mail\Providers\ProviderRegistry;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
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

    public function testRequiresOAuth2TrueForOAuth2Provider(): void
    {
        $registry = new ProviderRegistry();
        $registry->register($this->makeProvider('oauth_provider', 'OAuth Provider', 'api', 'oauth2'));

        $this->assertTrue($registry->requiresOAuth2('oauth_provider'));
    }

    public function testRequiresOAuth2FalseForNonOAuthProvider(): void
    {
        $registry = new ProviderRegistry();
        $registry->register($this->makeProvider('api_key_provider', 'API Key Provider', 'api', 'bearer'));

        $this->assertFalse($registry->requiresOAuth2('api_key_provider'));
    }

    public function testRequiresOAuth2FalseForUnknownProvider(): void
    {
        $registry = new ProviderRegistry();

        $this->assertFalse($registry->requiresOAuth2('nonexistent'));
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

    public function testMetadataIncludesRedirectUrlForOAuth2Providers(): void
    {
        Functions\when('home_url')->justReturn('https://site.test/bit-smtp/oauth/callback');

        $registry = new ProviderRegistry();
        $registry->register($this->makeProvider('oauth_provider', 'OAuth Provider', 'api', 'oauth2'));

        $metadata = $registry->metadata();

        $this->assertSame(
            'https://site.test/bit-smtp/oauth/callback',
            $metadata[0]['oauth_redirect_url']
        );
    }

    public function testMetadataOmitsRedirectUrlForNonOAuth2Providers(): void
    {
        $registry = new ProviderRegistry();
        $registry->register($this->makeProvider('api_key_provider', 'API Key Provider', 'api', 'bearer'));

        $metadata = $registry->metadata();

        $this->assertArrayNotHasKey('oauth_redirect_url', $metadata[0]);
    }

    private function makeProvider(
        string $key,
        string $label = 'Test',
        string $kind = 'smtp',
        string $authType = 'basic'
    ): ProviderInterface {
        $mock = Mockery::mock(ProviderInterface::class);
        $mock->shouldReceive('key')->andReturn($key);
        $mock->shouldReceive('label')->andReturn($label);
        $mock->shouldReceive('kind')->andReturn($kind);
        $mock->shouldReceive('authConfig')->andReturn(['type' => $authType, 'params' => []]);
        $mock->shouldReceive('fields')->andReturn([
            ['key' => 'host', 'label' => 'Host', 'type' => 'text', 'required' => true, 'secret' => false],
        ]);

        return $mock;
    }
}
