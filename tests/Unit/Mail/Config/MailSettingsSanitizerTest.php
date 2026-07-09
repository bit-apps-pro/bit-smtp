<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Config;

use BitApps\SMTP\Mail\Config\MailSettingsSanitizer;
use BitApps\SMTP\Tests\BaseUnitTestCase;

class MailSettingsSanitizerTest extends BaseUnitTestCase
{
    private function baseV2(): array
    {
        return [
            'schema_version'          => 2,
            'enabled'                 => false,
            'default_connection_id'   => '',
            'fallback_connection_ids' => [],
            'connections'             => [],
            'features'                => [
                'logging'        => [],
                'alerts'         => [],
                'routing'        => [],
                'tracking'       => [],
                'email_controls' => [],
            ],
        ];
    }

    public function testDropsUnknownTopLevelKeys(): void
    {
        $input           = $this->baseV2();
        $input['foo']    = 'bar';
        $result = MailSettingsSanitizer::sanitize($input);

        $this->assertArrayNotHasKey('foo', $result);
    }

    public function testCoercesSchemaVersion(): void
    {
        $input                   = $this->baseV2();
        $input['schema_version'] = '2';
        $result = MailSettingsSanitizer::sanitize($input);

        $this->assertSame(2, $result['schema_version']);
        $this->assertIsInt($result['schema_version']);
    }

    public function testCoercesEnabled(): void
    {
        $input            = $this->baseV2();
        $input['enabled'] = '1';
        $result = MailSettingsSanitizer::sanitize($input);

        $this->assertTrue($result['enabled']);
        $this->assertIsBool($result['enabled']);
    }

    public function testCoercesConnectionPort(): void
    {
        $input                = $this->baseV2();
        $input['connections'] = [
            [
                'id'           => 'conn_1',
                'provider'     => 'other_smtp',
                'kind'         => 'smtp',
                'name'         => 'Test',
                'enabled'      => true,
                'fromEmail'    => '',
                'fromName'     => '',
                'replyToEmail' => '',
                'settings'     => ['port' => '465'],
                'credentials'  => [],
            ],
        ];
        $result = MailSettingsSanitizer::sanitize($input);

        $this->assertSame(465, $result['connections'][0]['settings']['port']);
        $this->assertIsInt($result['connections'][0]['settings']['port']);
    }

    public function testTrimsStringFields(): void
    {
        $input                = $this->baseV2();
        $input['connections'] = [
            [
                'id'           => 'conn_1',
                'provider'     => 'other_smtp',
                'kind'         => 'smtp',
                'name'         => 'Test',
                'enabled'      => true,
                'fromEmail'    => '  test@example.com  ',
                'fromName'     => '',
                'replyToEmail' => '',
                'settings'     => [],
                'credentials'  => [],
            ],
        ];
        $result = MailSettingsSanitizer::sanitize($input);

        $this->assertSame('test@example.com', $result['connections'][0]['fromEmail']);
    }

    public function testDropsUnknownFeatureKeys(): void
    {
        $input             = $this->baseV2();
        $input['features'] = ['logging' => [], 'unknown_key' => []];
        $result = MailSettingsSanitizer::sanitize($input);

        $this->assertArrayNotHasKey('unknown_key', $result['features']);
        $this->assertArrayHasKey('logging', $result['features']);
    }

    public function testFallbackConnectionIdsEnsuredArray(): void
    {
        $input = $this->baseV2();
        unset($input['fallback_connection_ids']);
        $result = MailSettingsSanitizer::sanitize($input);

        $this->assertSame([], $result['fallback_connection_ids']);
    }

    public function testCredentialWhitelistStripsUnknownKeysAndPreservesValue(): void
    {
        $input                = $this->baseV2();
        $input['connections'] = [
            [
                'id'           => 'conn_1',
                'provider'     => 'other_smtp',
                'kind'         => 'smtp',
                'name'         => 'Test',
                'enabled'      => true,
                'fromEmail'    => '',
                'fromName'     => '',
                'replyToEmail' => '',
                'settings'     => [],
                'credentials'  => [
                    'password' => [
                        'source'  => 'database',
                        'value'   => 'secret123',
                        'bogus'   => 'evil_payload',
                        'another' => ['nested' => 'data'],
                    ],
                ],
            ],
        ];
        $result      = MailSettingsSanitizer::sanitize($input);
        $credentials = $result['connections'][0]['credentials'];

        $this->assertSame('database', $credentials['password']['source']);
        $this->assertSame('secret123', $credentials['password']['value']);
        $this->assertArrayNotHasKey('bogus', $credentials['password']);
        $this->assertArrayNotHasKey('another', $credentials['password']);
    }

    public function testCredentialAbsentDefaultsToEmptyArray(): void
    {
        $input                = $this->baseV2();
        $input['connections'] = [
            [
                'id'           => 'conn_1',
                'provider'     => 'other_smtp',
                'kind'         => 'smtp',
                'name'         => 'Test',
                'enabled'      => true,
                'fromEmail'    => '',
                'fromName'     => '',
                'replyToEmail' => '',
                'settings'     => [],
            ],
        ];
        $result = MailSettingsSanitizer::sanitize($input);

        $this->assertSame([], $result['connections'][0]['credentials']);
    }

    public function testEncryptionEmptyStringNormalizedToNone(): void
    {
        $input                = $this->baseV2();
        $input['connections'] = [
            [
                'id'           => 'conn_1',
                'provider'     => 'other_smtp',
                'kind'         => 'smtp',
                'name'         => 'Test',
                'enabled'      => true,
                'fromEmail'    => '',
                'fromName'     => '',
                'replyToEmail' => '',
                'settings'     => ['encryption' => ''],
                'credentials'  => [],
            ],
        ];
        $result = MailSettingsSanitizer::sanitize($input);

        $this->assertSame('none', $result['connections'][0]['settings']['encryption']);
    }

    public function testEncryptionAbsentNormalizedToNone(): void
    {
        $input                = $this->baseV2();
        $input['connections'] = [
            [
                'id'           => 'conn_1',
                'provider'     => 'other_smtp',
                'kind'         => 'smtp',
                'name'         => 'Test',
                'enabled'      => true,
                'fromEmail'    => '',
                'fromName'     => '',
                'replyToEmail' => '',
                'settings'     => [],
                'credentials'  => [],
            ],
        ];
        $result = MailSettingsSanitizer::sanitize($input);

        $this->assertSame('none', $result['connections'][0]['settings']['encryption']);
    }

    public function testEncryptionPresentValuePreserved(): void
    {
        $input                = $this->baseV2();
        $input['connections'] = [
            [
                'id'           => 'conn_1',
                'provider'     => 'other_smtp',
                'kind'         => 'smtp',
                'name'         => 'Test',
                'enabled'      => true,
                'fromEmail'    => '',
                'fromName'     => '',
                'replyToEmail' => '',
                'settings'     => ['encryption' => 'tls'],
                'credentials'  => [],
            ],
        ];
        $result = MailSettingsSanitizer::sanitize($input);

        $this->assertSame('tls', $result['connections'][0]['settings']['encryption']);
    }
}
