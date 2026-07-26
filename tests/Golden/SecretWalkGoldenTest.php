<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Golden;

use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Config\MailSettingsMigrator;
use BitApps\SMTP\Mail\Config\MailSettingsSerializer;
use BitApps\SMTP\Mail\Config\MaskedSecretResolver;
use BitApps\SMTP\Tests\Golden\Support\GoldenTestCase;
use BitApps\SMTP\Tests\Golden\Support\WpStubs;

/**
 * @internal
 *
 * @coversNothing
 */
final class SecretWalkGoldenTest extends GoldenTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WpStubs::install();
    }

    public function testMaskSentinelRestoresStoredSecretGolden(): void
    {
        $stored   = MailSettings::fromArray(MailSettingsMigrator::migrate([
            'status' => '1', 'smtp_host' => 'h', 'smtp_password' => 'STORED_SECRET',
        ]));
        // Incoming payload carries the mask sentinel for the password → resolver must restore STORED_SECRET.
        $incoming                                                       = $stored->toArray();
        $incoming['connections'][0]['credentials']['password']['value'] = MailSettingsSerializer::MASK_SENTINEL;
        $resolved                                                       = MaskedSecretResolver::apply($incoming, $stored);
        $this->assertMatchesGolden($resolved, 'secret_mask_sentinel_restore');
        self::assertSame('STORED_SECRET', $resolved['connections'][0]['credentials']['password']['value']);
    }
}
