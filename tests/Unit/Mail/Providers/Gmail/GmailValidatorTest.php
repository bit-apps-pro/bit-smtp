<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\Gmail;

use BitApps\SMTP\Mail\Providers\Gmail\GmailValidator;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class GmailValidatorTest extends BaseUnitTestCase
{
    private GmailValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new GmailValidator();
    }

    public function testMissingClientIdReturnsError(): void
    {
        $result = $this->validator->validate([], $this->validCredentials());

        $this->assertArrayHasKey('client_id', $result);
    }

    public function testWhitespaceOnlyClientIdReturnsError(): void
    {
        $result = $this->validator->validate(['client_id' => '   '], $this->validCredentials());

        $this->assertArrayHasKey('client_id', $result);
    }

    public function testMissingClientSecretReturnsError(): void
    {
        $credentials = $this->validCredentials();
        unset($credentials['client_secret']);

        $result = $this->validator->validate($this->validSettings(), $credentials);

        $this->assertArrayHasKey('client_secret', $result);
    }

    public function testEmptyStringClientSecretReturnsError(): void
    {
        $credentials                    = $this->validCredentials();
        $credentials['client_secret']   = '';

        $result = $this->validator->validate($this->validSettings(), $credentials);

        $this->assertArrayHasKey('client_secret', $result);
    }

    public function testMissingRefreshTokenReturnsError(): void
    {
        $credentials = $this->validCredentials();
        unset($credentials['refresh_token']);

        $result = $this->validator->validate($this->validSettings(), $credentials);

        $this->assertArrayHasKey('refresh_token', $result);
    }

    public function testEmptyStringRefreshTokenReturnsError(): void
    {
        $credentials                     = $this->validCredentials();
        $credentials['refresh_token']    = '   ';

        $result = $this->validator->validate($this->validSettings(), $credentials);

        $this->assertArrayHasKey('refresh_token', $result);
    }

    public function testAllPresentIsValid(): void
    {
        $result = $this->validator->validate($this->validSettings(), $this->validCredentials());

        $this->assertSame([], $result);
    }

    private function validSettings(): array
    {
        return ['client_id' => 'cid123'];
    }

    private function validCredentials(): array
    {
        return ['client_secret' => 'secret123', 'refresh_token' => 'refresh123'];
    }
}
