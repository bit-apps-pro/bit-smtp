<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\Microsoft365;

use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;
use BitApps\SMTP\Mail\Providers\Microsoft365\Microsoft365Provider;
use BitApps\SMTP\Mail\Providers\Microsoft365\Microsoft365Validator;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
class Microsoft365ProviderTest extends BaseUnitTestCase
{
    private Microsoft365Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new Microsoft365Provider(Mockery::mock(TransportInterface::class));
    }

    public function testKeyReturnsMicrosoft365(): void
    {
        $this->assertSame('microsoft365', $this->provider->key());
    }

    public function testLabelReturnsMicrosoft365Outlook(): void
    {
        $this->assertSame('Microsoft 365 / Outlook', $this->provider->label());
    }

    public function testKindReturnsApi(): void
    {
        $this->assertSame('api', $this->provider->kind());
    }

    public function testDefaultsReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->provider->defaults());
    }

    public function testAuthConfigIsOAuth2(): void
    {
        $this->assertSame(['type' => 'oauth2', 'params' => []], $this->provider->authConfig());
    }

    public function testFieldsExposeClientIdClientSecretTenantAndOauth(): void
    {
        $keys = array_column($this->provider->fields(), 'key');

        $this->assertSame(['client_id', 'client_secret', 'tenant', 'oauth'], $keys);
    }

    public function testClientIdFieldIsRequiredTextAndNotSecret(): void
    {
        $field = $this->findField('client_id');

        $this->assertNotNull($field);
        $this->assertTrue($field['required']);
        $this->assertFalse($field['secret']);
        $this->assertSame('text', $field['type']);
    }

    public function testClientSecretFieldIsRequiredPasswordAndSecret(): void
    {
        $field = $this->findField('client_secret');

        $this->assertNotNull($field);
        $this->assertTrue($field['required']);
        $this->assertTrue($field['secret']);
        $this->assertSame('password', $field['type']);
    }

    public function testTenantFieldIsOptionalTextDefaultingToCommon(): void
    {
        $field = $this->findField('tenant');

        $this->assertNotNull($field);
        $this->assertSame('text', $field['type']);
        $this->assertFalse($field['required']);
        $this->assertFalse($field['secret']);
        $this->assertSame('common', $field['default']);
    }

    public function testFieldsDoNotExposeTokensAsUserFields(): void
    {
        $this->assertNull($this->findField('refresh_token'));
        $this->assertNull($this->findField('access_token'));
    }

    public function testOauthFieldIsOptionalAndNotSecret(): void
    {
        $field = $this->findField('oauth');

        $this->assertNotNull($field);
        $this->assertSame('oauth', $field['type']);
        $this->assertSame('Microsoft account', $field['label']);
        $this->assertFalse($field['required']);
        $this->assertFalse($field['secret']);
    }

    public function testFieldExposesAllRenderMetadataKeys(): void
    {
        $expectedKeys = ['key', 'label', 'type', 'required', 'secret', 'placeholder', 'default', 'options', 'dependsOn', 'help'];

        foreach ($this->provider->fields() as $field) {
            $this->assertSame($expectedKeys, array_keys($field), "Field '{$field['key']}' must expose exactly the render-metadata keys");
            $this->assertSame(['text', 'url', 'linkLabel'], array_keys($field['help']));
            $this->assertNotSame('', $field['help']['text']);
            $this->assertStringStartsWith('https://', $field['help']['url']);
            $this->assertNotSame('', $field['help']['linkLabel']);
        }
    }

    public function testTransportReturnsInjectedInstance(): void
    {
        $transport = Mockery::mock(TransportInterface::class);
        $provider  = new Microsoft365Provider($transport);

        $this->assertSame($transport, $provider->transport());
    }

    public function testValidatorReturnsMicrosoft365ValidatorInstance(): void
    {
        $this->assertInstanceOf(Microsoft365Validator::class, $this->provider->validator());
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
