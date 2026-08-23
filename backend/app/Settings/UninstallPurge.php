<?php

namespace BitApps\SMTP\Settings;

use Throwable;

\defined('ABSPATH') || exit();

/**
 * Shared `uninstall_purge` preference reader for the plugin's uninstall (down()) migrations:
 * BitSmtpPluginOptions, BitSmtpLogsTableMigration, and BitSmtpSettingsSeed each gate their own
 * teardown on this, since the migrations directory isn't autoloaded and can't share a private
 * method across sibling files.
 */
final class UninstallPurge
{
    /**
     * Resolve the uninstall-purge preference, defaulting to true (purge) — the schema default and
     * the security-safe choice — if the preferences blob can't be read.
     */
    public static function shouldPurge(): bool
    {
        try {
            return (bool) PluginSettings::make()->get('uninstall_purge', true);
        } catch (Throwable $e) {
            return true;
        }
    }
}
