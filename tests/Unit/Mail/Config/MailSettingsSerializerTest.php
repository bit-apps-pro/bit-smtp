<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Config;

use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Config\MailSettingsSerializer;
use BitApps\SMTP\Tests\BaseUnitTestCase;

class MailSettingsSerializerTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Brain\Monkey\Functions\when('wp_generate_uuid4')->justReturn('test-uuid-1234');
    }

    private function v2Array(): array
    {
        return [
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => 'conn_abc',
            'fallback_connection_ids' => [],
            'connections'             => [
                [
                    'id'           => 'conn_abc',
                    'provider'     => 'other_smtp',
                    'kind'         => 'smtp',
                    'name'         => 'Primary SMTP',
                    'enabled'      => true,
                    'fromEmail'    => 'from@example.com',
                    'fromName'     => 'Sender',
                    'replyToEmail' => 'reply@example.com',
                    'settings'     => [
                        'host'       => 'smtp.example.com',
                        'port'       => 587,
                        'encryption' => 'tls',
                        'auth'       => true,
                        'username'   => 'user@example.com',
                        'smtp_debug' => false,
                    ],
                    'credentials'  => [
                        'password' => ['source' => 'database', 'value' => 'secret123'],
                    ],
                ],
            ],
            'features' => [],
        ];
    }

    public function testToLegacyShapeFlattensDefaultConnection(): void
    {
        $settings = MailSettings::fromArray($this->v2Array());
        $result   = MailSettingsSerializer::toLegacyShape($settings);

        $this->assertArrayHasKey('status',             $result);
        $this->assertArrayHasKey('from_email_address', $result);
        $this->assertArrayHasKey('from_name',          $result);
        $this->assertArrayHasKey('re_email_address',   $result);
        $this->assertArrayHasKey('smtp_host',          $result);
        $this->assertArrayHasKey('encryption',         $result);
        $this->assertArrayHasKey('port',               $result);
        $this->assertArrayHasKey('smtp_auth',          $result);
        $this->assertArrayHasKey('smtp_debug',         $result);
        $this->assertArrayHasKey('smtp_user_name',     $result);
        $this->assertArrayHasKey('smtp_password',      $result);

        $this->assertTrue($result['status']);
        $this->assertSame('from@example.com',  $result['from_email_address']);
        $this->assertSame('Sender',            $result['from_name']);
        $this->assertSame('reply@example.com', $result['re_email_address']);
        $this->assertSame('smtp.example.com',  $result['smtp_host']);
        $this->assertSame('tls',               $result['encryption']);
        $this->assertSame(587,                 $result['port']);
        $this->assertTrue($result['smtp_auth']);
        $this->assertFalse($result['smtp_debug']);
        $this->assertSame('user@example.com',  $result['smtp_user_name']);
        $this->assertSame('secret123',         $result['smtp_password']);
    }

    public function testToLegacyShapeEmitsPlaintextPassword(): void
    {
        $settings = MailSettings::fromArray($this->v2Array());
        $result   = MailSettingsSerializer::toLegacyShape($settings);

        $this->assertSame('secret123', $result['smtp_password']);
    }

    public function testToLegacyShapeWhenNoDefaultConnectionReturnsEmptyShape(): void
    {
        $data                         = $this->v2Array();
        $data['default_connection_id'] = 'nonexistent';
        $settings                     = MailSettings::fromArray($data);
        $result                       = MailSettingsSerializer::toLegacyShape($settings);

        $this->assertArrayHasKey('status',             $result);
        $this->assertArrayHasKey('from_email_address', $result);
        $this->assertArrayHasKey('from_name',          $result);
        $this->assertArrayHasKey('re_email_address',   $result);
        $this->assertArrayHasKey('smtp_host',          $result);
        $this->assertArrayHasKey('encryption',         $result);
        $this->assertArrayHasKey('port',               $result);
        $this->assertArrayHasKey('smtp_auth',          $result);
        $this->assertArrayHasKey('smtp_debug',         $result);
        $this->assertArrayHasKey('smtp_user_name',     $result);
        $this->assertArrayHasKey('smtp_password',      $result);

        $this->assertFalse($result['status']);
        $this->assertSame('', $result['smtp_password']);
        $this->assertSame(0,  $result['port']);
    }

    public function testToLegacyShapeStatusFalseWhenSettingsDisabled(): void
    {
        $data            = $this->v2Array();
        $data['enabled'] = false;
        $settings        = MailSettings::fromArray($data);
        $result          = MailSettingsSerializer::toLegacyShape($settings);

        $this->assertFalse($result['status']);
    }

    public function testToLegacyShapeStatusFalseWhenConnectionDisabled(): void
    {
        $data                                  = $this->v2Array();
        $data['connections'][0]['enabled']     = false;
        $settings                              = MailSettings::fromArray($data);
        $result                                = MailSettingsSerializer::toLegacyShape($settings);

        $this->assertFalse($result['status']);
    }

    public function testFromLegacyShapeProducesV2Array(): void
    {
        $flat = [
            'status'               => '1',
            'from_email_address'   => 'from@example.com',
            'from_name'            => 'Sender',
            're_email_address'     => 'reply@example.com',
            'smtp_host'            => 'smtp.example.com',
            'port'                 => 587,
            'encryption'           => 'tls',
            'smtp_auth'            => true,
            'smtp_user_name'       => 'user@example.com',
            'smtp_password'        => 'secret123',
            'smtp_debug'           => false,
        ];

        $result = MailSettingsSerializer::fromLegacyShape($flat);

        $this->assertSame(2, $result['schema_version']);
        $this->assertNotEmpty($result['connections']);
        $this->assertArrayHasKey('id', $result['connections'][0]);
    }

    public function testFromLegacyShapePreservesExistingPasswordWhenIncomingIsEmpty(): void
    {
        $current = MailSettings::fromArray($this->v2Array());
        $flat    = ['status' => '1', 'smtp_host' => 'h', 'port' => 25];

        $result = MailSettingsSerializer::fromLegacyShape($flat, $current);

        $this->assertSame('secret123', $result['connections'][0]['credentials']['password']['value']);
    }

    public function testFromLegacyShapeDoesNotPreservePasswordWhenIncomingProvided(): void
    {
        $current = MailSettings::fromArray($this->v2Array());
        $flat    = ['status' => '1', 'smtp_host' => 'h', 'port' => 25, 'smtp_password' => 'newpass'];

        $result = MailSettingsSerializer::fromLegacyShape($flat, $current);

        $this->assertSame('newpass', $result['connections'][0]['credentials']['password']['value']);
    }

    public function testFromLegacyShapeDoesNotPreservePasswordWhenNoCurrentProvided(): void
    {
        $flat   = ['status' => '1', 'smtp_host' => 'h', 'port' => 25];
        $result = MailSettingsSerializer::fromLegacyShape($flat);

        $this->assertSame('', $result['connections'][0]['credentials']['password']['value']);
    }

    public function testLegacyRoundTrip(): void
    {
        $original = MailSettings::fromArray($this->v2Array());
        $legacy   = MailSettingsSerializer::toLegacyShape($original);
        $v2Again  = MailSettingsSerializer::fromLegacyShape($legacy, $original);
        $settings2 = MailSettings::fromArray($v2Again);
        $conn2     = $settings2->defaultConnection();

        $this->assertSame(2,                  $v2Again['schema_version']);
        $this->assertTrue($v2Again['enabled']);
        $this->assertNotNull($conn2);
        $this->assertSame('from@example.com',  $conn2->getFromEmail());
        $this->assertSame('Sender',            $conn2->getFromName());
        $this->assertSame('reply@example.com', $conn2->getReplyToEmail());
        $this->assertSame('smtp.example.com',  $conn2->setting('host'));
        $this->assertSame(587,                 $conn2->setting('port'));
        $this->assertSame('tls',               $conn2->setting('encryption'));
        $this->assertSame('secret123',         $conn2->getCredentials()['password']['value']);
    }

    public function testToApiShapeMasksAllCredentialValues(): void
    {
        $settings = MailSettings::fromArray($this->v2Array());
        $result   = MailSettingsSerializer::toApiShape($settings);

        $this->assertSame('********', $result['connections'][0]['credentials']['password']['value']);
    }

    public function testToApiShapeDoesNotMaskNonSecretFields(): void
    {
        $settings = MailSettings::fromArray($this->v2Array());
        $result   = MailSettingsSerializer::toApiShape($settings);

        $this->assertSame('from@example.com', $result['connections'][0]['fromEmail']);
        $this->assertSame('smtp.example.com', $result['connections'][0]['settings']['host']);
    }

    public function testToApiShapeWithMultipleConnections(): void
    {
        $data = $this->v2Array();
        $data['connections'][] = [
            'id'           => 'conn_def',
            'provider'     => 'other_smtp',
            'kind'         => 'smtp',
            'name'         => 'Secondary',
            'enabled'      => true,
            'fromEmail'    => 'other@example.com',
            'fromName'     => 'Other',
            'replyToEmail' => '',
            'settings'     => [
                'host'       => 'smtp2.example.com',
                'port'       => 465,
                'encryption' => 'ssl',
                'auth'       => true,
                'username'   => 'other@example.com',
                'smtp_debug' => false,
            ],
            'credentials'  => [
                'password' => ['source' => 'database', 'value' => 'pass2'],
                'api_key'  => ['source' => 'database', 'value' => 'apikey123'],
            ],
        ];

        $settings = MailSettings::fromArray($data);
        $result   = MailSettingsSerializer::toApiShape($settings);

        $this->assertSame('********', $result['connections'][0]['credentials']['password']['value']);
        $this->assertSame('********', $result['connections'][1]['credentials']['password']['value']);
        $this->assertSame('********', $result['connections'][1]['credentials']['api_key']['value']);
    }

    public function testToApiShapeConnectionsWithoutCredentialsUnaffected(): void
    {
        $data = $this->v2Array();
        $data['connections'][0]['credentials'] = [];

        $settings = MailSettings::fromArray($data);
        $result   = MailSettingsSerializer::toApiShape($settings);

        $this->assertSame([], $result['connections'][0]['credentials']);
    }

    public function testToApiShapeMasksScalarCredential(): void
    {
        $data = $this->v2Array();
        $data['connections'][0]['credentials'] = [
            'api_key' => 'raw-api-key-value',
        ];

        $settings = MailSettings::fromArray($data);
        $result   = MailSettingsSerializer::toApiShape($settings);

        $this->assertSame('********', $result['connections'][0]['credentials']['api_key']);
    }

    public function testToApiShapeMasksNestedValueAtAnyDepth(): void
    {
        $data = $this->v2Array();
        $data['connections'][0]['credentials'] = [
            'token' => [
                'meta' => [
                    'value' => 'deeply-nested-secret',
                ],
            ],
        ];

        $settings = MailSettings::fromArray($data);
        $result   = MailSettingsSerializer::toApiShape($settings);

        $this->assertSame('********', $result['connections'][0]['credentials']['token']['meta']['value']);
    }

    public function testToApiShapePreservesNonValueKeysInNestedCredential(): void
    {
        $data = $this->v2Array();
        $data['connections'][0]['credentials'] = [
            'password' => [
                'source' => 'database',
                'value'  => 'secret',
                'label'  => 'My Password',
            ],
        ];

        $settings = MailSettings::fromArray($data);
        $result   = MailSettingsSerializer::toApiShape($settings);

        $this->assertSame('database', $result['connections'][0]['credentials']['password']['source']);
        $this->assertSame('My Password', $result['connections'][0]['credentials']['password']['label']);
        $this->assertSame('********', $result['connections'][0]['credentials']['password']['value']);
    }
}
