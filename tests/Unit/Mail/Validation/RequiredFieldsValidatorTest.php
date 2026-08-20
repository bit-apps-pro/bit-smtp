<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Validation;

use BitApps\SMTP\Mail\Validation\RequiredFieldsValidator;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class RequiredFieldsValidatorTest extends BaseUnitTestCase
{
    public function testMissingRequiredSecretReportsError(): void
    {
        $v = new RequiredFieldsValidator($this->fields());

        $errors = $v->validate(['region' => 'us'], []);

        $this->assertArrayHasKey('api_key', $errors);
        $this->assertArrayNotHasKey('region', $errors);
    }

    public function testAllPresentIsValid(): void
    {
        $v = new RequiredFieldsValidator($this->fields());

        $this->assertSame([], $v->validate(['region' => 'us'], ['api_key' => 'k']));
    }

    public function testOauthMarkerFieldIsSkipped(): void
    {
        $v = new RequiredFieldsValidator($this->fields());

        $this->assertArrayNotHasKey('oauth', $v->validate(['region' => 'us'], ['api_key' => 'k']));
    }

    public function testMissingRequiredNonSecretFieldReportsError(): void
    {
        $v = new RequiredFieldsValidator($this->fields());

        $errors = $v->validate([], ['api_key' => 'k']);

        $this->assertArrayHasKey('region', $errors);
    }

    public function testWhitespaceOnlyValueIsTreatedAsMissing(): void
    {
        $v = new RequiredFieldsValidator($this->fields());

        $errors = $v->validate(['region' => '  '], ['api_key' => '   ']);

        $this->assertArrayHasKey('api_key', $errors);
        $this->assertArrayHasKey('region', $errors);
    }

    public function testCredentialsArriveAsScalarKeyValueNotSourceValueShape(): void
    {
        $v = new RequiredFieldsValidator($this->fields());

        // The validator reads $credentials['api_key'] directly as a scalar; a normalized
        // {source,value} shape would fail trim() and wrongly report the field as missing.
        $errors = $v->validate(['region' => 'us'], ['api_key' => 'k']);

        $this->assertArrayNotHasKey('api_key', $errors);
    }

    private function fields(): array
    {
        return [
            ['key' => 'api_key', 'label' => 'API Key', 'required' => true, 'secret' => true, 'type' => 'password'],
            ['key' => 'region', 'label' => 'Region', 'required' => true, 'secret' => false, 'type' => 'select'],
            ['key' => 'oauth', 'label' => 'Account', 'required' => true, 'secret' => false, 'type' => 'oauth'],
        ];
    }
}
