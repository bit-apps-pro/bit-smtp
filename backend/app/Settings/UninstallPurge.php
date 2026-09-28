<?php

namespace BitApps\SMTP\Settings;

use Throwable;

\defined('ABSPATH') || exit();

/**
 * Shared `uninstall_purge` preference reader for plugin's uninstall migrations (not autoloaded, can't share private method).
 */
final class UninstallPurge
{
    /**
     * Resolve uninstall-purge preference (defaults to true: purge, schema default, security-safe).
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
