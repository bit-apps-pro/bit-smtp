<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\OtherSmtp;

use BitApps\SMTP\Mail\Providers\OtherSmtp\OtherSmtpValidator;
use BitApps\SMTP\Tests\BaseUnitTestCase;

class OtherSmtpValidatorTest extends BaseUnitTestCase
{
    public function testMissingHostReturnsHostError(): void
    {
        $validator = new OtherSmtpValidator();
        $settings = $this->validSettings();
        $settings['host'] = '';

        $result = $validator->validate($settings, []);

        $this->assertArrayHasKey('host', $result);
    }

    public function testPortZeroReturnsPortError(): void
    {
        $validator = new OtherSmtpValidator();
        $settings = $this->validSettings();
        $settings['port'] = 0;

        $result = $validator->validate($settings, []);

        $this->assertArrayHasKey('port', $result);
    }

    public function testPort65536ReturnsPortError(): void
    {
        $validator = new OtherSmtpValidator();
        $settings = $this->validSettings();
        $settings['port'] = 65536;

        $result = $validator->validate($settings, []);

        $this->assertArrayHasKey('port', $result);
    }

    public function testPort587IsValid(): void
    {
        $validator = new OtherSmtpValidator();
        $settings = $this->validSettings();
        $settings['port'] = 587;

        $result = $validator->validate($settings, []);

        $this->assertArrayNotHasKey('port', $result);
    }

    public function testBadEncryptionReturnsEncryptionError(): void
    {
        $validator = new OtherSmtpValidator();
        $settings = $this->validSettings();
        $settings['encryption'] = 'ftp';

        $result = $validator->validate($settings, []);

        $this->assertArrayHasKey('encryption', $result);
    }

    public function testEncryptionNoneIsValid(): void
    {
        $validator = new OtherSmtpValidator();
        $settings = $this->validSettings();
        $settings['encryption'] = 'none';

        $result = $validator->validate($settings, []);

        $this->assertArrayNotHasKey('encryption', $result);
    }

    public function testEncryptionSslIsValid(): void
    {
        $validator = new OtherSmtpValidator();
        $settings = $this->validSettings();
        $settings['encryption'] = 'ssl';

        $result = $validator->validate($settings, []);

        $this->assertArrayNotHasKey('encryption', $result);
    }

    public function testEncryptionTlsIsValid(): void
    {
        $validator = new OtherSmtpValidator();
        $settings = $this->validSettings();
        $settings['encryption'] = 'tls';

        $result = $validator->validate($settings, []);

        $this->assertArrayNotHasKey('encryption', $result);
    }

    public function testAuthTruthyEmptyUsernameReturnsUsernameError(): void
    {
        $validator = new OtherSmtpValidator();
        $settings = $this->validSettings();
        $settings['auth'] = true;
        $settings['username'] = '';

        $result = $validator->validate($settings, []);

        $this->assertArrayHasKey('username', $result);
    }

    public function testAuthTruthyNoPasswordCredentialReturnsPasswordError(): void
    {
        $validator = new OtherSmtpValidator();
        $settings = $this->validSettings();
        $settings['auth'] = true;
        $settings['username'] = 'user';

        $result = $validator->validate($settings, []);

        $this->assertArrayHasKey('password', $result);
    }

    public function testAuthTruthyWithUsernameAndPasswordHasNoErrors(): void
    {
        $validator = new OtherSmtpValidator();
        $settings = $this->validSettings();
        $settings['auth'] = true;
        $settings['username'] = 'user';
        $credentials = ['password' => 'secret'];

        $result = $validator->validate($settings, $credentials);

        $this->assertArrayNotHasKey('username', $result);
        $this->assertArrayNotHasKey('password', $result);
    }

    public function testFullyValidConfigReturnsEmptyArray(): void
    {
        $validator = new OtherSmtpValidator();
        $settings = $this->validSettings();

        $result = $validator->validate($settings, []);

        $this->assertSame([], $result);
    }

    public function testPortOneLowerBoundIsValid(): void
    {
        $validator = new OtherSmtpValidator();
        $settings = $this->validSettings();
        $settings['port'] = 1;

        $result = $validator->validate($settings, []);

        $this->assertArrayNotHasKey('port', $result);
    }

    public function testPort65535UpperBoundIsValid(): void
    {
        $validator = new OtherSmtpValidator();
        $settings = $this->validSettings();
        $settings['port'] = 65535;

        $result = $validator->validate($settings, []);

        $this->assertArrayNotHasKey('port', $result);
    }

    public function testPortNumericStringIsValid(): void
    {
        $validator = new OtherSmtpValidator();
        $settings = $this->validSettings();
        $settings['port'] = '587';

        $result = $validator->validate($settings, []);

        $this->assertArrayNotHasKey('port', $result);
    }

    public function testPortNonNumericStringReturnsDistinctError(): void
    {
        $validator = new OtherSmtpValidator();
        $settings = $this->validSettings();
        $settings['port'] = 'abc';

        $result = $validator->validate($settings, []);

        $this->assertArrayHasKey('port', $result);
        $this->assertSame('Port must be a number.', $result['port']);
    }

    public function testPasswordOfZeroStringIsValid(): void
    {
        $validator = new OtherSmtpValidator();
        $settings = $this->validSettings();
        $settings['auth'] = true;
        $settings['username'] = 'user';
        $credentials = ['password' => '0'];

        $result = $validator->validate($settings, $credentials);

        $this->assertArrayNotHasKey('password', $result);
    }

    private function validSettings(): array
    {
        return ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'auth' => false];
    }
}
