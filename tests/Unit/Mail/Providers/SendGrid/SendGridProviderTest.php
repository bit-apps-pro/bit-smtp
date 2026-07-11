<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\SendGrid;

use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;
use BitApps\SMTP\Mail\Providers\SendGrid\SendGridProvider;
use BitApps\SMTP\Mail\Providers\SendGrid\SendGridValidator;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
class SendGridProviderTest extends BaseUnitTestCase
{
    private SendGridProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new SendGridProvider(Mockery::mock(TransportInterface::class));
    }

    public function testKeyReturnsSendgrid(): void
    {
        $this->assertSame('sendgrid', $this->provider->key());
    }

    public function testLabelReturnsSendGrid(): void
    {
        $this->assertSame('SendGrid', $this->provider->label());
    }

    public function testKindReturnsApi(): void
    {
        $this->assertSame('api', $this->provider->kind());
    }

    public function testDefaultsReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->provider->defaults());
    }

    public function testFieldsExposesSingleApiKeyField(): void
    {
        $fields = $this->provider->fields();

        $this->assertCount(1, $fields);
        $this->assertSame('api_key', $fields[0]['key']);
    }

    public function testApiKeyFieldHasSecretTrue(): void
    {
        $field = $this->findField('api_key');

        $this->assertNotNull($field);
        $this->assertTrue($field['secret']);
    }

    public function testApiKeyFieldHasTypePassword(): void
    {
        $field = $this->findField('api_key');

        $this->assertNotNull($field);
        $this->assertSame('password', $field['type']);
    }

    public function testApiKeyFieldIsRequired(): void
    {
        $field = $this->findField('api_key');

        $this->assertNotNull($field);
        $this->assertTrue($field['required']);
    }

    public function testApiKeyFieldLabelIsApiKey(): void
    {
        $field = $this->findField('api_key');

        $this->assertNotNull($field);
        $this->assertSame('API Key', $field['label']);
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
        $provider  = new SendGridProvider($transport);

        $this->assertSame($transport, $provider->transport());
    }

    public function testValidatorReturnsSendGridValidatorInstance(): void
    {
        $this->assertInstanceOf(SendGridValidator::class, $this->provider->validator());
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
