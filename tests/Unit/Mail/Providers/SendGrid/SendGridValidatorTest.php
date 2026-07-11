<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\SendGrid;

use BitApps\SMTP\Mail\Providers\SendGrid\SendGridValidator;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class SendGridValidatorTest extends BaseUnitTestCase
{
    private SendGridValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new SendGridValidator();
    }

    public function testMissingApiKeyCredentialReturnsError(): void
    {
        $result = $this->validator->validate([], []);

        $this->assertArrayHasKey('api_key', $result);
    }

    public function testEmptyStringApiKeyCredentialReturnsError(): void
    {
        $result = $this->validator->validate([], ['api_key' => '']);

        $this->assertArrayHasKey('api_key', $result);
    }

    public function testWhitespaceOnlyApiKeyCredentialReturnsError(): void
    {
        $result = $this->validator->validate([], ['api_key' => '   ']);

        $this->assertArrayHasKey('api_key', $result);
    }

    public function testPresentApiKeyCredentialIsValid(): void
    {
        $result = $this->validator->validate([], ['api_key' => 'SG.abc123']);

        $this->assertSame([], $result);
    }
}
