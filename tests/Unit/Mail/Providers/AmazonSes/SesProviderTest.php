<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\AmazonSes;

use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;
use BitApps\SMTP\Mail\Providers\AmazonSes\SesProvider;
use BitApps\SMTP\Mail\Providers\AmazonSes\SesValidator;
use BitApps\SMTP\Mail\Status\DeliveryStatus;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
class SesProviderTest extends BaseUnitTestCase
{
    private SesProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new SesProvider(Mockery::mock(TransportInterface::class));
    }

    public function testKeyReturnsAmazonSes(): void
    {
        $this->assertSame('amazon_ses', $this->provider->key());
    }

    public function testLabelReturnsAmazonSes(): void
    {
        $this->assertSame('Amazon SES', $this->provider->label());
    }

    public function testKindReturnsApi(): void
    {
        $this->assertSame('api', $this->provider->kind());
    }

    public function testDefaultsReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->provider->defaults());
    }

    public function testDeliveryStatusOnAcceptOnlyReportsProviderHandoff(): void
    {
        $this->assertSame(DeliveryStatus::ACCEPTED, $this->provider->deliveryStatusOnAccept());
    }

    public function testFieldsExposesAccessKeySecretKeyAndRegion(): void
    {
        $keys = array_column($this->provider->fields(), 'key');

        $this->assertSame(['access_key', 'secret_key', 'region'], $keys);
    }

    public function testAccessKeyFieldIsRequiredTextAndNotSecret(): void
    {
        $field = $this->findField('access_key');

        $this->assertNotNull($field);
        $this->assertTrue($field['required']);
        $this->assertFalse($field['secret']);
        $this->assertSame('text', $field['type']);
    }

    public function testSecretKeyFieldIsRequiredPasswordAndSecret(): void
    {
        $field = $this->findField('secret_key');

        $this->assertNotNull($field);
        $this->assertTrue($field['required']);
        $this->assertTrue($field['secret']);
        $this->assertSame('password', $field['type']);
    }

    public function testRegionFieldIsSelectWithCommonSesRegionsAndDefaultsToUsEast1(): void
    {
        $field = $this->findField('region');

        $this->assertNotNull($field);
        $this->assertSame('select', $field['type']);
        $this->assertTrue($field['required']);
        $this->assertFalse($field['secret']);
        $this->assertSame('us-east-1', $field['default']);

        $optionValues = array_column($field['options'], 'value');
        $this->assertSame(
            ['us-east-1', 'us-east-2', 'us-west-2', 'eu-west-1', 'eu-central-1', 'ap-south-1', 'ap-southeast-2'],
            $optionValues
        );
    }

    public function testFieldExposesAllRenderMetadataKeys(): void
    {
        $expectedKeys = ['key', 'label', 'type', 'required', 'secret', 'placeholder', 'default', 'options', 'dependsOn'];

        foreach ($this->provider->fields() as $field) {
            $this->assertSame($expectedKeys, array_keys($field), "Field '{$field['key']}' must expose exactly the render-metadata keys");
        }
    }

    public function testTransportReturnsInjectedInstance(): void
    {
        $transport = Mockery::mock(TransportInterface::class);
        $provider  = new SesProvider($transport);

        $this->assertSame($transport, $provider->transport());
    }

    public function testValidatorReturnsSesValidatorInstance(): void
    {
        $this->assertInstanceOf(SesValidator::class, $this->provider->validator());
    }

    public function testValidatorReturnsSameInstanceOnMultipleCalls(): void
    {
        $this->assertSame($this->provider->validator(), $this->provider->validator());
    }

    public function testValidatorSatisfiesValidatorInterface(): void
    {
        $this->assertInstanceOf(ValidatorInterface::class, $this->provider->validator());
    }

    private function findField(string $key): ?array
    {
        foreach ($this->provider->fields() as $field) {
            if ($field['key'] === $key) {
                return $field;
            }
        }

        return null;
    }
}
