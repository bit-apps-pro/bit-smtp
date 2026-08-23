<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Uninstall;

use BitApps\SMTP\Config;
use BitApps\SMTP\Settings\PluginSettings;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use BitSmtpLogsTableMigration;
use BitSmtpPluginOptions;
use BitSmtpSettingsSeed;
use Brain\Monkey\Functions;

// Global-namespace migration classes, included directly by MigrationHelper (not PSR-4 autoloaded).
require_once \dirname(__DIR__, 3) . '/backend/db/Migrations/BitSmtpPluginOptions.php';
require_once \dirname(__DIR__, 3) . '/backend/db/Migrations/BitSmtpLogsTableMigration.php';
require_once \dirname(__DIR__, 3) . '/backend/db/Migrations/BitSmtpSettingsSeed.php';

/**
 * Proves Task 19: BitSmtpPluginOptions::down() purges every bit_smtp_* option on uninstall —
 * critically the AES-encrypted credential blob (`options`) and the legacy plaintext credential
 * backup (`options_v1_backup`) a prior version of this migration left behind — unless the user
 * opted out via the `uninstall_purge` preference, in which case nothing is touched. Also covers the
 * sibling uninstall migrations (BitSmtpLogsTableMigration, BitSmtpSettingsSeed) sharing that same
 * gate via Settings\UninstallPurge::shouldPurge(), so logs/prefs survive alongside credentials.
 *
 * @internal
 *
 * @coversNothing
 */
final class PurgeTest extends BaseUnitTestCase
{
    public function testDownDeletesEveryOptionIncludingBothCredentialKeysWhenPurgeIsEnabled(): void
    {
        $options = [
            'bit_smtp_options'                      => 'encrypted-credential-blob',
            'bit_smtp_options_v1_backup'            => 'plaintext-legacy-credential-blob',
            PluginSettings::OPTION_NAME             => ['uninstall_purge' => true],
            'bit_smtp_logging_continuity_from'      => '2026-03-01 00:00:00',
            'bit_smtp_logging_enabled'              => 1,
            'bit_smtp_log_deleted_at'               => 1700000000,
            'bit_smtp_db_version'                   => '2.1',
            'bit_smtp_installed'                    => 1700000000,
            'bit_smtp_version'                      => '1.2.4',
            'bit_smtp_settings'                     => ['legacy' => true],
            'bit_smtp_old_version'                  => '1.2.3',
            'bit_smtp_log_retention'                => 30,
            'bit_smtp_tracking_skipped'             => true,
            'bit_smtp_test_mail_form_submitted'     => 3,
            'bit_smtp_failure_notification_active'  => 'incident-uuid',
        ];
        $this->stubOptionsApi($options);
        $clearedHooks = [];
        Functions\when('wp_clear_scheduled_hook')->alias(static function (string $hook) use (&$clearedHooks): void {
            $clearedHooks[] = $hook;
        });

        (new BitSmtpPluginOptions())->down();

        // Security-critical: both credential keys must be gone.
        self::assertArrayNotHasKey('bit_smtp_options', $options);
        self::assertArrayNotHasKey('bit_smtp_options_v1_backup', $options);
        // Every other bit_smtp_* option is gone too.
        self::assertSame([], $options);
        self::assertSame([Config::RETENTION_GC_HOOK], $clearedHooks);
    }

    public function testDownPreservesEverythingIncludingCredentialsWhenPurgeIsDisabled(): void
    {
        $options = [
            'bit_smtp_options'           => 'encrypted-credential-blob',
            'bit_smtp_options_v1_backup' => 'plaintext-legacy-credential-blob',
            PluginSettings::OPTION_NAME  => ['uninstall_purge' => false],
            'bit_smtp_db_version'        => '2.1',
        ];
        $original = $options;
        $this->stubOptionsApi($options);
        Functions\when('wp_clear_scheduled_hook')->justReturn(true);

        (new BitSmtpPluginOptions())->down();

        self::assertSame($original, $options);
    }

    public function testLogsTableMigrationDownDropsBothTablesWhenPurgeIsEnabled(): void
    {
        $options = [PluginSettings::OPTION_NAME => ['uninstall_purge' => true]];
        $this->stubOptionsApi($options);
        $wpdb = new PurgeTestDatabaseSpy();

        $this->withWpdb($wpdb, static function (): void {
            (new BitSmtpLogsTableMigration())->down();
        });

        self::assertCount(2, $wpdb->queries);
        self::assertStringContainsString('log_delivery_events', $wpdb->queries[0]);
        self::assertStringContainsString('logs', $wpdb->queries[1]);
    }

    public function testLogsTableMigrationDownPreservesBothTablesWhenPurgeIsDisabled(): void
    {
        $options = [PluginSettings::OPTION_NAME => ['uninstall_purge' => false]];
        $this->stubOptionsApi($options);
        $wpdb = new PurgeTestDatabaseSpy();

        $this->withWpdb($wpdb, static function (): void {
            (new BitSmtpLogsTableMigration())->down();
        });

        self::assertSame([], $wpdb->queries);
    }

    public function testSettingsSeedDownDeletesPreferencesBlobWhenPurgeIsEnabled(): void
    {
        $options = [PluginSettings::OPTION_NAME => ['uninstall_purge' => true, 'log_retention_days' => 45]];
        $this->stubOptionsApi($options);

        (new BitSmtpSettingsSeed())->down();

        self::assertArrayNotHasKey(PluginSettings::OPTION_NAME, $options);
    }

    public function testSettingsSeedDownPreservesPreferencesBlobWhenPurgeIsDisabled(): void
    {
        $options  = [PluginSettings::OPTION_NAME => ['uninstall_purge' => false, 'log_retention_days' => 45]];
        $original = $options;
        $this->stubOptionsApi($options);

        (new BitSmtpSettingsSeed())->down();

        self::assertSame($original, $options);
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

    /**
     * Swap $GLOBALS['wpdb'] for the given double for the callback's duration, restoring whatever was
     * there before (BitSmtpLogsTableMigration::down() runs real SQL through Schema/Blueprint, which
     * read the wpdb global directly rather than through a mockable function).
     */
    private function withWpdb(PurgeTestDatabaseSpy $wpdb, callable $callback): void
    {
        $hadWpdb         = \array_key_exists('wpdb', $GLOBALS);
        $previousWpdb    = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = $wpdb;

        try {
            $callback();
        } finally {
            if ($hadWpdb) {
                $GLOBALS['wpdb'] = $previousWpdb;
            } else {
                unset($GLOBALS['wpdb']);
            }
        }
    }
}

/**
 * Minimal wpdb double covering what Schema::drop()/Blueprint need: the collation capability probe,
 * the query itself, and the last_error/suppress_errors state Blueprint toggles around each statement.
 */
final class PurgeTestDatabaseSpy
{
    public string $last_error = '';

    public bool $suppress_errors = false;

    /**
     * @var array<int,string>
     */
    public array $queries = [];

    public function has_cap(string $cap): bool
    {
        return false;
    }

    public function query(string $sql): bool
    {
        $this->queries[] = $sql;

        return true;
    }
}
