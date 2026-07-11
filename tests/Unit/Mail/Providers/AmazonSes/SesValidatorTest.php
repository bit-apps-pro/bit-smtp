<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\AmazonSes;

use BitApps\SMTP\Mail\Providers\AmazonSes\SesValidator;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class SesValidatorTest extends BaseUnitTestCase
{
    private SesValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new SesValidator();
    }

    public function testMissingAccessKeyReturnsError(): void
    {
        $result = $this->validator->validate(['region' => 'us-east-1'], $this->validCredentials());

        $this->assertArrayHasKey('access_key', $result);
    }

    public function testWhitespaceOnlyAccessKeyReturnsError(): void
    {
        $result = $this->validator->validate(['access_key' => '   ', 'region' => 'us-east-1'], $this->validCredentials());

        $this->assertArrayHasKey('access_key', $result);
    }

    public function testMissingSecretKeyReturnsError(): void
    {
        $result = $this->validator->validate($this->validSettings(), []);

        $this->assertArrayHasKey('secret_key', $result);
    }

    public function testEmptyStringSecretKeyReturnsError(): void
    {
        $result = $this->validator->validate($this->validSettings(), ['secret_key' => '']);

        $this->assertArrayHasKey('secret_key', $result);
    }

    public function testMissingRegionReturnsError(): void
    {
        $result = $this->validator->validate(['access_key' => 'AKIDEXAMPLE'], $this->validCredentials());

        $this->assertArrayHasKey('region', $result);
    }

    public function testEmptyStringRegionReturnsError(): void
    {
        $result = $this->validator->validate(['access_key' => 'AKIDEXAMPLE', 'region' => ''], $this->validCredentials());

        $this->assertArrayHasKey('region', $result);
    }

    public function testRegionContainingHostInjectionCharactersReturnsError(): void
    {
        $result = $this->validator->validate(
            ['access_key' => 'AKIDEXAMPLE', 'region' => 'us-east-1.attacker.example'],
            $this->validCredentials()
        );

        $this->assertArrayHasKey('region', $result);
    }

    public function testAllPresentIsValid(): void
    {
        $result = $this->validator->validate($this->validSettings(), $this->validCredentials());

        $this->assertSame([], $result);
    }

    private function validSettings(): array
    {
        return ['access_key' => 'AKIDEXAMPLE', 'region' => 'us-east-1'];
    }

    private function validCredentials(): array
    {
        return ['secret_key' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY'];
    }
}
