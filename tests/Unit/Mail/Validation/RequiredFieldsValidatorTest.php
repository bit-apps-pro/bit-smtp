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

        $errors = $v->validate(['region' => 'us'], ['api_key' => 'k']);

        $this->assertArrayNotHasKey('api_key', $errors);
    }

    public function testRequiredWhenFieldIsRequiredOnlyWhileConditionHolds(): void
    {
        $v = new RequiredFieldsValidator([
            ['key' => 'username', 'label' => 'Username', 'required' => false, 'secret' => false, 'type' => 'text', 'requiredWhen' => ['field' => 'auth', 'value' => true]],
            ['key' => 'password', 'label' => 'Password', 'required' => false, 'secret' => true, 'type' => 'password', 'requiredWhen' => ['field' => 'auth', 'value' => true]],
        ]);

        $this->assertSame(
            ['username' => 'Username is required.', 'password' => 'Password is required.'],
            $v->validate(['auth' => true], [])
        );
        $this->assertSame([], $v->validate(['auth' => false], []));
        $this->assertSame([], $v->validate(['auth' => true, 'username' => 'u'], ['password' => 'p']));
    }

    public function testABooleanRequiredWhenMatchesByTruthinessAndAnyOtherValueStrictly(): void
    {
        $v = new RequiredFieldsValidator([
            ['key' => 'username', 'label' => 'Username', 'required' => false, 'secret' => false, 'type' => 'text', 'requiredWhen' => ['field' => 'auth', 'value' => true]],
            ['key' => 'region', 'label' => 'Region', 'required' => false, 'secret' => false, 'type' => 'text', 'requiredWhen' => ['field' => 'mode', 'value' => 'custom']],
        ]);

        $this->assertArrayHasKey('username', $v->validate(['auth' => '1'], []));
        $this->assertArrayNotHasKey('username', $v->validate(['auth' => 0], []));
        $this->assertArrayHasKey('region', $v->validate(['mode' => 'custom'], []));
        $this->assertArrayNotHasKey('region', $v->validate(['mode' => 'Custom'], []));
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
