<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\Gmail;

use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;
use BitApps\SMTP\Mail\Providers\Gmail\GmailProvider;
use BitApps\SMTP\Mail\Providers\Gmail\GmailValidator;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
class GmailProviderTest extends BaseUnitTestCase
{
    private GmailProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new GmailProvider(Mockery::mock(TransportInterface::class));
    }

    public function testKeyReturnsGmail(): void
    {
        $this->assertSame('gmail', $this->provider->key());
    }

    public function testLabelReturnsGmailGoogleWorkspace(): void
    {
        $this->assertSame('Gmail / Google Workspace', $this->provider->label());
    }

    public function testKindReturnsApi(): void
    {
        $this->assertSame('api', $this->provider->kind());
    }

    public function testDefaultsReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->provider->defaults());
    }

    public function testFieldsExposesClientIdClientSecretAndOauth(): void
    {
        $keys = array_column($this->provider->fields(), 'key');

        $this->assertSame(['client_id', 'client_secret', 'oauth'], $keys);
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

    public function testFieldsDoNotExposeRefreshTokenAsUserField(): void
    {
        $this->assertNull($this->findField('refresh_token'));
    }

    public function testOauthFieldIsOptionalAndNotSecret(): void
    {
        $field = $this->findField('oauth');

        $this->assertNotNull($field);
        $this->assertSame('oauth', $field['type']);
        $this->assertSame('Google account', $field['label']);
        $this->assertFalse($field['required']);
        $this->assertFalse($field['secret']);
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
        $provider  = new GmailProvider($transport);

        $this->assertSame($transport, $provider->transport());
    }

    public function testValidatorReturnsGmailValidatorInstance(): void
    {
        $this->assertInstanceOf(GmailValidator::class, $this->provider->validator());
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
