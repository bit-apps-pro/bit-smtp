<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\OtherSmtp;

use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;
use BitApps\SMTP\Mail\Providers\OtherSmtp\OtherSmtpProvider;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;

class OtherSmtpProviderTest extends BaseUnitTestCase
{
    private OtherSmtpProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new OtherSmtpProvider(Mockery::mock(TransportInterface::class));
    }

    public function testKeyReturnsOtherSmtp(): void
    {
        $this->assertSame('other_smtp', $this->provider->key());
    }

    public function testLabelReturnsOtherSmtp(): void
    {
        $this->assertSame('Other SMTP', $this->provider->label());
    }

    public function testKindReturnsSmtp(): void
    {
        $this->assertSame('smtp', $this->provider->kind());
    }

    public function testDefaultsReturnsExpectedValues(): void
    {
        $this->assertSame(['port' => 587, 'encryption' => 'tls', 'auth' => true], $this->provider->defaults());
    }

    public function testPasswordFieldHasSecretTrue(): void
    {
        $passwordField = $this->findField('password');

        $this->assertNotNull($passwordField);
        $this->assertTrue($passwordField['secret']);
    }

    public function testPasswordFieldHasTypePassword(): void
    {
        $passwordField = $this->findField('password');

        $this->assertNotNull($passwordField);
        $this->assertSame('password', $passwordField['type']);
    }

    public function testNonPasswordFieldsHaveSecretFalse(): void
    {
        foreach ($this->provider->fields() as $field) {
            if ($field['key'] !== 'password') {
                $this->assertFalse($field['secret']);
            }
        }
    }

    public function testTransportReturnsInjectedInstance(): void
    {
        $transport = Mockery::mock(TransportInterface::class);
        $provider  = new OtherSmtpProvider($transport);

        $this->assertSame($transport, $provider->transport());
    }

    public function testValidatorReturnsValidatorInterface(): void
    {
        $this->assertInstanceOf(ValidatorInterface::class, $this->provider->validator());
    }

    public function testValidatorReturnsSameInstanceOnMultipleCalls(): void
    {
        $this->assertSame($this->provider->validator(), $this->provider->validator());
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
