<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\Cloudflare;

use BitApps\SMTP\Mail\Providers\Cloudflare\CloudflareValidator;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class CloudflareValidatorTest extends BaseUnitTestCase
{
    private CloudflareValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new CloudflareValidator();
    }

    public function testMissingAccountIdReturnsFieldError(): void
    {
        $errors = $this->validator->validate([], $this->validCredentials());

        $this->assertArrayHasKey('account_id', $errors);
    }

    public function testWhitespaceOnlyFlattenedApiTokenReturnsFieldError(): void
    {
        $errors = $this->validator->validate($this->validSettings(), [
            'api_token' => '   ',
        ]);

        $this->assertArrayHasKey('api_token', $errors);
    }

    public function testMissingApiTokenValueReturnsFieldError(): void
    {
        $errors = $this->validator->validate($this->validSettings(), []);

        $this->assertArrayHasKey('api_token', $errors);
    }

    public function testInvalidAccountIdCannotBeInterpolatedIntoEndpoint(): void
    {
        $errors = $this->validator->validate([
            'account_id' => '0123456789abcdef0123456789abcdef/forged',
        ], $this->validCredentials());

        $this->assertSame('Account ID is invalid.', $errors['account_id']);
    }

    public function testUppercaseAccountIdIsValid(): void
    {
        $errors = $this->validator->validate([
            'account_id' => 'ABCDEFABCDEFABCDEFABCDEFABCDEFAB',
        ], $this->validCredentials());

        $this->assertSame([], $errors);
    }

    public function testValidAccountIdAndCredentialValueHaveNoErrors(): void
    {
        $this->assertSame([], $this->validator->validate($this->validSettings(), $this->validCredentials()));
    }

    private function validSettings(): array
    {
        return ['account_id' => '0123456789abcdef0123456789abcdef'];
    }

    private function validCredentials(): array
    {
        return ['api_token' => 'cf-secret-token'];
    }
}
