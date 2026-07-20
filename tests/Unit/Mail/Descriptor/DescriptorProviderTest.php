<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Descriptor;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Descriptor\DescriptorApiTransport;
use BitApps\SMTP\Mail\Descriptor\DescriptorProvider;
use BitApps\SMTP\Mail\Descriptor\ProviderDescriptor;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use BitApps\SMTP\Mail\Validation\RequiredFieldsValidator;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 *
 * @coversNothing
 */
class DescriptorProviderTest extends BaseUnitTestCase
{
    private AuthorizationResolver $resolver;

    private ApiClient $apiClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver  = new AuthorizationResolver(Mockery::mock(OAuth2TokenProvider::class), new SigV4Signer());
        $this->apiClient = Mockery::mock(ApiClient::class);
    }

    public function testDelegatesMetadataToDescriptor(): void
    {
        $provider = $this->provider();

        $this->assertSame('synthetic', $provider->key());
        $this->assertSame('Synthetic', $provider->label());
        $this->assertSame('api', $provider->kind());
        $this->assertSame(['type' => 'bearer', 'params' => ['credentialKey' => 'api_key']], $provider->authConfig());
    }

    public function testFieldsDelegateToDescriptor(): void
    {
        $fields = $this->provider()->fields();

        $this->assertCount(2, $fields);
        $this->assertSame('api_key', $fields[0]['key']);
    }

    public function testDefaultsDeriveFromFieldDefaultsKeyedByFieldKey(): void
    {
        $this->assertSame(
            ['api_key' => '', 'region' => 'us'],
            $this->provider()->defaults()
        );
    }

    public function testValidatorIsRequiredFieldsValidator(): void
    {
        $this->assertInstanceOf(RequiredFieldsValidator::class, $this->provider()->validator());
    }

    public function testTransportReturnsConfiguredDescriptorApiTransport(): void
    {
        $this->assertInstanceOf(DescriptorApiTransport::class, $this->provider()->transport());
    }

    public function testTransportBuildsFreshInstanceEachCall(): void
    {
        $provider = $this->provider();

        $this->assertNotSame($provider->transport(), $provider->transport());
    }

    public function testTransportThrowsForUnknownEncoder(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->provider(['encoder' => 'protobuf'])->transport();
    }

    #[DataProvider('supportedEncoders')]
    public function testTransportBuildsForSupportedEncoders(string $encoder): void
    {
        $this->assertInstanceOf(DescriptorApiTransport::class, $this->provider(['encoder' => $encoder])->transport());
    }

    public static function supportedEncoders(): array
    {
        return [['json'], ['form'], ['mime_raw'], ['multipart']];
    }

    private function provider(array $overrides = []): DescriptorProvider
    {
        return new DescriptorProvider($this->descriptor($overrides), $this->apiClient, $this->resolver);
    }

    private function descriptor(array $overrides = []): ProviderDescriptor
    {
        return ProviderDescriptor::fromArray(array_merge([
            'key'    => 'synthetic',
            'label'  => 'Synthetic',
            'kind'   => 'api',
            'fields' => [
                ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true, 'secret' => true, 'default' => ''],
                ['key' => 'region', 'label' => 'Region', 'type' => 'select', 'required' => false, 'secret' => false, 'default' => 'us'],
            ],
            'auth'       => ['type' => 'bearer', 'params' => ['credentialKey' => 'api_key']],
            'endpoint'   => ['host' => 'api.example.com', 'path' => '/send'],
            'encoder'    => 'json',
            'payload'    => ['subject' => 'subject'],
            'success'    => [200],
            'errorPaths' => ['message'],
        ], $overrides));
    }
}
