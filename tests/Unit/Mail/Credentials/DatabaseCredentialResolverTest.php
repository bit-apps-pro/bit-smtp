<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Credentials;

use BitApps\SMTP\Mail\Credentials\Credential;
use BitApps\SMTP\Mail\Credentials\DatabaseCredentialResolver;
use BitApps\SMTP\Tests\BaseUnitTestCase;

class DatabaseCredentialResolverTest extends BaseUnitTestCase
{
    private DatabaseCredentialResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new DatabaseCredentialResolver();
    }

    public function testSupportsReturnsTrueForDatabaseSource(): void
    {
        $this->assertTrue($this->resolver->supports('database'));
    }

    public function testSupportsReturnsFalseForEnvSource(): void
    {
        $this->assertFalse($this->resolver->supports('env'));
    }

    public function testSupportsReturnsFalseForOtherSources(): void
    {
        $this->assertFalse($this->resolver->supports('other'));
    }

    public function testResolveReturnsValueForDatabaseCredential(): void
    {
        $credential = Credential::fromArray(['source' => 'database', 'value' => 'secret_value']);
        $result = $this->resolver->resolve($credential);

        $this->assertSame('secret_value', $result);
    }

    public function testResolveReturnsNullForNonDatabaseSource(): void
    {
        $credential = Credential::fromArray(['source' => 'env', 'value' => 'secret_value']);
        $result = $this->resolver->resolve($credential);

        $this->assertNull($result);
    }

    public function testResolveReturnsNullForEmptyValue(): void
    {
        $credential = Credential::fromArray(['source' => 'database', 'value' => '']);
        $result = $this->resolver->resolve($credential);

        $this->assertNull($result);
    }

    public function testResolveReturnsNullForNullValue(): void
    {
        $credential = Credential::fromArray(['source' => 'database', 'value' => null]);
        $result = $this->resolver->resolve($credential);

        $this->assertNull($result);
    }

    public function testResolveReturnsZeroStringValue(): void
    {
        $credential = Credential::fromArray(['source' => 'database', 'value' => '0']);
        $result = $this->resolver->resolve($credential);

        $this->assertSame('0', $result);
    }
}
