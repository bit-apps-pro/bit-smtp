<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Config;

use BitApps\SMTP\Mail\Config\MailSettingsMigrator;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;

/**
 * @internal
 *
 * @coversNothing
 */
class MailSettingsMigratorTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_generate_uuid4')->justReturn('test-uuid-1234');
    }

    public function testAlreadyV2PassesThroughUnchanged(): void
    {
        $v2 = [
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => 'conn_abc',
            'fallback_connection_ids' => [],
            'connections'             => [],
            'features'                => [],
        ];

        $this->assertSame($v2, MailSettingsMigrator::migrate($v2));
    }

    public function testEmptyInputReturnsSkeleton(): void
    {
        $result = MailSettingsMigrator::migrate([]);

        $this->assertSame(2, $result['schema_version']);
        $this->assertFalse($result['enabled']);
        $this->assertSame('', $result['default_connection_id']);
        $this->assertSame([], $result['fallback_connection_ids']);
        $this->assertSame([], $result['connections']);
        $this->assertArrayHasKey('features', $result);
    }

    public function testLegacyMigrationIsIdempotentAcrossLoads(): void
    {
        // Migrate-on-load runs every request until the first v2 save; a non-stable id
        // would mismatch the frontend load vs the save-time re-migration → duplicate connection.
        $first  = MailSettingsMigrator::migrate($this->legacyInput());
        $second = MailSettingsMigrator::migrate($this->legacyInput());

        $this->assertSame($first['connections'][0]['id'], $second['connections'][0]['id']);
        $this->assertSame(MailSettingsMigrator::MIGRATED_CONNECTION_ID, $first['connections'][0]['id']);
    }

    public function testLegacyMigrationMapsAllFields(): void
    {
        $result = MailSettingsMigrator::migrate($this->legacyInput());
        $conn   = $result['connections'][0];

        $this->assertSame(2, $result['schema_version']);
        $this->assertTrue($result['enabled']);
        $this->assertSame(MailSettingsMigrator::MIGRATED_CONNECTION_ID, $result['default_connection_id']);
        $this->assertSame([], $result['fallback_connection_ids']);
        $this->assertCount(1, $result['connections']);
        $this->assertSame(MailSettingsMigrator::MIGRATED_CONNECTION_ID, $conn['id']);
        $this->assertSame('other_smtp', $conn['provider']);
        $this->assertSame('smtp', $conn['kind']);
        $this->assertSame('Primary SMTP', $conn['name']);
        $this->assertSame('test@example.com', $conn['fromEmail']);
        $this->assertSame('Test User', $conn['fromName']);
        $this->assertSame('reply@example.com', $conn['replyToEmail']);
        $this->assertSame('smtp.example.com', $conn['settings']['host']);
        $this->assertSame(587, $conn['settings']['port']);
        $this->assertSame('tls', $conn['settings']['encryption']);
        $this->assertTrue($conn['settings']['auth']);
        $this->assertSame('user@example.com', $conn['settings']['username']);
        $this->assertSame(['source' => 'database', 'value' => 'secret123'], $conn['credentials']['password']);
        $this->assertFalse($conn['settings']['smtp_debug']);
    }

    public function testPortCoercedToInt(): void
    {
        $input          = $this->legacyInput();
        $input['port']  = '465';
        $result         = MailSettingsMigrator::migrate($input);

        $this->assertSame(465, $result['connections'][0]['settings']['port']);
    }

    public function testEncryptionAbsentDefaultsToNone(): void
    {
        $input = $this->legacyInput();
        unset($input['encryption']);
        $result = MailSettingsMigrator::migrate($input);

        $this->assertSame('none', $result['connections'][0]['settings']['encryption']);
    }

    public function testEncryptionEmptyStringDefaultsToNone(): void
    {
        $input               = $this->legacyInput();
        $input['encryption'] = '';
        $result              = MailSettingsMigrator::migrate($input);

        $this->assertSame('none', $result['connections'][0]['settings']['encryption']);
    }

    public function testEncryptionPreservedWhenPresent(): void
    {
        $input               = $this->legacyInput();
        $input['encryption'] = 'ssl';
        $result              = MailSettingsMigrator::migrate($input);

        $this->assertSame('ssl', $result['connections'][0]['settings']['encryption']);
    }

    public function testSmtpDebugMappedToConnectionSettings(): void
    {
        $input               = $this->legacyInput();
        $input['smtp_debug'] = '1';
        $result              = MailSettingsMigrator::migrate($input);

        $this->assertTrue($result['connections'][0]['settings']['smtp_debug']);
    }

    public function testSmtpDebugFalseByDefault(): void
    {
        $input = $this->legacyInput();
        unset($input['smtp_debug']);
        $result = MailSettingsMigrator::migrate($input);

        $this->assertFalse($result['connections'][0]['settings']['smtp_debug']);
    }

    public function testTypoKeyFormNameFixed(): void
    {
        $input  = ['form_name' => 'Typo Name', 'smtp_host' => 'h', 'port' => 25];
        $result = MailSettingsMigrator::migrate($input);

        $this->assertSame('Typo Name', $result['connections'][0]['fromName']);
    }

    public function testCanonicalFromNameWinsOverTypoWhenBothPresent(): void
    {
        $input = [
            'from_name' => 'Canonical Name',
            'form_name' => 'Typo Name',
            'smtp_host' => 'h',
            'port'      => 25,
        ];
        $result = MailSettingsMigrator::migrate($input);

        $this->assertSame('Canonical Name', $result['connections'][0]['fromName']);
    }

    public function testTypoKeyFormEmailAddressFixed(): void
    {
        $input  = ['form_email_address' => 'typo@example.com', 'smtp_host' => 'h', 'port' => 25];
        $result = MailSettingsMigrator::migrate($input);

        $this->assertSame('typo@example.com', $result['connections'][0]['fromEmail']);
    }

    public function testReEmailAddressMapsToReplyToEmail(): void
    {
        $input  = ['re_email_address' => 'reply@example.com', 'smtp_host' => 'h', 'port' => 25];
        $result = MailSettingsMigrator::migrate($input);

        $this->assertSame('reply@example.com', $result['connections'][0]['replyToEmail']);
    }

    public function testBooleanStatusFalse(): void
    {
        $input           = $this->legacyInput();
        $input['status'] = 0;
        $result          = MailSettingsMigrator::migrate($input);

        $this->assertFalse($result['enabled']);
        $this->assertFalse($result['connections'][0]['enabled']);
    }

    public function testBooleanStatusTrueFromString(): void
    {
        $input           = $this->legacyInput();
        $input['status'] = 'true';
        $result          = MailSettingsMigrator::migrate($input);

        $this->assertTrue($result['enabled']);
    }

    public function testPasswordInCredentials(): void
    {
        $input                  = $this->legacyInput();
        $input['smtp_password'] = 'mypass';
        $result                 = MailSettingsMigrator::migrate($input);

        $this->assertSame(
            ['source' => 'database', 'value' => 'mypass'],
            $result['connections'][0]['credentials']['password']
        );
    }

    public function testNonDefaultPortPreserved(): void
    {
        $input         = $this->legacyInput();
        $input['port'] = 2525;
        $result        = MailSettingsMigrator::migrate($input);

        $this->assertSame(2525, $result['connections'][0]['settings']['port']);
    }

    public function testConnectionIdHasConnPrefix(): void
    {
        $result = MailSettingsMigrator::migrate($this->legacyInput());

        $this->assertStringStartsWith('conn_', $result['connections'][0]['id']);
    }

    private function legacyInput(): array
    {
        return [
            'status'             => '1',
            'from_email_address' => 'test@example.com',
            'from_name'          => 'Test User',
            're_email_address'   => 'reply@example.com',
            'smtp_host'          => 'smtp.example.com',
            'port'               => '587',
            'encryption'         => 'tls',
            'smtp_auth'          => '1',
            'smtp_user_name'     => 'user@example.com',
            'smtp_password'      => 'secret123',
            'smtp_debug'         => '0',
        ];
    }
}
