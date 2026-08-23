<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Settings;

use BitApps\SMTP\Settings\PluginSettings;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use BitSmtpSettingsSeed;
use Brain\Monkey\Functions;

// Global-namespace migration class, included directly by MigrationHelper (not PSR-4 autoloaded).
require_once \dirname(__DIR__, 3) . '/backend/db/Migrations/BitSmtpSettingsSeed.php';

/**
 * @internal
 *
 * @coversNothing
 */
final class SettingsSeedTest extends BaseUnitTestCase
{
    public function testUpSeedsPreferencesFromLegacyOptionsWhenNoPreferencesBlobExists(): void
    {
        $options = [
            'bit_smtp_log_retention'   => 45,
            'bit_smtp_logging_enabled' => 0,
        ];
        $this->stubOptionsApi($options);

        (new BitSmtpSettingsSeed())->up();

        $preferences = $options[PluginSettings::OPTION_NAME];
        self::assertSame(45, $preferences['log_retention_days']);
        self::assertSame(false, $preferences['logging_enabled']);
    }

    public function testUpDoesNotOverwriteAnExistingPreferencesBlob(): void
    {
        $options = [
            PluginSettings::OPTION_NAME => ['log_retention_days' => 7],
            'bit_smtp_log_retention'    => 45,
        ];
        $this->stubOptionsApi($options);

        (new BitSmtpSettingsSeed())->up();

        self::assertSame(7, $options[PluginSettings::OPTION_NAME]['log_retention_days']);
    }

    public function testDownDeletesThePreferencesOption(): void
    {
        $options = [
            PluginSettings::OPTION_NAME => ['logging_enabled' => true],
        ];
        $this->stubOptionsApi($options);

        (new BitSmtpSettingsSeed())->down();

        self::assertArrayNotHasKey(PluginSettings::OPTION_NAME, $options);
    }

    /**
     * Stub get_option/update_option/delete_option against an in-memory map keyed by option name.
     *
     * @param array<string,mixed> $options
     */
    private function stubOptionsApi(array &$options): void
    {
        Functions\when('get_option')->alias(static function (string $key, $default = false) use (&$options) {
            return $options[$key] ?? $default;
        });
        Functions\when('update_option')->alias(static function (string $key, $value) use (&$options): bool {
            $options[$key] = $value;

            return true;
        });
        Functions\when('delete_option')->alias(static function (string $key) use (&$options): bool {
            unset($options[$key]);

            return true;
        });
    }
}
