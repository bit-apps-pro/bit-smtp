<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Golden;

use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Config\MailSettingsMigrator;
use BitApps\SMTP\Mail\Config\MailSettingsSerializer;
use BitApps\SMTP\Tests\Golden\Support\GoldenTestCase;
use BitApps\SMTP\Tests\Golden\Support\WpStubs;

/**
 * @internal
 *
 * @coversNothing
 */
final class PersistenceGoldenTest extends GoldenTestCase
{
    private const LEGACY_V1 = [
        'status'           => '1', 'from_email_address' => 'a@example.test', 'from_name' => 'Sender',
        're_email_address' => 'reply@example.test', 'smtp_host' => 'smtp.example.test',
        'port'             => 587, 'encryption' => 'tls', 'smtp_auth' => '1',
        'smtp_user_name'   => 'user', 'smtp_debug' => '', 'smtp_password' => 'secret',
    ];

    private const LEGACY_V1_TYPO = [
        'status'    => 'true', 'form_email_address' => 'typo@example.test', 'form_name' => 'TypoName',
        'smtp_host' => 'smtp.example.test', 'encryption' => 'STARTTLS', // arbitrary legacy value must survive
    ];

    protected function setUp(): void
    {
        parent::setUp();
        WpStubs::install();
    }

    public function testMigrateEmptyGolden(): void
    {
        $this->assertMatchesGolden(MailSettingsMigrator::migrate([]), 'persistence_migrate_empty');
    }

    public function testMigrateLegacyGolden(): void
    {
        $this->assertMatchesGolden(MailSettingsMigrator::migrate(self::LEGACY_V1), 'persistence_migrate_v1');
    }

    public function testMigrateLegacyTypoAndArbitraryEncryptionGolden(): void
    {
        $this->assertMatchesGolden(MailSettingsMigrator::migrate(self::LEGACY_V1_TYPO), 'persistence_migrate_v1_typo');
    }

    public function testV2RoundTripPreservesUnknownKeys(): void
    {
        $v2 = MailSettingsMigrator::migrate(self::LEGACY_V1);
        // Inject provider-specific + future keys that MUST survive fromArray->toArray.
        $v2['connections'][0]['settings']['webhook_signature_enabled'] = true;
        $v2['connections'][0]['settings']['some_future_provider_key']  = 'keepme';
        $out                                                           = MailSettings::fromArray($v2)->toArray();
        $this->assertMatchesGolden($out, 'persistence_v2_roundtrip');
        // Explicit losslessness invariant (documents the #1 break risk):
        self::assertSame(true, $out['connections'][0]['settings']['webhook_signature_enabled'] ?? null);
        self::assertSame('keepme', $out['connections'][0]['settings']['some_future_provider_key'] ?? null);
    }

    public function testMaskedApiShapeGolden(): void
    {
        $settings = MailSettings::fromArray(MailSettingsMigrator::migrate(self::LEGACY_V1));
        $this->assertMatchesGolden(MailSettingsSerializer::toApiShape($settings), 'persistence_api_masked');
    }

    public function testLegacyFlatRoundTripGolden(): void
    {
        $settings = MailSettings::fromArray(MailSettingsMigrator::migrate(self::LEGACY_V1));
        $flat     = MailSettingsSerializer::toLegacyShape($settings);
        $this->assertMatchesGolden($flat, 'persistence_legacy_flat');
    }
}
